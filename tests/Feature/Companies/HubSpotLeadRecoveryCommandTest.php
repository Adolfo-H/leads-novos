<?php

use App\Jobs\SyncCompanyToHubSpot;
use App\Models\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flushLocks();
});

afterEach(function () {
    Cache::flushLocks();
});

function companyForHubSpotRecovery(
    string $root
): Company {
    return Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => 'EMPRESA RECOVERY '.$root,
    ]);
}

it('recovers stale incomplete HubSpot synchronizations', function () {
    Queue::fake();

    config([
        'services.hubspot.lead_sync_enabled' => true,
    ]);

    $company =
        companyForHubSpotRecovery(
            '81111111'
        );

    $sync =
        $company
            ->hubSpotLead()
            ->create([
                'pipeline_id' => 'default',
                'deal_stage_id' => 'appointmentscheduled',
                'sync_error' => 'Falha anterior',
                'metadata' => [],
            ]);

    DB::table(
        'company_hubspot_leads'
    )
        ->where(
            'id',
            $sync->id
        )
        ->update([
            'updated_at' => now()
                ->subMinutes(20),
        ]);

    $exitCode =
        Artisan::call(
            'hubspot:leads-recover',
            [
                '--minutes' => 10,
                '--limit' => 50,
            ]
        );

    expect(
        $exitCode
    )->toBe(0);

    Queue::assertPushed(
        SyncCompanyToHubSpot::class,
        fn (
            SyncCompanyToHubSpot $job
        ): bool => $job->companyId
                === $company->id
    );
});

it('does not recover a recent HubSpot synchronization', function () {
    Queue::fake();

    config([
        'services.hubspot.lead_sync_enabled' => true,
    ]);

    $company =
        companyForHubSpotRecovery(
            '82222222'
        );

    $company
        ->hubSpotLead()
        ->create([
            'pipeline_id' => 'default',
            'deal_stage_id' => 'appointmentscheduled',
            'metadata' => [],
        ]);

    Artisan::call(
        'hubspot:leads-recover',
        [
            '--minutes' => 10,
        ]
    );

    Queue::assertNothingPushed();
});

it('does not recover an already completed HubSpot synchronization', function () {
    Queue::fake();

    config([
        'services.hubspot.lead_sync_enabled' => true,
    ]);

    $company =
        companyForHubSpotRecovery(
            '83333333'
        );

    $sync =
        $company
            ->hubSpotLead()
            ->create([
                'hubspot_company_id' => 'company-1',
                'hubspot_deal_id' => 'deal-1',
                'synced_at' => now(),
                'metadata' => [],
            ]);

    DB::table(
        'company_hubspot_leads'
    )
        ->where(
            'id',
            $sync->id
        )
        ->update([
            'updated_at' => now()
                ->subMinutes(20),
        ]);

    Artisan::call(
        'hubspot:leads-recover',
        [
            '--minutes' => 10,
        ]
    );

    Queue::assertNothingPushed();
});

it('does nothing while automatic HubSpot sync is disabled', function () {
    Queue::fake();

    config([
        'services.hubspot.lead_sync_enabled' => false,
    ]);

    Artisan::call(
        'hubspot:leads-recover'
    );

    Queue::assertNothingPushed();
});
