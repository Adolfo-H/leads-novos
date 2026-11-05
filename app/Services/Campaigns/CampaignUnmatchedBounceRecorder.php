<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra apenas DSNs RFC estruturados que não puderam ser vinculados.
 * Nunca cria vínculos, nunca altera destinatários e nunca suprime e-mails.
 * Não persiste Message-ID, endereço completo nem corpo do e-mail.
 */
final class CampaignUnmatchedBounceRecorder
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

        // As mesmas provas estruturais exigidas pelo processador de DSNs vinculados.
        if (preg_match('/^[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+$/D', $messageId) !== 1
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[45]\.\d{1,3}\.\d{1,3}$/D', $status) !== 1
            || ! in_array($action, ['failed', 'delayed'], true)) {
            return false;
        }

        $expected = $action === 'failed' && str_starts_with($status, '5.')
            ? (in_array($status, ['5.1.1', '5.1.2', '5.1.10'], true) ? 'invalid' : 'permanent_other')
            : (str_starts_with($status, '4.') ? 'temporary' : null);
        if ($expected === null || $expected !== $classification) {
            return false;
        }

        if (DB::table('email_campaign_bounces')->where('mailbox', $mailbox)
            ->where('uid_validity', $validity)->where('imap_uid', $uid)->exists()) {
            return false;
        }

        $date = now('UTC');
        if ($receivedAt !== null && $receivedAt !== '') {
            try {
                $date = Carbon::parse($receivedAt);
            } catch (\Throwable) {
                // Data inválida não torna uma devolução vinculada ou comprovada.
            }
        }

        [$local, $domain] = explode('@', $recipient, 2);
        $mask = mb_substr($local, 0, 1).'***@'.$domain;

        // Dedupe pelo UIDVALIDITY+UID da caixa, não pelo destinatário.
        return DB::table('email_campaign_unmatched_bounces')->insertOrIgnore([
            'mailbox' => $mailbox,
            'uid_validity' => $validity,
            'imap_uid' => $uid,
            'recipient_mask' => mb_substr($mask, 0, 255),
            'status_code' => $status,
            'action' => $action,
            'classification' => $classification,
            'received_at' => $date,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]) === 1;
    }
}
