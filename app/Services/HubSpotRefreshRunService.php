<?php

namespace App\Services;

use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\HubSpotRefreshItem;
use App\Models\HubSpotRefreshRun;
use Illuminate\Support\Facades\DB;

final class HubSpotRefreshRunService
{
    public function active(): ?HubSpotRefreshRun
    {
        return HubSpotRefreshRun::query()
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
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function start(
        array $companyIds,
        ?int $userId,
    ): HubSpotRefreshRun {
        $companyIds =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn (
                                mixed $id
                            ): int => (int) $id,
                            $companyIds
                        ),
                        static fn (
                            int $id
                        ): bool => $id > 0
                    )
                )
            );

        $run =
            DB::transaction(
                function () use (
                    $companyIds,
                    $userId,
                ): HubSpotRefreshRun {
                    $run =
                        HubSpotRefreshRun::query()
                            ->create([
                                'initiated_by' => $userId,

                                'scope' => 'bulk',

                                'status' => 'running',

                                'total' => count(
                                    $companyIds
                                ),

                                'processed' => 0,

                                'changed' => 0,

                                'unchanged' => 0,

                                'failed' => 0,

                                'started_at' => now(),
                            ]);

                    $now =
                        now();

                    $rows = [];

                    foreach (
                        $companyIds as $companyId
                    ) {
                        $rows[] = [
                            'run_id' => $run->id,

                            'company_id' => $companyId,

                            'status' => 'queued',

                            'created_at' => $now,

                            'updated_at' => $now,
                        ];
                    }

                    foreach (
                        array_chunk(
                            $rows,
                            500
                        ) as $chunk
                    ) {
                        HubSpotRefreshItem::query()
                            ->insert(
                                $chunk
                            );
                    }

                    return $run;
                }
            );

        $items =
            HubSpotRefreshItem::query()
                ->where(
                    'run_id',
                    $run->id
                )
                ->get([
                    'id',
                    'company_id',
                ]);

        foreach ($items as $item) {
            /*
             * IMPORTANTÍSSIMO:
             *
             * O segundo argumento vincula o Job
             * ao item usado pela barra de progresso.
             */
            RefreshCompanyFromHubSpot::dispatch(
                $item->company_id,
                $item->id,
            );
        }

        return $run->refresh();
    }

    public function markRunning(
        int $itemId
    ): void {
        HubSpotRefreshItem::query()
            ->whereKey(
                $itemId
            )
            ->where(
                'status',
                'queued'
            )
            ->update([
                'status' => 'running',

                'started_at' => now(),

                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    public function complete(
        int $itemId,
        array $changes,
    ): void {
        $finalizeRunId =
            DB::transaction(
                function () use (
                    $itemId,
                    $changes,
                ): ?int {
                    $item =
                        HubSpotRefreshItem::query()
                            ->lockForUpdate()
                            ->find(
                                $itemId
                            );

                    if ($item === null) {
                        return null;
                    }

                    if (
                        in_array(
                            $item->status,
                            [
                                'changed',
                                'unchanged',
                                'failed',
                            ],
                            true
                        )
                    ) {
                        return null;
                    }

                    $changed =
                        $changes !== [];

                    $item
                        ->forceFill([
                            'status' => $changed
                                    ? 'changed'
                                    : 'unchanged',

                            'changes' => $changes,

                            'error' => null,

                            'completed_at' => now(),
                        ])
                        ->save();

                    $run =
                        HubSpotRefreshRun::query()
                            ->lockForUpdate()
                            ->findOrFail(
                                $item->run_id
                            );

                    $run->processed =
                        $run->processed + 1;

                    if ($changed) {
                        $run->changed =
                            $run->changed + 1;
                    } else {
                        $run->unchanged =
                            $run->unchanged + 1;
                    }

                    $finished =
                        $run->processed
                        + $run->failed;

                    if (
                        $finished >=
                        $run->total
                    ) {
                        $run->status =
                            $run->failed > 0
                                ? 'completed_with_errors'
                                : 'completed';

                        $run->completed_at =
                            now();
                    }

                    $run->save();

                    return
                        $finished >=
                        $run->total
                            ? $run->id
                            : null;
                }
            );

        if ($finalizeRunId !== null) {
            $this->buildSummary(
                $finalizeRunId
            );
        }
    }

    public function fail(
        int $itemId,
        string $error,
    ): void {
        $finalizeRunId =
            DB::transaction(
                function () use (
                    $itemId,
                    $error,
                ): ?int {
                    $item =
                        HubSpotRefreshItem::query()
                            ->lockForUpdate()
                            ->find(
                                $itemId
                            );

                    if ($item === null) {
                        return null;
                    }

                    if (
                        in_array(
                            $item->status,
                            [
                                'changed',
                                'unchanged',
                                'failed',
                            ],
                            true
                        )
                    ) {
                        return null;
                    }

                    $item
                        ->forceFill([
                            'status' => 'failed',

                            'error' => mb_substr(
                                $error,
                                0,
                                4000
                            ),

                            'completed_at' => now(),
                        ])
                        ->save();

                    $run =
                        HubSpotRefreshRun::query()
                            ->lockForUpdate()
                            ->findOrFail(
                                $item->run_id
                            );

                    $run->failed =
                        $run->failed + 1;

                    $finished =
                        $run->processed
                        + $run->failed;

                    if (
                        $finished >=
                        $run->total
                    ) {
                        $run->status =
                            'completed_with_errors';

                        $run->completed_at =
                            now();
                    }

                    $run->save();

                    return
                        $finished >=
                        $run->total
                            ? $run->id
                            : null;
                }
            );

        if ($finalizeRunId !== null) {
            $this->buildSummary(
                $finalizeRunId
            );
        }
    }

    private function buildSummary(
        int $runId
    ): void {
        $items =
            HubSpotRefreshItem::query()
                ->where(
                    'run_id',
                    $runId
                )
                ->where(
                    'status',
                    'changed'
                )
                ->with([
                    'company:id,corporate_name',
                ])
                ->orderByDesc(
                    'completed_at'
                )
                ->get();

        /**
         * @var array<string, int> $categories
         */
        $categories = [];

        foreach ($items as $item) {
            /*
             * getAttribute() evita inferência
             * incorreta do PHPStan sobre JSON.
             */
            $rawChanges =
                $item->getAttribute(
                    'changes'
                );

            if (! is_array($rawChanges)) {
                continue;
            }

            foreach ($rawChanges as $rawChange) {
                if (! is_array($rawChange)) {
                    continue;
                }

                $rawLabel =
                    $rawChange[
                        'label'
                    ]
                    ?? null;

                if (! is_scalar($rawLabel)) {
                    continue;
                }

                $label =
                    trim(
                        (string) $rawLabel
                    );

                if ($label === '') {
                    continue;
                }

                $categories[$label] =
                    (
                        $categories[$label]
                        ?? 0
                    )
                    + 1;
            }
        }

        arsort(
            $categories
        );

        $categoryRows = [];

        foreach (
            $categories as $label => $count
        ) {
            $categoryRows[] = [
                'label' => $label,

                'count' => $count,
            ];
        }

        $recent = [];

        foreach (
            $items->take(8) as $item
        ) {
            $rawChanges =
                $item->getAttribute(
                    'changes'
                );

            $changes = [];

            if (is_array($rawChanges)) {
                foreach (
                    $rawChanges as $rawChange
                ) {
                    if (is_array($rawChange)) {
                        $changes[] =
                            $rawChange;
                    }
                }
            }

            $companyRelation =
                $item->getRelation(
                    'company'
                );

            $companyName =
                $companyRelation instanceof Company
                    ? $companyRelation
                        ->corporate_name
                    : (
                        'Empresa #'
                        .$item->company_id
                    );

            $recent[] = [
                'company' => $companyName,

                'changes' => array_slice(
                    $changes,
                    0,
                    4
                ),
            ];
        }

        HubSpotRefreshRun::query()
            ->whereKey(
                $runId
            )
            ->update([
                'summary' => [
                    'categories' => array_slice(
                        $categoryRows,
                        0,
                        10
                    ),

                    'recent' => $recent,
                ],
            ]);
    }
}
