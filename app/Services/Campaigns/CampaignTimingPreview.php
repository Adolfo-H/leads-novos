<?php

namespace App\Services\Campaigns;

/** Read-only reference estimate for the HTTP wizard. Never changes queues or policies. */
final class CampaignTimingPreview
{
    public const HOURS = [0, 1, 2, 4, 8, 12, 24];

    /**
     * Estimates elapsed processing time assuming one scheduler cycle per minute,
     * empty rolling quotas, no other work and all attempts accepted immediately.
     * Manual caps match CampaignPacing::save(); automatic uses global quotas.
     *
     * @param  array{hour:int,tick:int,day:int}  $policy
     * @return array{hour:int,tick:int,day:int,minutes:int}
     */
    public static function estimate(int $recipients, int $hours, array $policy): array
    {
        $recipients = max(0, min(1000, $recipients));
        $hours = in_array($hours, self::HOURS, true) ? $hours : 0;
        $day = max(1, $policy['day']);
        // Zero means global policy, not a duration of zero hours.
        $hour = min(max(1, $policy['hour']), $day);
        $tick = min(max(1, $policy['tick']), $hour);
        if ($hours > 0) {
            $hour = min($hour, max(1, (int) ceil($recipients / $hours)));
            $tick = min($tick, $hour, max(1, (int) ceil($recipients / ($hours * 60))));
        }
        if ($recipients === 0) {
            return ['hour' => $hour, 'tick' => $tick, 'day' => $day, 'minutes' => 0];
        }

        // Simulated, not actual, quota history. Skip periods without capacity.
        /** @var list<array{minute:int,count:int}> $batches */
        $batches = [];
        $minute = 0;
        $hourIndex = 0;
        $dayIndex = 0;
        $hourUsed = 0;
        $dayUsed = 0;
        $remaining = $recipients;
        while ($remaining > 0) {
            while (isset($batches[$hourIndex]) && $batches[$hourIndex]['minute'] + 60 <= $minute) {
                $hourUsed -= $batches[$hourIndex]['count'];
                $hourIndex++;
            }
            while (isset($batches[$dayIndex]) && $batches[$dayIndex]['minute'] + 1440 <= $minute) {
                $dayUsed -= $batches[$dayIndex]['count'];
                $dayIndex++;
            }
            $available = min($remaining, $tick, $hour - $hourUsed, $day - $dayUsed);
            if ($available > 0) {
                $batches[] = ['minute' => $minute, 'count' => $available];
                $hourUsed += $available;
                $dayUsed += $available;
                $remaining -= $available;
                $minute++;

                continue;
            }

            $next = $minute + 1;
            if ($hourUsed >= $hour && isset($batches[$hourIndex])) {
                $next = max($next, $batches[$hourIndex]['minute'] + 60);
            }
            if ($dayUsed >= $day && isset($batches[$dayIndex])) {
                $next = max($next, $batches[$dayIndex]['minute'] + 1440);
            }
            $minute = $next;
        }

        return ['hour' => $hour, 'tick' => $tick, 'day' => $day, 'minutes' => $minute];
    }

    public static function label(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'Sem destinatários';
        }
        $parts = [];
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;
        if ($days > 0) {
            $parts[] = $days.($days === 1 ? ' dia' : ' dias');
        }
        if ($hours > 0) {
            $parts[] = $hours.' h';
        }
        if ($rest > 0) {
            $parts[] = $rest.' min';
        }

        return implode(' ', $parts);
    }
}
