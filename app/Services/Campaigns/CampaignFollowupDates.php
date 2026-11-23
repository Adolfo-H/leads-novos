<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Datas por rodada, sem janela de recuperacao automatica apos o horario escolhido. */
final class CampaignFollowupDates
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function isExplicit(\stdClass $plan): bool
    {
        return isset($plan->send_dates);
    }

    /** @return array<int, string> */
    public function dates(\stdClass $plan): array
    {
        $raw = $plan->send_dates ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }
        $dates = [];
        foreach ($decoded as $round => $date) {
            $index = (int) $round;
            if ($index >= 2 && $index <= 5 && is_string($date) && $this->at($date) !== null) {
                $dates[$index] = $date;
            }
        }

        return $dates;
    }

    public function at(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::parse($date.' 08:00:00', self::TIMEZONE)->setTimezone('UTC');
    }

    public function dateForRound(\stdClass $plan, int $round): ?CarbonImmutable
    {
        $raw = $this->dates($plan)[$round] ?? null;

        return $raw === null ? null : $this->at($raw);
    }

    /**
     * @return 'missing'|'waiting'|'scheduled'|'ready'|'expired'|'invalid_previous'
     */
    public function state(\stdClass $plan, int $round, EmailCampaign $previous, ?CarbonImmutable $now = null): string
    {
        $due = $this->dateForRound($plan, $round);
        if ($due === null) {
            return 'missing';
        }
        if ($previous->status !== 'completed' || $previous->completed_at === null) {
            return 'waiting';
        }
        $completedRaw = $previous->getRawOriginal('completed_at');
        if (! is_string($completedRaw) || trim($completedRaw) === '') {
            return 'invalid_previous';
        }
        try {
            $completed = CarbonImmutable::parse($completedRaw, 'UTC');
        } catch (\Throwable) {
            return 'invalid_previous';
        }
        $lastSent = DB::table('email_campaign_recipients')
            ->where('email_campaign_id', $previous->id)
            ->where('status', 'sent')->max('sent_at');
        try {
            $last = $lastSent === null ? null : CarbonImmutable::parse((string) $lastSent, 'UTC');
        } catch (\Throwable) {
            return 'invalid_previous';
        }
        if ($completed->greaterThan($due) || ($last !== null && $last->greaterThan($due))) {
            return 'invalid_previous';
        }
        $now ??= CarbonImmutable::now('UTC');
        if ($now->lessThan($due)) {
            return 'scheduled';
        }
        // O scheduler verifica a cada 5 minutos. Fora dos primeiros 15 minutos,
        // nao inicia tardiamente ao ligar o notebook ou recuperar Redis/IMAP.
        if ($now->greaterThanOrEqualTo($due->addMinutes(15))) {
            return 'expired';
        }

        return 'ready';
    }
}
