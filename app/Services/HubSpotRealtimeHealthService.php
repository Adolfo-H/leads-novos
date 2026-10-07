<?php

namespace App\Services;

use App\Jobs\HubSpotQueueHeartbeat;
use App\Models\HubSpotRefreshRun;
use App\Models\HubSpotWebhookEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

final class HubSpotRealtimeHealthService
{
    public function __construct(
        private readonly HubSpotTunnelHealthService $tunnelHealth,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     pending: int,
     *     failed_recently: int,
     *     last_event_label: string,
     *     oldest_pending_label: string|null,
     *     webhook_worker_online: bool,
     *     webhook_worker_label: string,
     *     realtime_worker_online: bool,
     *     realtime_worker_label: string,
     *     tunnel_configured: bool,
     *     tunnel_checked: bool,
     *     tunnel_online: bool|null,
     *     tunnel_label: string,
     *     monitor_label: string,
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

        /*
         * created_at é a prova de quando o
         * Prospector realmente RECEBEU o webhook.
         */
        $latestReceivedAt =
            HubSpotWebhookEvent::query()
                ->max(
                    'created_at'
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
                    now()
                        ->subHour()
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

        $latestReceived =
            $this->date(
                $latestReceivedAt
            );

        $webhookWorker =
            $this->worker(
                'hubspot-webhooks'
            );

        $realtimeWorker =
            $this->worker(
                'hubspot-realtime'
            );

        $tunnel =
            $this
                ->tunnelHealth
                ->snapshot();

        /*
         * Evidência real > auto-probe.
         *
         * Se o HubSpot acabou de entregar um
         * webhook para nós, então necessariamente:
         *
         * HubSpot
         *   -> internet
         *   -> domínio/ngrok
         *   -> Laravel
         *
         * está funcionando.
         *
         * Em ambiente Docker local é possível o
         * container não conseguir acessar a própria
         * URL pública, gerando falso negativo.
         */
        $evidenceMinutes =
            max(
                1,
                (int) config(
                    'services.hubspot.health_public_evidence_minutes',
                    5
                )
            );

        $recentWebhookEvidence =
            $latestReceived !== null
            && $latestReceived
                ->greaterThanOrEqualTo(
                    now()
                        ->subMinutes(
                            $evidenceMinutes
                        )
                        ->toImmutable()
                );

        $tunnelConfigured =
            $tunnel[
                'configured'
            ];

        $tunnelChecked =
            $tunnel[
                'checked'
            ];

        $tunnelOnline =
            $tunnel[
                'online'
            ];

        $tunnelLabel =
            $tunnel[
                'label'
            ];

        if ($recentWebhookEvidence) {
            $tunnelConfigured =
                true;

            $tunnelChecked =
                true;

            $tunnelOnline =
                true;

            $tunnelLabel =
                'online · webhook '
                .$this->relative($latestReceived);
        }

        $monitorAt =
            $this->date(
                Cache::get(
                    HubSpotQueueHeartbeat::dispatchCacheKey()
                )
            );

        $monitorLabel =
            $monitorAt !== null
                ? $this->relative(
                    $monitorAt
                )
                : 'não inicializado';

        $workerProblem =
            ! $webhookWorker[
                'online'
            ]
            || ! $realtimeWorker[
                'online'
            ];

        $tunnelProblem =
            $tunnelChecked
            && $tunnelOnline
                !== true;

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

        if (
            $workerProblem
            || $tunnelProblem
        ) {
            $status =
                'degraded';

            $label =
                'HubSpot integração degradada';

        } elseif ($delayed) {
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

            /*
             * Só chamamos de "tempo real OK"
             * quando também conseguimos testar
             * o endpoint público.
             *
             * Se o túnel não estiver configurado,
             * afirmamos apenas aquilo que sabemos:
             * as filas estão saudáveis.
             */
            $label =
                $tunnelChecked
                    ? 'HubSpot tempo real OK'
                    : 'HubSpot filas OK';
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

            /*
             * Nunca mostramos 100% por
             * arredondamento.
             *
             * Exemplo:
             *
             * 1145 / 1146 = 99,91%
             *
             * round() transformava isso em 100%.
             *
             * Agora:
             *
             * - enquanto faltar qualquer item,
             *   o máximo visual é 99%;
             * - 100% somente quando finished
             *   realmente alcança total.
             */
            if ($total <= 0) {
                $bulkProgress =
                    0;

            } elseif ($finished >= $total) {
                $bulkProgress =
                    100;

            } else {
                $bulkProgress =
                    min(
                        99,
                        max(
                            0,
                            (int) floor(
                                (
                                    $finished
                                    / $total
                                )
                                * 100
                            )
                        )
                    );
            }

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

            'webhook_worker_online' => $webhookWorker[
                    'online'
                ],

            'webhook_worker_label' => $webhookWorker[
                    'label'
                ],

            'realtime_worker_online' => $realtimeWorker[
                    'online'
                ],

            'realtime_worker_label' => $realtimeWorker[
                    'label'
                ],

            'tunnel_configured' => $tunnelConfigured,

            'tunnel_checked' => $tunnelChecked,

            'tunnel_online' => $tunnelOnline,

            'tunnel_label' => $tunnelLabel,

            'monitor_label' => $monitorLabel,

            'bulk_active' => $bulk !== null,

            'bulk_progress' => $bulkProgress,

            'bulk_label' => $bulkLabel,
        ];
    }

    /**
     * @return array{
     *     online: bool,
     *     label: string
     * }
     */
    private function worker(
        string $queue
    ): array {
        $heartbeat =
            $this->date(
                Cache::get(
                    HubSpotQueueHeartbeat::cacheKey(
                        $queue
                    )
                )
            );

        if ($heartbeat === null) {
            return [
                'online' => false,
                'label' => 'sem heartbeat',
            ];
        }

        $staleSeconds =
            max(
                60,
                (int) config(
                    'services.hubspot.health_worker_stale_seconds',
                    180
                )
            );

        $online =
            $heartbeat
                ->greaterThanOrEqualTo(
                    now()
                        ->subSeconds(
                            $staleSeconds
                        )
                        ->toImmutable()
                );

        return [
            'online' => $online,

            'label' => $online
                    ? 'ativo '
                        .$this->relative(
                            $heartbeat
                        )
                    : 'sem resposta '
                        .$this->relative(
                            $heartbeat
                        ),
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
                (int) $date
                    ->diffInSeconds(
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
