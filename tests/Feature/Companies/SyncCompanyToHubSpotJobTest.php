<?php

use App\Jobs\SyncCompanyToHubSpot;
use App\Models\Company;
use App\Services\HubSpotLeadEligibilityService;
use App\Services\HubSpotLeadSyncService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

function makeQueuedHubSpotCandidate(): Company
{
    $company =
        Company::query()->create([
            'cnpj_root' => '77777777',
            'corporate_name' => 'EMPRESA TESTE JOB HUBSPOT',
        ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 90,
            'priority' => 'high',
            'label' => 'Alta prioridade',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'metadata' => [],
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',
            'status' => 'not_found',
            'checked_at' => now(),
        ]);

    return $company;
}

it('has its own retry policy for HubSpot synchronization', function () {
    $job =
        new SyncCompanyToHubSpot(
            1
        );

    expect(
        $job->tries
    )->toBe(4);

    expect(
        $job->timeout
    )->toBe(180);

    expect(
        $job->backoff()
    )->toBe([
        60,
        300,
        900,
    ]);
});

it('lets HubSpot failures reach the queue retry mechanism', function () {
    config([
        'services.hubspot.lead_sync_enabled' => true,
        'services.hubspot.lead_min_score' => 60,
        'services.hubspot.access_token' => 'test-token',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    $company =
        makeQueuedHubSpotCandidate();

    Http::fake([
        'https://api.hubapi.com/*' => Http::response(
            [],
            500
        ),
    ]);

    $job =
        new SyncCompanyToHubSpot(
            $company->id
        );

    expect(
        fn () => $job->handle(
            app(
                HubSpotLeadEligibilityService::class
            ),
            app(
                HubSpotLeadSyncService::class
            ),
        )
    )->toThrow(
        RuntimeException::class
    );
});
