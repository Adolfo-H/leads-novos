<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;
use Carbon\CarbonImmutable;

/**
 * Monitor somente de leitura da fila comercial.
 *
 * Nunca chama Mail, Queue ou altera o banco. O objetivo e localizar
 * mensagens reservadas ou iniciadas que nao avancaram de estado.
 */
final class CampaignQueueHealth
{
    /**
     * @return array{
     *   safe_mode: bool,
     *   queue: string,
     *   reservation_timeout_minutes: int,
     *   sending_timeout_minutes: int,
     *   pending: int,
     *   reserved: int,
     *   sending: int,
     *   sent: int,
     *   uncertain: int,
     *   suppressed: int,
     *   canceled: int,
     *   stale_reserved: int,
     *   stale_sending: int
     * }
     */
    public function snapshot(): array
    {
        $reserveMinutes = max(10, min(1440, (int) config('campaign_mail.reserved_stale_minutes', 30)));
        $sendingMinutes = max(10, min(1440, (int) config('campaign_mail.sending_stale_minutes', 30)));

        // O Postgres do Prospector trabalha com timestamps em UTC.
        // Compare instantes UTC, sem reinterpretar Sao Paulo como UTC.
        $reservedBefore = CarbonImmutable::now('UTC')
            ->subMinutes($reserveMinutes)
            ->format('Y-m-d H:i:s');
        $sendingBefore = CarbonImmutable::now('UTC')
            ->subMinutes($sendingMinutes)
            ->format('Y-m-d H:i:s');

        return [
            'safe_mode' => ! (bool) config('campaign_mail.live_enabled', false),
            'queue' => (string) config('campaign_mail.queue', 'campaigns'),
            'reservation_timeout_minutes' => $reserveMinutes,
            'sending_timeout_minutes' => $sendingMinutes,
            'pending' => $this->countStatus('pending'),
            'reserved' => $this->countStatus('reserved'),
            'sending' => $this->countStatus('sending'),
            'sent' => $this->countStatus('sent'),
            'uncertain' => $this->countStatus('uncertain'),
            'suppressed' => $this->countStatus('suppressed'),
            'canceled' => $this->countStatus('canceled'),
            'stale_reserved' => EmailCampaignRecipient::query()
                ->where('status', 'reserved')
                ->whereRaw('(reserved_at IS NULL OR reserved_at < ?)', [$reservedBefore])
                ->count(),
            'stale_sending' => EmailCampaignRecipient::query()
                ->where('status', 'sending')
                ->whereRaw('(attempted_at IS NULL OR attempted_at < ?)', [$sendingBefore])
                ->count(),
        ];
    }

    private function countStatus(string $status): int
    {
        return EmailCampaignRecipient::query()->where('status', $status)->count();
    }
}
