<?php

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Services\HubSpotLeadSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

function hubSpotRecoveryCompany(
    string $root,
    string $name,
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => $root,
            'corporate_name' => $name,
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
            'contacted_count' => 0,
            'associated_deals_count' => 0,
            'metadata' => [],
            'checked_at' => now(),
        ]);

    return $company;
}

/**
 * @param  array<string, mixed>  $response
 */
function useHubSpotRecoveryProvider(
    array $response
): void {
    app()->instance(
        CrmCompanyProvider::class,
        new class($response) implements CrmCompanyProvider
        {
            /**
             * @param  array<string, mixed>  $response
             */
            public function __construct(
                private array $response,
            ) {}

            public function name(): string
            {
                return 'fake-hubspot';
            }

            public function findCompany(
                Company $company
            ): array {
                return $this->response;
            }
        }
    );
}

function hubSpotFoundResponse(
    string $companyId,
    array $deals = [],
): array {
    return [
        'found' => true,
        'external_id' => $companyId,
        'name' => 'Empresa',
        'domain' => null,
        'lifecycle_stage' => null,
        'owner_id' => null,
        'contacted_count' => 0,
        'associated_deals_count' => count($deals),
        'deals' => $deals,
        'last_contacted_at' => null,
        'matched_by' => 'name',
        'matched_value' => 'Empresa',
        'external_url' => null,
        'metadata' => [],
    ];
}

beforeEach(function () {
    config([
        'services.hubspot.lead_min_score' => 60,
        'services.hubspot.access_token' => 'test-token',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
        'services.hubspot.lead_pipeline' => 'default',
        'services.hubspot.lead_initial_stage' => 'appointmentscheduled',
    ]);
});

it('recovers a remotely created company during a partial retry', function () {
    $company =
        hubSpotRecoveryCompany(
            '71111111',
            'EMPRESA RECUPERACAO COMPANY'
        );

    $company
        ->hubSpotLead()
        ->create([
            'pipeline_id' => 'default',
            'deal_stage_id' => 'appointmentscheduled',
            'sync_error' => 'Resposta anterior perdida',
            'metadata' => [],
        ]);

    useHubSpotRecoveryProvider(
        hubSpotFoundResponse(
            'company-123'
        )
    );

    Http::fake([
        'https://api.hubapi.com/crm/v3/objects/deals' => Http::response(
            [
                'id' => 'deal-456',
            ],
            201
        ),

        '*' => Http::response(
            [],
            200
        ),
    ]);

    $sync =
        app(
            HubSpotLeadSyncService::class
        )->sync(
            $company->fresh()
        );

    expect(
        $sync->hubspot_company_id
    )->toBe(
        'company-123'
    );

    expect(
        $sync->hubspot_deal_id
    )->toBe(
        'deal-456'
    );

    expect(
        $sync->synced_at
    )->not->toBeNull();

    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'POST'
            && str_contains(
                $request->url(),
                '/crm/v3/objects/companies'
            )
    );
});

it('recovers an already created deal instead of creating a duplicate', function () {
    $company =
        hubSpotRecoveryCompany(
            '72222222',
            'EMPRESA RECUPERACAO DEAL'
        );

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'company-123',
            'pipeline_id' => 'default',
            'deal_stage_id' => 'appointmentscheduled',
            'sync_error' => 'Resposta do deal perdida',
            'metadata' => [],
        ]);

    useHubSpotRecoveryProvider(
        hubSpotFoundResponse(
            'company-123',
            [
                [
                    'id' => 'deal-existing',
                    'name' => 'Prospecção - EMPRESA RECUPERACAO DEAL',
                    'stage_id' => 'appointmentscheduled',
                    'stage_label' => 'Agendamento',
                    'pipeline_id' => 'default',
                    'is_closed' => false,
                    'is_closed_won' => false,
                    'closed_at' => null,
                ],
            ]
        )
    );

    Http::fake([
        '*' => Http::response(
            [],
            200
        ),
    ]);

    $sync =
        app(
            HubSpotLeadSyncService::class
        )->sync(
            $company->fresh()
        );

    expect(
        $sync->hubspot_deal_id
    )->toBe(
        'deal-existing'
    );

    expect(
        $sync->synced_at
    )->not->toBeNull();

    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'POST'
            && str_contains(
                $request->url(),
                '/crm/v3/objects/deals'
            )
    );
});

it('keeps the first attempt conservative when the company already exists', function () {
    $company =
        hubSpotRecoveryCompany(
            '73333333',
            'EMPRESA JA EXISTENTE'
        );

    useHubSpotRecoveryProvider(
        hubSpotFoundResponse(
            'company-existing'
        )
    );

    Http::fake();

    expect(
        fn () => app(
            HubSpotLeadSyncService::class
        )->sync(
            $company
        )
    )->toThrow(
        RuntimeException::class
    );

    Http::assertNothingSent();
});

it('prevents two synchronization flows for the same company', function () {
    $company =
        hubSpotRecoveryCompany(
            '74444444',
            'EMPRESA CONCORRENCIA'
        );

    $lock =
        Cache::lock(
            'hubspot-lead-sync-company-'
            .$company->id,
            240
        );

    expect(
        $lock->get()
    )->toBeTrue();

    try {
        expect(
            fn () => app(
                HubSpotLeadSyncService::class
            )->sync(
                $company
            )
        )->toThrow(
            RuntimeException::class
        );
    } finally {
        $lock->release();
    }
});
