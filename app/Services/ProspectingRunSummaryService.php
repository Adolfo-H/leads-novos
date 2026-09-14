<?php

namespace App\Services;

use App\Models\ImportBatch;

final class ProspectingRunSummaryService
{
    /**
     * @return list<array{
     *     id: int,
     *     uuid: string,
     *     status: string,
     *     created_at: string|null,
     *     total_rows: int,
     *     processed_rows: int,
     *     discovered_count: int,
     *     known_count: int,
     *     new_candidates_count: int,
     *     lead_count: int,
     *     crm_blocked_count: int,
     *     researched_count: int,
     *     export_identified_count: int,
     *     failed_count: int,
     *     filters: array<string, mixed>
     * }>
     */
    public function recent(
        int $limit = 10
    ): array {
        $limit =
            max(
                1,
                min(
                    50,
                    $limit
                )
            );

        $batches =
            ImportBatch::query()
                ->where(
                    'source_type',
                    'prospecting'
                )
                ->with([
                    'items.company.crmCheck',
                    'items.company.sdrScore',
                    'items.company.exportIntelligence',
                ])
                ->latest('id')
                ->limit(
                    $limit
                )
                ->get();

        $result = [];

        foreach ($batches as $batch) {
            $leadCount = 0;
            $crmBlockedCount = 0;
            $researchedCount = 0;
            $exportIdentifiedCount = 0;
            $failedCount = 0;

            foreach ($batch->items as $item) {
                if (
                    $item->status
                    === 'failed'
                ) {
                    $failedCount++;
                }

                $company =
                    $item->company;

                if (! $company) {
                    continue;
                }

                if (
                    $company
                        ->sdrScore
                        ?->is_eligible
                    === true
                ) {
                    $leadCount++;
                }

                $crmStatus =
                    $company
                        ->crmCheck
                        ?->status;

                if (
                    in_array(
                        $crmStatus,
                        [
                            'client',
                            'opportunity',
                        ],
                        true
                    )
                ) {
                    $crmBlockedCount++;
                }

                $export =
                    $company
                        ->exportIntelligence;

                if (
                    $export
                    && $export->research_status
                        === 'completed'
                ) {
                    $researchedCount++;
                }

                if (
                    $export
                    && (
                        $export->direct_status
                            === 'yes'
                        || $export->indirect_status
                            === 'yes'
                        || $export->trading_status
                            === 'yes'
                    )
                ) {
                    $exportIdentifiedCount++;
                }
            }

            $rawMetadata =
                $batch->getAttribute(
                    'metadata'
                );

            /** @var mixed $rawMetadata */
            $metadata =
                is_array(
                    $rawMetadata
                )
                    ? $rawMetadata
                    : [];

            $prospecting =
                data_get(
                    $metadata,
                    'prospecting',
                    []
                );

            if (! is_array($prospecting)) {
                $prospecting = [];
            }

            $knownCount =
                data_get(
                    $prospecting,
                    'known_roots_count'
                );

            /*
             * Compatibilidade com os primeiros
             * lotes criados pelo comando antigo.
             */
            if (! is_numeric($knownCount)) {
                $knownCount =
                    data_get(
                        $prospecting,
                        'existing_roots_count',
                        0
                    );
            }

            $filters =
                data_get(
                    $prospecting,
                    'filters',
                    []
                );

            $result[] = [
                'id' => $batch->id,

                'uuid' => (string) $batch->uuid,

                'status' => (string) $batch->status,

                'created_at' => $batch
                    ->created_at
                    ?->toIso8601String(),

                'total_rows' => (int) $batch->total_rows,

                'processed_rows' => (int) $batch->processed_rows,

                'discovered_count' => (int) data_get(
                    $prospecting,
                    'discovered_count',
                    0
                ),

                'known_count' => is_numeric(
                    $knownCount
                )
                        ? (int) $knownCount
                        : 0,

                'new_candidates_count' => (int) data_get(
                    $prospecting,
                    'new_candidates_count',
                    $batch->total_rows
                ),

                'lead_count' => $leadCount,

                'crm_blocked_count' => $crmBlockedCount,

                'researched_count' => $researchedCount,

                'export_identified_count' => $exportIdentifiedCount,

                'failed_count' => $failedCount,

                'filters' => is_array($filters)
                        ? $filters
                        : [],
            ];
        }

        return $result;
    }
}
