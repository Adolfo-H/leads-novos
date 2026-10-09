<?php

namespace App\Services;

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HubSpotWebhookQueueService
{
    /**
     * Depois de cinco tentativas normais do Job,
     * permitimos recuperação até o total de dez
     * processamentos registrados no banco.
     *
     * Assim falhas transitórias podem se recuperar
     * sem criar um loop infinito.
     */
    private const MAX_TOTAL_ATTEMPTS = 10;

    /* WEBHOOK_RECOVERY_SAFE_V10
     * Redis retry_after: 720s. O job pode tentar ate 5 vezes,
     * com timeout de 120s e backoff 15/60/180/600s.
     * Evita recuperar um evento enquanto o worker ainda tenta.
     */
    private const MIN_QUEUED_STALE_MINUTES = 15;

    private const MIN_PROCESSING_STALE_MINUTES = 30;

    /**
     * Estados que nunca devem voltar
     * automaticamente para a fila.
     *
     * @var list<string>
     */
    private const TERMINAL_STATUSES = [
        'processed',
        'ignored',
        'blocked_scope',
    ];

    /**
     * Reserva o evento no banco antes de falar
     * com Redis.
     *
     * Essa ordem é intencional:
     *
     * BANCO → FILA
     *
     * Nunca:
     *
     * FILA → BANCO
     *
     * Se Redis estiver indisponível, o evento
     * continua persistido e recuperável.
     */
    public function dispatch(
        HubSpotWebhookEvent|int $event,
        bool $force = false,
        ?int $staleMinutes = null,
    ): bool {
        $eventId =
            $event instanceof HubSpotWebhookEvent
                ? (int) $event->id
                : $event;

        $reserved =
            DB::transaction(
                function () use (
                    $eventId,
                    $force,
                    $staleMinutes,
                ): bool {
                    $locked =
                        HubSpotWebhookEvent::query()
                            ->whereKey(
                                $eventId
                            )
                            ->lockForUpdate()
                            ->first();

                    if ($locked === null) {
                        return false;
                    }

                    /*
                     * A selecao e a reserva nao sao atomicas.
                     * Outra tentativa pode ter atualizado o evento
                     * entre a listagem e este lock. Rechecamos aqui.
                     */
                    if (
                        $force
                        && $staleMinutes !== null
                        && in_array(
                            $locked->status,
                            ['queued', 'processing'],
                            true
                        )
                    ) {
                        $minimum = $locked->status === 'queued'
                            ? self::MIN_QUEUED_STALE_MINUTES
                            : self::MIN_PROCESSING_STALE_MINUTES;

                        $cutoff = now()->subMinutes(
                            max($minimum, $staleMinutes)
                        );

                        if (
                            $locked->getRawOriginal('updated_at') === null
                            || HubSpotWebhookEvent::query()
                                ->whereKey($eventId)
                                ->where('updated_at', '>', $cutoff)
                                ->exists()
                        ) {
                            return false;
                        }
                    }

                    if (
                        in_array(
                            $locked->status,
                            self::TERMINAL_STATUSES,
                            true
                        )
                    ) {
                        return false;
                    }

                    /*
                     * Evento definitivamente esgotado:
                     * não entra em loop automático.
                     */
                    if (
                        $locked->status
                            === 'failed'
                        && $locked->attempts
                            >= self::MAX_TOTAL_ATTEMPTS
                    ) {
                        return false;
                    }

                    /*
                     * Fluxo normal:
                     *
                     * se já estiver queued ou processing,
                     * outra requisição não cria outro job.
                     */
                    if (
                        ! $force
                        && in_array(
                            $locked->status,
                            [
                                'queued',
                                'processing',
                            ],
                            true
                        )
                    ) {
                        return false;
                    }

                    if (
                        ! in_array(
                            $locked->status,
                            [
                                'received',
                                'failed',
                                'queued',
                                'processing',
                            ],
                            true
                        )
                    ) {
                        return false;
                    }

                    /*
                     * updated_at passa a representar
                     * também o momento da última
                     * reserva para a fila.
                     *
                     * Isso permite detectar jobs
                     * abandonados sem adicionar uma
                     * migration somente para queued_at.
                     */
                    $locked->forceFill([
                        'status' => 'queued',

                        'processed_at' => null,
                    ])->save();

                    return true;
                }
            );

        if (! $reserved) {
            return false;
        }

        try {
            ProcessHubSpotWebhookEvent::dispatch(
                $eventId
            );

            return true;
        } catch (Throwable $exception) {
            /*
             * O banco já possui o webhook.
             *
             * Se Redis falhar, voltamos para
             * "received", que é recuperado pelo
             * scheduler ou por um reenvio do HubSpot.
             */
            HubSpotWebhookEvent::query()
                ->whereKey(
                    $eventId
                )
                ->where(
                    'status',
                    'queued'
                )
                ->update([
                    'status' => 'received',

                    'error' => mb_substr(
                        'Falha ao enviar webhook para a fila: '
                        .$exception->getMessage(),
                        0,
                        4000
                    ),

                    'processed_at' => null,
                ]);

            return false;
        }
    }

    /**
     * Recupera:
     *
     * - received:
     *   persistido mas ainda não enviado;
     *
     * - failed:
     *   falhou depois dos retries do Job;
     *
     * - queued antigo:
     *   banco marcou como enfileirado,
     *   mas o job pode ter sido perdido;
     *
     * - processing antigo:
     *   worker pode ter morrido durante
     *   o processamento.
     *
     * @return array{
     *     selected: int,
     *     queued: int,
     *     skipped: int
     * }
     */
    public function recover(
        int $staleMinutes = 10,
        int $limit = 100,
    ): array {
        $staleMinutes =
            max(
                1,
                $staleMinutes
            );

        $limit =
            max(
                1,
                min(
                    1000,
                    $limit
                )
            );

        $queuedStaleBefore = now()->subMinutes(
            max($staleMinutes, self::MIN_QUEUED_STALE_MINUTES)
        );

        $processingStaleBefore = now()->subMinutes(
            max($staleMinutes, self::MIN_PROCESSING_STALE_MINUTES)
        );

        $ids =
            HubSpotWebhookEvent::query()
                ->where(
                    function (
                        $query
                    ) use (
                        $queuedStaleBefore,
                        $processingStaleBefore
                    ): void {
                        /*
                         * Persistido sem job.
                         */
                        $query
                            ->where(
                                'status',
                                'received'
                            )

                            /*
                             * Job esgotou retries,
                             * mas ainda pode passar
                             * pela recuperação limitada.
                             */
                            ->orWhere(
                                function (
                                    $failed
                                ): void {
                                    $failed
                                        ->where(
                                            'status',
                                            'failed'
                                        )
                                        ->where(
                                            'attempts',
                                            '<',
                                            self::MAX_TOTAL_ATTEMPTS
                                        );
                                }
                            )

                            /*
                             * Reserva antiga ou
                             * processamento abandonado.
                             */
                            ->orWhere(
                                function ($queued) use ($queuedStaleBefore): void {
                                    $queued
                                        ->where('status', 'queued')
                                        ->where('updated_at', '<=', $queuedStaleBefore);
                                }
                            )
                            ->orWhere(
                                function ($processing) use ($processingStaleBefore): void {
                                    $processing
                                        ->where('status', 'processing')
                                        ->where('updated_at', '<=', $processingStaleBefore);
                                }
                            );
                    }
                )
                ->orderBy(
                    'id'
                )
                ->limit(
                    $limit
                )
                ->pluck(
                    'id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->all();

        $queued =
            0;

        $skipped =
            0;

        foreach (
            $ids as $eventId
        ) {
            if (
                $this->dispatch(
                    event: $eventId,

                    force: true,
                    staleMinutes: $staleMinutes,
                )
            ) {
                $queued++;

                continue;
            }

            $skipped++;
        }

        return [
            'selected' => count(
                $ids
            ),

            'queued' => $queued,

            'skipped' => $skipped,
        ];
    }
}
