<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\ImportQueueService;
use Illuminate\Console\Command;

class RecoverStaleImports extends Command
{
    protected $signature =
        'imports:recover-stale {--minutes=20}';

    protected $description =
        'Reenfileira itens de importação sem progresso.';

    public function handle(
        ImportQueueService $queue
    ): int {
        $minutes = max(
            1,
            (int) $this->option(
                'minutes'
            )
        );

        $cutoff = now()
            ->subMinutes(
                $minutes
            );

        $batches = ImportBatch::query()
            ->where(
                'status',
                'processing'
            )
            ->whereHas(
                'items',
                function ($query) use (
                    $cutoff
                ) {
                    $query
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
                        );
                }
            )
            ->orderBy('id')
            ->limit(100)
            ->get();

        $recovered = 0;

        foreach ($batches as $batch) {
            $recovered +=
                $queue->recoverStale(
                    $batch,
                    afterMinutes: $minutes,
                );
        }

        $this->components->info(
            $recovered === 1
                ? '1 item recuperado.'
                : $recovered
                    .' itens recuperados.'
        );

        return self::SUCCESS;
    }
}
