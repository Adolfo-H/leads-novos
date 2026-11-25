<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $noticeTableReady = Schema::hasTable('email_campaign_inbound_notices');
            // EC_REPLY_MAILBOX_V2_3: compatible during the additive migration.
            $testMailboxReady = Schema::hasColumn('email_campaign_test_messages', 'reply_mailbox');
            foreach ($batch['messages'] as $message) {
                $uid = (int) ($message['uid'] ?? 0);
                // EC_CAMPAIGNS_V7_DSN: antes dos filtros de auto-resposta.
                // DSNs chegam normalmente de MAILER-DAEMON e não do cliente.
                if ($uid > 0 && is_array($message['bounce'] ?? null)) {
                    // EC_CAMPAIGNS_FOLLOWUP_V294: vinculado primeiro; só o não correlacionado vai para revisão.
                    $linked = app(CampaignBounceProcessor::class)->record(
                        $mailbox, $validity, $uid, $message['bounce'],
                        is_string($message['received_at'] ?? null) ? $message['received_at'] : null,
                    );
                    $alreadyLinked = $linked || EmailCampaignBounce::query()
                        ->where('mailbox', $mailbox)->where('uid_validity', $validity)
                        ->where('imap_uid', $uid)->exists();
                    if ($alreadyLinked) {
                        DB::table('email_campaign_unmatched_bounces')
                            ->where('mailbox', $mailbox)->where('uid_validity', $validity)
                            ->where('imap_uid', $uid)->delete();
                    } else {
                        app(CampaignUnmatchedBounceRecorder::class)->record(
                            $mailbox, $validity, $uid, $message['bounce'],
                            is_string($message['received_at'] ?? null) ? $message['received_at'] : null,
                        );
                    }

                    // Um DSN nunca deve contar como resposta de um cliente.
                    continue;
                }
                $from = CampaignAudience::normalizedEmail((string) ($message['from_email'] ?? ''));
                if ($uid < 1 || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                // Registra retornos automáticos e não confirmados em categoria separada.
                // Nunca os transforma em respostas humanas nem altera replied_at.
                if ($noticeTableReady && app(CampaignInboundNoticeRecorder::class)
                    ->record($mailbox, $validity, $uid, $message)) {
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
                // Reprocessing only enriches an existing test when its identity AND
                // previously recorded UID/UIDVALIDITY match. No second reply count.
                if ($testMailboxReady) {
                    $oldTest = EmailCampaignTestMessage::query()->where('test_to', $from)
                        ->whereIn('message_id', $messageIds)->whereNotNull('replied_at')
                        ->whereNull('reply_mailbox')->where('reply_uid_validity', $validity)
                        ->where('reply_imap_uid', $uid)->first();
                    if ($oldTest !== null) {
                        $expectedBox = CampaignReplyMailbox::dedicatedTestAddress((string) $oldTest->message_id, (int) $oldTest->email_campaign_id);
                        if ($expectedBox === null || $mailbox === $expectedBox) {
                            $oldTest->update(['reply_mailbox' => $mailbox]);
                        }
                    }
                }
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
                    if ($testMailboxReady) {
                        $tracked->update(['reply_mailbox' => $mailbox]);
                    }
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

            // EC_DUAL_IMAP_V2_1: preserve the UTC offset for dedicated cursors.
            // Query builder bypasses Eloquent's offset-stripping date format.
            if (in_array($mailbox, ['publicidade@exportcontrol.com.br', 'propaganda@exportcontrol.com.br'], true)) {
                DB::table('email_campaign_inbox_cursors')->where('mailbox', $mailbox)->update([
                    'last_synced_at' => now('UTC')->toIso8601String(),
                ]);
            }

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
