<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignTestMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Registra somente DSNs estruturados correlacionados por Message-ID + destinatário.
 * Jamais deduz bounces pelo assunto, remetente, trechos de texto ou domínio.
 */
final class CampaignBounceProcessor
{
    /** @param array<string, mixed> $dsn */
    public function record(string $mailbox, string $validity, int $uid, array $dsn, ?string $receivedAt = null): bool
    {
        if ($uid < 1 || ! ctype_digit($validity) || strlen($validity) > 40) {
            return false;
        }

        $messageId = (string) ($dsn['original_message_id'] ?? '');
        $recipient = CampaignAudience::normalizedEmail((string) ($dsn['final_recipient'] ?? ''));
        $status = (string) ($dsn['status_code'] ?? '');
        $action = (string) ($dsn['action'] ?? '');
        $classification = (string) ($dsn['classification'] ?? '');
        if (preg_match('/^[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+$/D', $messageId) !== 1
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[45]\.\d{1,3}\.\d{1,3}$/D', $status) !== 1
            || ! in_array($action, ['failed', 'delayed'], true)) {
            return false;
        }

        // A classificação recebida do leitor Python NUNCA é autoridade para bloqueio.
        $actual = $action === 'failed' && str_starts_with($status, '5.')
            ? (in_array($status, ['5.1.1', '5.1.2', '5.1.10'], true) ? 'invalid' : 'permanent_other')
            : (str_starts_with($status, '4.') ? 'temporary' : null);
        if ($actual === null || $actual !== $classification) {
            return false;
        }

        $commercial = EmailCampaignRecipient::query()
            ->where('message_id', $messageId)
            ->where('normalized_email', $recipient)
            ->whereNotNull('attempted_at')
            ->first();
        $pilot = null;
        if ($commercial === null) {
            $pilot = EmailCampaignTestMessage::query()
                ->where('message_id', $messageId)
                ->where('test_to', $recipient)
                ->whereNotNull('attempted_at')
                ->first();
        }
        if ($commercial === null && $pilot === null) {
            return false;
        }

        $when = CarbonImmutable::now('UTC');
        if (is_string($receivedAt) && $receivedAt !== '') {
            try {
                $when = CarbonImmutable::parse($receivedAt);
            } catch (\Throwable) {
                // Não aceitar datas malformadas como prova de qualquer outra ação.
            }
        }

        $campaignId = $commercial->email_campaign_id ?? $pilot?->email_campaign_id;
        if ($campaignId === null) {
            return false;
        }

        return DB::transaction(function () use ($mailbox, $validity, $uid, $recipient, $status, $action, $actual, $dsn, $commercial, $pilot, $when, $campaignId): bool {
            $event = EmailCampaignBounce::query()->firstOrCreate(
                ['mailbox' => $mailbox, 'uid_validity' => $validity, 'imap_uid' => $uid],
                [
                    'email_campaign_id' => $campaignId,
                    'email_campaign_recipient_id' => $commercial?->id,
                    'email_campaign_test_message_id' => $pilot?->id,
                    'final_recipient' => $recipient,
                    'status_code' => $status,
                    'action' => $action,
                    'classification' => $actual,
                    'diagnostic' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) ($dsn['diagnostic'] ?? '')) ?? '', 0, 200),
                    'received_at' => $when,
                ],
            );

            if (! $event->wasRecentlyCreated) {
                return false;
            }

            // Pilotos nunca suprimem endereços comerciais, mesmo em caso de erro real.
            // 5.7.x / policy failures e 4.x: somente auditoria, sem supressão automática.
            if ($commercial !== null && $actual === 'invalid') {
                app(CampaignManager::class)->suppress($recipient, 'hard_bounce');
            }

            return true;
        });
    }
}
