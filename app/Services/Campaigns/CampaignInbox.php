<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** IMAP: registra apenas cabeçalhos associados a nossos Message-ID enviados. */
final class CampaignInbox
{
    /**
     * @param  array{uidvalidity:string,last_uid:int,initialized:bool,messages:list<array<string,mixed>>}  $batch
     */
    public function import(string $mailbox, array $batch): int
    {
        $validity = (string) $batch['uidvalidity'];
        $lastUid = max(0, (int) $batch['last_uid']);
        if ($validity === '' || ! ctype_digit($validity) || strlen($validity) > 40) {
            return 0;
        }

        return DB::transaction(function () use ($mailbox, $batch, $validity, $lastUid): int {
            $cursor = EmailCampaignInboxCursor::query()->firstOrNew(['mailbox' => $mailbox]);
            if ((string) ($cursor->uid_validity ?? '') !== $validity) {
                $cursor->last_uid = 0;
            }
            $cursor->uid_validity = $validity;
            $matched = 0;
            foreach ($batch['messages'] as $message) {
                $uid = (int) ($message['uid'] ?? 0);
                // EC_CAMPAIGNS_V7_DSN: antes dos filtros de auto-resposta.
                // DSNs chegam normalmente de MAILER-DAEMON e não do cliente.
                if ($uid > 0 && is_array($message['bounce'] ?? null)) {
                    app(CampaignBounceProcessor::class)->record(
                        $mailbox, $validity, $uid, $message['bounce'],
                        is_string($message['received_at'] ?? null) ? $message['received_at'] : null,
                    );

                    // Um DSN nunca deve contar como resposta de um cliente.
                    continue;
                }
                $from = CampaignAudience::normalizedEmail((string) ($message['from_email'] ?? ''));
                if ($uid < 1 || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                $auto = mb_strtolower(trim((string) ($message['auto_submitted'] ?? '')));
                if ($auto !== '' && $auto !== 'no') {
                    continue;
                }
                // Sem In-Reply-To/References NÃO associamos mensagens por aproximação.
                $references = (string) ($message['in_reply_to'] ?? '').' '.(string) ($message['references'] ?? '');
                preg_match_all('/<([A-Za-z0-9.\-]+@[A-Za-z0-9.\-]+)>/', $references, $matches);
                $messageIds = array_values(array_unique($matches[1]));
                if ($messageIds === []) {
                    continue;
                }
                // EC_TRACKED_IMAP_TEST_V1: testes isolados das métricas comerciais.
                $tracked = EmailCampaignTestMessage::query()
                    ->where('test_to', $from)
                    ->whereIn('message_id', $messageIds)
                    ->whereNotNull('attempted_at')
                    ->whereNull('replied_at')
                    ->first();
                if ($tracked !== null) {
                    $tracked->update([
                        'replied_at' => $this->dateOrNow($message['received_at'] ?? null),
                        'reply_subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 255),
                        'reply_uid_validity' => $validity,
                        'reply_imap_uid' => $uid,
                    ]);
                    $matched++;

                    continue;
                }
                $recipient = EmailCampaignRecipient::query()
                    ->where('normalized_email', $from)
                    ->whereIn('message_id', $messageIds)
                    ->whereNotNull('attempted_at')->first();
                if ($recipient === null) {
                    continue;
                }
                $reply = EmailCampaignReply::query()->firstOrCreate(
                    ['mailbox' => $mailbox, 'uid_validity' => $validity, 'imap_uid' => $uid],
                    [
                        'email_campaign_id' => $recipient->email_campaign_id,
                        'email_campaign_recipient_id' => $recipient->id,
                        'from_email' => $from,
                        'subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 255),
                        // EC_CAMPAIGN_INTELLIGENCE_V9: correlaciona resposta humana ao Message-ID recebido.
                        'incoming_message_id' => mb_substr((string) ($message['message_id'] ?? ''), 0, 255),
                        'received_at' => $this->dateOrNow($message['received_at'] ?? null),
                    ],
                );
                if ($reply->wasRecentlyCreated) {
                    $matched++;
                    if ($recipient->replied_at === null) {
                        $recipient->update(['replied_at' => $reply->received_at ?? now()]);
                    }
                }
            }
            $cursor->last_uid = max((int) ($cursor->last_uid ?? 0), $lastUid);
            $cursor->last_synced_at = now('UTC');
            $cursor->save();

            return $matched;
        });
    }

    private function dateOrNow(mixed $value): CarbonImmutable
    {
        try {
            return is_string($value) && $value !== ''
                ? CarbonImmutable::parse($value)
                : CarbonImmutable::now();
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }
}
