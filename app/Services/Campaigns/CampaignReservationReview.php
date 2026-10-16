<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recuperação MANUAL exclusivamente de reservas não iniciadas.
 * Não recoloca mensagens incertas/sending/sent na fila e nunca dispara jobs.
 */
final class CampaignReservationReview
{
    /**
     * @return array{recipient_id:int,campaign_id:int,campaign_status:string,recipient_status:string,attempts:int,reserved_at:string|null,eligible:bool}|null
     */
    public function inspect(int $recipientId): ?array
    {
        $recipient = EmailCampaignRecipient::query()->find($recipientId);
        if ($recipient === null) {
            return null;
        }

        $campaign = EmailCampaign::query()->find((int) $recipient->email_campaign_id);

        return [
            'recipient_id' => (int) $recipient->id,
            'campaign_id' => (int) $recipient->email_campaign_id,
            'campaign_status' => (string) ($campaign->status ?? 'missing'),
            'recipient_status' => (string) $recipient->status,
            'attempts' => (int) $recipient->attempts,
            'reserved_at' => $recipient->reserved_at === null ? null : (string) $recipient->reserved_at,
            'eligible' => $this->eligible($recipient, $campaign),
        ];
    }

    public function release(int $recipientId, string $reason): bool
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 12 || mb_strlen($reason) > 240) {
            throw ValidationException::withMessages([
                'reason' => 'Informe um motivo de auditoria com 12 a 240 caracteres.',
            ]);
        }

        return DB::transaction(function () use ($recipientId, $reason): bool {
            $candidate = EmailCampaignRecipient::query()->find($recipientId);
            if ($candidate === null) {
                return false;
            }

            // Mesma ordem de locks do job: campanha e depois destinatário.
            $campaign = EmailCampaign::query()
                ->whereKey((int) $candidate->email_campaign_id)
                ->lockForUpdate()
                ->first();
            $recipient = EmailCampaignRecipient::query()
                ->whereKey($recipientId)
                ->lockForUpdate()
                ->first();

            if ($recipient === null || ! $this->eligible($recipient, $campaign)) {
                return false;
            }

            $fingerprint = substr(hash('sha256', (string) $recipient->reservation_token), 0, 16);
            $recipient->update([
                'status' => 'pending',
                'reserved_at' => null,
                'reservation_token' => null,
            ]);

            DB::table('email_campaign_reservation_reviews')->insert([
                'recipient_id' => $recipientId,
                'campaign_id' => (int) $recipient->email_campaign_id,
                'action' => 'release_unstarted',
                'token_fingerprint' => $fingerprint,
                'reason' => $reason,
                'reviewed_at' => now('UTC'),
            ]);

            return true;
        }, 3);
    }

    private function eligible(EmailCampaignRecipient $recipient, ?EmailCampaign $campaign): bool
    {
        if ($campaign === null || ! in_array($campaign->status, ['running', 'paused'], true)) {
            return false;
        }

        // Verificar os campos no banco evita confundir datas locais com UTC e
        // só considera reservas que nunca iniciaram uma tentativa SMTP.
        return EmailCampaignRecipient::query()
            ->whereKey((int) $recipient->id)
            ->where('status', 'reserved')
            ->where('attempts', 0)
            ->whereNull('attempted_at')
            ->whereNull('message_id')
            ->whereNotNull('reservation_token')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', now('UTC')->subMinutes(30))
            ->exists();
    }
}
