<?php

use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\HubSpotRefreshItem;
use App\Models\HubSpotRefreshRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('requeues an abandoned HubSpot bulk refresh item', function () {
    Queue::fake();

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '91919191',

                'corporate_name' => 'EMPRESA BULK STALE',
            ]);

    $run =
        HubSpotRefreshRun::query()
            ->create([
                'scope' => 'bulk',

                'status' => 'running',

                'total' => 1,

                'processed' => 0,

                'changed' => 0,

                'unchanged' => 0,

                'failed' => 0,

                'started_at' => now()
                    ->subHour(),
            ]);

    $item =
        HubSpotRefreshItem::query()
            ->create([
                'run_id' => $run->id,

                'company_id' => $company->id,

                'status' => 'running',

                'started_at' => now()
                    ->subHour(),
            ]);

    DB::table(
        'hubspot_refresh_items'
    )
        ->where(
            'id',
            $item->id
        )
        ->update([
            'updated_at' => now()
                ->subMinutes(
                    30
                ),
        ]);

    $this
        ->artisan(
            'hubspot:bulk-recover',
            [
                '--minutes' => 20,

                '--limit' => 100,
            ]
        )
        ->expectsOutput(
            'Itens stale selecionados: 1 | reenfileirados: 1'
        )
        ->assertSuccessful();

    Queue::assertPushed(
        RefreshCompanyFromHubSpot::class,
        function (
            RefreshCompanyFromHubSpot $job
        ) use (
            $company,
            $item
        ): bool {
            return
                $job->companyId
                    === $company->id
                && $job->refreshItemId
                    === $item->id;
        }
    );

    expect(
        $item
            ->fresh()
            ->updated_at
    )->toBeGreaterThan(
        now()
            ->subMinute()
    );
});

it('does not requeue a recent bulk refresh item', function () {
    Queue::fake();

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '92929292',

                'corporate_name' => 'EMPRESA BULK RECENTE',
            ]);

    $run =
        HubSpotRefreshRun::query()
            ->create([
                'scope' => 'bulk',

                'status' => 'running',

                'total' => 1,

                'processed' => 0,

                'changed' => 0,

                'unchanged' => 0,

                'failed' => 0,

                'started_at' => now()
                    ->subMinutes(
                        5
                    ),
            ]);

    HubSpotRefreshItem::query()
        ->create([
            'run_id' => $run->id,

            'company_id' => $company->id,

            'status' => 'running',

            'started_at' => now()
                ->subMinutes(
                    5
                ),
        ]);

    $this
        ->artisan(
            'hubspot:bulk-recover',
            [
                '--minutes' => 20,
            ]
        )
        ->expectsOutput(
            'Itens stale selecionados: 0 | reenfileirados: 0'
        )
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('ignores stale items whose refresh run is already finished', function () {
    Queue::fake();

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '93939393',

                'corporate_name' => 'EMPRESA BULK FINALIZADO',
            ]);

    $run =
        HubSpotRefreshRun::query()
            ->create([
                'scope' => 'bulk',

                'status' => 'completed',

                'total' => 1,

                'processed' => 1,

                'changed' => 0,

                'unchanged' => 1,

                'failed' => 0,

                'started_at' => now()
                    ->subHour(),

                'completed_at' => now()
                    ->subMinutes(
                        30
                    ),
            ]);

    $item =
        HubSpotRefreshItem::query()
            ->create([
                'run_id' => $run->id,

                'company_id' => $company->id,

                /*
                 * Estado propositalmente inconsistente
                 * para provar que uma rodada encerrada
                 * nunca será ressuscitada.
                 */
                'status' => 'running',

                'started_at' => now()
                    ->subHour(),
            ]);

    DB::table(
        'hubspot_refresh_items'
    )
        ->where(
            'id',
            $item->id
        )
        ->update([
            'updated_at' => now()
                ->subMinutes(
                    30
                ),
        ]);

    $this
        ->artisan(
            'hubspot:bulk-recover',
            [
                '--minutes' => 20,
            ]
        )
        ->expectsOutput(
            'Itens stale selecionados: 0 | reenfileirados: 0'
        )
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
