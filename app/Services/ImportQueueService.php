<?php

namespace App\Services;

use App\Jobs\EnrichImportItem;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportQueueService
{
    public function dispatchReady(
        ImportBatch $batch
    ): int {
        $items = $batch
            ->items()
            ->where(
                'status',
                'ready'
            )
            ->get();

        $dispatched = 0;

        foreach ($items as $item) {
            /** @var ImportItem $item */
            if (
                $this->dispatchItem(
                    $item
                )
            ) {
                $dispatched++;
            }
        }

        if ($dispatched > 0) {
            $batch->update([
                'status' => 'processing',
            ]);
        }

        return $dispatched;
    }

    public function recoverStale(
        ImportBatch $batch,
        int $afterMinutes = 20,
    ): int {
        $afterMinutes = max(
            1,
            $afterMinutes
        );

        $cutoff = now()
            ->subMinutes(
                $afterMinutes
            );

        $items = $batch
            ->items()
            ->whereIn(
                'status',
                [
                    'queued',
                    'processing',
                ]
            )
            ->where(
                'updated_at',
                '<=',
                $cutoff
            )
            ->orderBy('id')
            ->limit(100)
            ->get();

        $recovered = 0;

        foreach ($items as $item) {
            /** @var ImportItem $item */
            if (
                $this->dispatchItem(
                    $item,
                    recovery: true,
                )
            ) {
                $recovered++;
            }
        }

        if ($items->isNotEmpty()) {
            $this->refreshBatch(
                $batch->id
            );
        }

        return $recovered;
    }

    public function refreshBatch(
        int $batchId
    ): void {
        DB::transaction(
            function () use ($batchId) {
                $batch = ImportBatch::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $batchId
                    );

                $counts = ImportItem::query()
                    ->where(
                        'import_batch_id',
                        $batchId
                    )
                    ->selectRaw(
                        'status, COUNT(*) as total'
                    )
                    ->groupBy('status')
                    ->pluck(
                        'total',
                        'status'
                    );

                $active =
                    (int) (
                        $counts[
                            'ready'
                        ]
                        ?? 0
                    )
                    + (int) (
                        $counts[
                            'queued'
                        ]
                        ?? 0
                    )
                    + (int) (
                        $counts[
                            'processing'
                        ]
                        ?? 0
                    );

                $completed =
                    (int) (
                        $counts[
                            'completed'
                        ]
                        ?? 0
                    );

                $failed =
                    (int) (
                        $counts[
                            'failed'
                        ]
                        ?? 0
                    );

                $status =
                    $active > 0
                        ? 'processing'
                        : (
                            $failed > 0
                            && $completed === 0
                                ? 'failed'
                                : 'completed'
                        );

                $batch->update([
                    'status' => $status,

                    'processed_rows' => $completed
                        + $failed,
                ]);
            }
        );
    }

    private function dispatchItem(
        ImportItem $item,
        bool $recovery = false,
    ): bool {
        $metadata =
            $item->getAttribute(
                'metadata'
            );

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $queueMetadata =
            data_get(
                $metadata,
                'queue',
                []
            );

        if (
            ! is_array(
                $queueMetadata
            )
        ) {
            $queueMetadata = [];
        }

        /*
         * Se houve falha antiga de dispatch,
         * uma nova tentativa começa limpa.
         */
        unset(
            $queueMetadata[
                'last_dispatch_error'
            ]
        );

        if ($recovery) {
            $queueMetadata[
                'recovery_count'
            ] =
                (int) (
                    $queueMetadata[
                        'recovery_count'
                    ]
                    ?? 0
                )
                + 1;

            $queueMetadata[
                'last_recovered_at'
            ] =
                now()
                    ->toIso8601String();

            $queueMetadata[
                'previous_status'
            ] =
                $item->status;
        } else {
            $queueMetadata[
                'recovery_count'
            ] ??= 0;
        }

        $queueMetadata[
            'last_enqueued_at'
        ] =
            now()
                ->toIso8601String();

        $metadata[
            'queue'
        ] =
            $queueMetadata;

        /*
         * Primeiro registramos que ele está
         * sendo enviado para a fila.
         */
        $item->update([
            'status' => 'queued',

            'error_message' => $recovery
                    ? (
                        'Item reenfileirado '
                        .'automaticamente após '
                        .'ficar sem progresso.'
                    )
                    : null,

            'metadata' => $metadata,
        ]);

        try {
            EnrichImportItem::dispatch(
                $item->id
            );

            return true;
        } catch (
            Throwable $exception
        ) {
            /*
             * O banco não pode dizer "queued"
             * se o job nem conseguiu entrar
             * no Redis.
             */
            $queueMetadata[
                'last_dispatch_error'
            ] =
                mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    1000
                );

            $metadata[
                'queue'
            ] =
                $queueMetadata;

            $item->update([
                'status' => 'ready',

                'error_message' => 'Não foi possível enviar '
                    .'o item para a fila: '
                    .mb_substr(
                        $exception
                            ->getMessage(),
                        0,
                        1800
                    ),

                'metadata' => $metadata,
            ]);

            return false;
        }
    }
}
