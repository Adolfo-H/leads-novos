<?php

namespace App\Services;

use App\Jobs\EnrichImportItem;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use Illuminate\Support\Facades\DB;

final class ImportQueueService
{
    public function dispatchReady(
        ImportBatch $batch
    ): int {
        $items = $batch
            ->items()
            ->where('status', 'ready')
            ->get();

        foreach ($items as $item) {
            $item->update([
                'status' => 'queued',
                'error_message' => null,
            ]);

            EnrichImportItem::dispatch(
                $item->id
            );
        }

        if ($items->isNotEmpty()) {
            $batch->update([
                'status' => 'processing',
            ]);
        }

        return $items->count();
    }

    public function refreshBatch(
        int $batchId
    ): void {
        DB::transaction(
            function () use ($batchId) {
                $batch = ImportBatch::query()
                    ->lockForUpdate()
                    ->findOrFail($batchId);

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
                    (int) ($counts['ready'] ?? 0)
                    + (int) ($counts['queued'] ?? 0)
                    + (int) ($counts['processing'] ?? 0);

                $completed =
                    (int) ($counts['completed'] ?? 0);

                $failed =
                    (int) ($counts['failed'] ?? 0);

                $status = $active > 0
                    ? 'processing'
                    : (
                        $failed > 0
                        && $completed === 0
                            ? 'failed'
                            : 'completed'
                    );

                $batch->update([
                    'status' => $status,
                    'processed_rows' => $completed + $failed,
                ]);
            }
        );
    }
}
