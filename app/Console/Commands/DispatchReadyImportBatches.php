<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\ImportQueueService;
use Illuminate\Console\Command;

class DispatchReadyImportBatches extends Command
{
    protected $signature =
        'imports:dispatch-ready';

    protected $description =
        'Envia lotes de importação prontos para a fila em blocos limitados.';

    public function handle(
        ImportQueueService $queue
    ): int {
        $batchLimit = max(
            1,
            min(
                500,
                (int) config(
                    'prospector.imports.ready_batches_per_run',
                    100
                )
            )
        );

        $batches = ImportBatch::query()
            ->whereIn(
                'status',
                [
                    'ready',
                    'processing',
                ]
            )
            ->whereHas(
                'items',
                static fn ($query) => $query->where(
                    'status',
                    'ready'
                )
            )
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($batchLimit)
            ->get();

        $dispatched = 0;

        foreach ($batches as $batch) {
            $dispatched += $queue->dispatchReady($batch);
            $queue->refreshBatch($batch->id);
        }

        $this->components->info(
            $dispatched === 1
                ? '1 item enviado à fila.'
                : $dispatched.' itens enviados à fila.'
        );

        return self::SUCCESS;
    }
}
