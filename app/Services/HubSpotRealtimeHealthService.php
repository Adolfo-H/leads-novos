<?php

namespace App\Services;

use App\Models\HubSpotRefreshRun;
use App\Models\HubSpotWebhookEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class HubSpotRealtimeHealthService
{
    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     pending: int,
     *     failed_recently: int,
     *     last_event_label: string,
     *     oldest_pending_label: string|null,
     *     bulk_active: bool,
     *     bulk_progress: int|null,
     *     bulk_label: string|null
     * }
     */
    public function snapshot(): array
    {
        $pendingQuery =
            HubSpotWebhookEvent::query()
                ->whereIn(
                    'status',
                    [
                        'received',
                        'queued',
                        'processing',
                    ]
                );

        $pending =
            (clone $pendingQuery)
                ->count();

        $oldestPendingAt =
            (clone $pendingQuery)
                ->min(
                    'created_at'
                );

        $latestEventAt =
            HubSpotWebhookEvent::query()
                ->whereNotNull(
                    'occurred_at'
                )
                ->max(
                    'occurred_at'
                );

        $failedRecently =
            HubSpotWebhookEvent::query()
                ->where(
                    'status',
                    'failed'
                )
                ->where(
                    'updated_at',
                    '>=',
                    now()->subHour()
                )
                ->count();

        $oldestPending =
            $this->date(
                $oldestPendingAt
            );

        $latestEvent =
            $this->date(
                $latestEventAt
            );

        $delayed =
            $failedRecently > 0
            || (
                $oldestPending !== null
                && $oldestPending->lessThan(
                    now()
                        ->subMinute()
                        ->toImmutable()
                )
            );

        if ($delayed) {
            $status =
                'delayed';

            $label =
                'HubSpot com atraso';

        } elseif ($pending > 0) {
            $status =
                'processing';

            $label =
                'HubSpot processando';

        } else {
            $status =
                'healthy';

            $label =
                'HubSpot tempo real OK';
        }

        $bulk =
            HubSpotRefreshRun::query()
                ->whereIn(
                    'status',
                    [
                        'queued',
                        'running',
                    ]
                )
                ->latest(
                    'id'
                )
                ->first();

        $bulkProgress =
            null;

        $bulkLabel =
            null;

        if ($bulk !== null) {
            $total =
                max(
                    0,
                    (int) $bulk->total
                );

            $finished =
                max(
                    0,
                    (int) $bulk->processed
                    + (int) $bulk->failed
                );

            $bulkProgress =
                $total > 0
                    ? min(
                        100,
                        (int) round(
                            (
                                $finished
                                / $total
                            )
                            * 100
                        )
                    )
                    : 0;

            $bulkLabel =
                number_format(
                    $finished,
                    0,
                    ',',
                    '.'
                )
                .' / '
                .number_format(
                    $total,
                    0,
                    ',',
                    '.'
                );
        }

        return [
            'status' => $status,

            'label' => $label,

            'pending' => $pending,

            'failed_recently' => $failedRecently,

            'last_event_label' => $latestEvent !== null
                    ? $this->relative(
                        $latestEvent
                    )
                    : 'nenhum evento recebido',

            'oldest_pending_label' => $oldestPending !== null
                    ? $this->relative(
                        $oldestPending
                    )
                    : null,

            'bulk_active' => $bulk !== null,

            'bulk_progress' => $bulkProgress,

            'bulk_label' => $bulkLabel,
        ];
    }

    private function date(
        mixed $value
    ): ?CarbonImmutable {
        if (
            $value instanceof DateTimeInterface
        ) {
            return CarbonImmutable::instance(
                $value
            );
        }

        if (
            ! is_scalar(
                $value
            )
            || trim(
                (string) $value
            ) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                (string) $value
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function relative(
        CarbonImmutable $date
    ): string {
        $seconds =
            max(
                0,
                $date->diffInSeconds(
                    now()
                )
            );

        if ($seconds < 10) {
            return 'agora';
        }

        if ($seconds < 60) {
            return 'há '
                .$seconds
                .' s';
        }

        $minutes =
            (int) floor(
                $seconds / 60
            );

        if ($minutes < 60) {
            return 'há '
                .$minutes
                .' min';
        }

        $hours =
            (int) floor(
                $minutes / 60
            );

        if ($hours < 24) {
            return 'há '
                .$hours
                .' h';
        }

        return $date->format(
            'd/m H:i'
        );
    }
}
