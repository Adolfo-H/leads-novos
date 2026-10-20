<?php

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Services\HubSpotLeadSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

function package12CompanyForSync(): Company
{
    $company = Company::query()->create([
        'cnpj_root' => '81234567',
        'corporate_name' => 'EMPRESA TESTE PACOTE 12',
    ]);

    $company->sdrScore()->create([
        'score' => 90,
        'priority' => 'high',
        'label' => 'Alta prioridade',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'metadata' => [],
    ]);

    $company->crmCheck()->create([
        'provider' => 'hubspot',
        'status' => 'not_found',
        'contacted_count' => 0,
        'associated_deals_count' => 0,
        'metadata' => [],
        'checked_at' => now(),
    ]);

    $company->hubSpotLead()->create([
        'hubspot_company_id' => 'company-local-confirmed',
        'pipeline_id' => 'default',
        'deal_stage_id' => 'appointmentscheduled',
        'metadata' => [],
    ]);

    return $company;
}

function package12UseLinkedCompanyProvider(): void
{
    app()->instance(
        CrmCompanyProvider::class,
        new class implements CrmCompanyProvider
        {
            public function name(): string
            {
                return 'fake-hubspot';
            }

            public function findCompany(Company $company): array
            {
                return [
                    'found' => true,
                    'external_id' => 'company-local-confirmed',
                    'name' => $company->corporate_name,
                    'domain' => null,
                    'lifecycle_stage' => null,
                    'owner_id' => null,
                    'contacted_count' => 0,
                    'associated_deals_count' => 0,
                    'deals' => [],
                    'last_contacted_at' => null,
                    'matched_by' => 'linked_hubspot_id',
                    'matched_value' => 'company-local-confirmed',
                    'external_url' => null,
                    'metadata' => [],
                ];
            }
        }
    );
}

beforeEach(function () {
    config([
        'services.hubspot.access_token' => 'test-token',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
        'services.hubspot.lead_pipeline' => 'default',
        'services.hubspot.lead_initial_stage' => 'appointmentscheduled',
        'services.hubspot.http_retry_delays_ms' => [0, 0, 0],
        'services.hubspot.http_retry_max_delay_ms' => 0,
    ]);

    package12UseLinkedCompanyProvider();
});

it('marks a lost deal creation response uncertain and never retries its POST', function () {
    Http::fake([
        'https://api.hubapi.com/crm/v3/objects/deals' => Http::response(
            ['message' => 'synthetic response lost after acceptance'],
            504
        ),
        '*' => Http::response(['results' => []], 200),
    ]);

    $company = package12CompanyForSync();
    $service = app(HubSpotLeadSyncService::class);

    expect(fn () => $service->sync($company->fresh()))
        ->toThrow(RuntimeException::class);

    $sync = CompanyHubSpotLead::query()
        ->where('company_id', $company->id)
        ->firstOrFail();

    expect($sync->hubspot_deal_id)->toBeNull()
        ->and($sync->hubspot_deal_sync_status)->toBe('remote_result_uncertain')
        ->and($sync->hubspot_deal_sync_error_category)->toBe('http_5xx')
        ->and($sync->hubspot_deal_operation_id)->not->toBeNull()
        ->and($sync->sync_error)->not->toContain('synthetic response lost');

    expect(fn () => $service->sync($company->fresh()))
        ->toThrow(RuntimeException::class);

    $dealPosts = Http::recorded(
        fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/crm/v3/objects/deals')
    );

    expect($dealPosts)->toHaveCount(1);
});

it('persists the created deal before association and retries only the missing association', function () {
    $associationAccepted = false;
    $dealPosts = 0;
    $associationWrites = 0;

    Http::fake(function (Request $request) use (
        &$associationAccepted,
        &$dealPosts,
        &$associationWrites,
    ) {
        if (
            $request->method() === 'POST'
            && str_ends_with($request->url(), '/crm/v3/objects/deals')
        ) {
            $dealPosts++;

            return Http::response(['id' => 'deal-created-once'], 201);
        }

        if (
            $request->method() === 'PUT'
            && str_contains($request->url(), '/associations/default/deals/')
        ) {
            $associationWrites++;
            $associationAccepted = true;

            return Http::response(['message' => 'association response lost'], 500);
        }

        if (
            $request->method() === 'GET'
            && str_contains($request->url(), '/associations/deals')
            && $associationAccepted
        ) {
            return Http::response([
                'results' => [
                    ['toObjectId' => 'deal-created-once'],
                ],
            ], 200);
        }

        return Http::response(['results' => []], 200);
    });

    $company = package12CompanyForSync();
    $service = app(HubSpotLeadSyncService::class);

    expect(fn () => $service->sync($company->fresh()))
        ->toThrow(RuntimeException::class);

    $sync = CompanyHubSpotLead::query()
        ->where('company_id', $company->id)
        ->firstOrFail();

    expect($sync->hubspot_deal_id)->toBe('deal-created-once')
        ->and($sync->hubspot_deal_sync_status)->toBe('completed');

    $service->sync($company->fresh());

    expect($dealPosts)->toBe(1)
        ->and($associationWrites)->toBe(1);
});
