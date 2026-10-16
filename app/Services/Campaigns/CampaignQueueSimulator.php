<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Carbon\CarbonImmutable;

/**
 * Projeção virtual: NUNCA envia e-mail, agenda job, reserva destinatário nem salva no BD.
 *
 * O relógio inicia no minuto atual, com fila virtual vazia e sem concorrência.
 * Reproduz os limites do CampaignDispatcher (janelas móveis de 60 min e
 * 24 horas, e máximo por execução a cada minuto). Não zera à meia-noite.
 */
final class CampaignQueueSimulator
{
    /**
     * @return array{
     *   requested:int,
     *   safe_mode:bool,
     *   hourly_limit:int,
     *   daily_limit:int,
     *   per_tick_limit:int,
     *   first_hour:int,
     *   first_calendar_day:int,
     *   first_24_hours:int,
     *   within_seven_days:int,
     *   remaining:int,
     *   completed:bool,
     *   completion_minutes:int|null,
     *   forecast_starts_at:string,
     *   actual:array{pending:int,reserved:int,sending:int,sent:int,uncertain:int,suppressed:int,canceled:int,stale_reserved:int,stale_sending:int,drafts:int,running:int,paused:int}
     * }
     */
    public function report(int $requested = 50): array
    {
        // CAMPANHAS_V6_SIM_POLICY: mesmo limite e janela usados pelo despachante.
        $policyService = app(CampaignSendPolicy::class);
        $policy = $policyService->effective();
        $hour = max(1, min(100, $policy['hour']));
        $day = max(1, min(500, $policy['day']));
        $tick = max(1, min(5, $policy['tick']));
        $requested = max(1, min(500, $requested));

        $start = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfMinute();
        $firstDay = $start->toDateString();
        // Minutos virtuais de cada reserva: permitem duas janelas móveis simultâneas.
        $window = [];
        $hourHead = 0;
        $dayHead = 0;
        $remaining = $requested;
        $firstHour = 0;
        $firstCalendarDay = 0;
        $first24 = 0;
        $completionMinutes = null;

        // Máximo 7 dias: nunca cria jobs nem acessa o SMTP.
        for ($minute = 0; $minute < 10080 && $remaining > 0; $minute++) {
            $instant = $start->addMinutes($minute);
            if (! $policyService->windowOpen($policy, $instant)) {
                continue;
            }
            $calendarDay = $instant->toDateString();
            // >= cutoff no despachante inclui a reserva exatamente no limite.
            while ($hourHead < count($window) && $window[$hourHead] < $minute - 60) {
                $hourHead++;
            }
            while ($dayHead < count($window) && $window[$dayHead] < $minute - 1440) {
                $dayHead++;
            }
            $recentHour = count($window) - $hourHead;
            $recentDay = count($window) - $dayHead;
            $canReserve = max(0, min($remaining, $tick, $hour - $recentHour, $day - $recentDay));
            if ($canReserve === 0) {
                continue;
            }
            for ($i = 0; $i < $canReserve; $i++) {
                $window[] = $minute;
            }
            // Janela diária de 24 horas já considerada em $recentDay.
            $remaining -= $canReserve;
            if ($minute < 60) {
                $firstHour += $canReserve;
            }
            if ($calendarDay === $firstDay) {
                $firstCalendarDay += $canReserve;
            }
            if ($minute < 1440) {
                $first24 += $canReserve;
            }
            if ($remaining === 0) {
                $completionMinutes = $minute;
            }
        }

        $statuses = EmailCampaignRecipient::query()
            ->select('status')->selectRaw('COUNT(*) AS total')
            ->groupBy('status')->pluck('total', 'status')->all();
        $count = static fn (string $status): int => (int) ($statuses[$status] ?? 0);

        // Timestamps da base PostgreSQL estão em UTC.
        $cutoff = CarbonImmutable::now('UTC')->subMinutes(30)->format('Y-m-d H:i:s');

        $staleReserved = EmailCampaignRecipient::query()->where('status', 'reserved')
            ->where(static function ($q) use ($cutoff): void {
                $q->whereNull('reserved_at')->orWhere('reserved_at', '<', $cutoff);
            })->count();
        $staleSending = EmailCampaignRecipient::query()->where('status', 'sending')
            ->where(static function ($q) use ($cutoff): void {
                $q->whereNull('attempted_at')->orWhere('attempted_at', '<', $cutoff);
            })->count();

        return [
            'requested' => $requested,
            'safe_mode' => ! (bool) config('campaign_mail.live_enabled', false),
            'hourly_limit' => $hour,
            'daily_limit' => $day,
            'per_tick_limit' => $tick,
            'first_hour' => $firstHour,
            'first_calendar_day' => $firstCalendarDay,
            'first_24_hours' => $first24,
            'within_seven_days' => $requested - $remaining,
            'remaining' => $remaining,
            'completed' => $remaining === 0,
            'completion_minutes' => $completionMinutes,
            'forecast_starts_at' => $start->format('d/m/Y H:i'),
            'actual' => [
                'pending' => $count('pending'),
                'reserved' => $count('reserved'),
                'sending' => $count('sending'),
                'sent' => $count('sent'),
                'uncertain' => $count('uncertain'),
                'suppressed' => $count('suppressed'),
                'canceled' => $count('canceled'),
                'stale_reserved' => $staleReserved,
                'stale_sending' => $staleSending,
                'drafts' => EmailCampaign::query()->where('status', 'draft')->count(),
                'running' => EmailCampaign::query()->where('status', 'running')->count(),
                'paused' => EmailCampaign::query()->where('status', 'paused')->count(),
            ],
        ];
    }
}
