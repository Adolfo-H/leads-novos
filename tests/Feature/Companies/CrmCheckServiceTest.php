<?php

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CustomerRegistryEntry;
use App\Services\CompanyService;
use App\Services\CrmCheckService;

function crmCompanyForTest(): Company
{
    return app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'COOPERATIVA CRM TESTE',

            'legal_nature_code' => '2143',

            'legal_nature_description' => 'Cooperativa',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'state' => 'PR',

            'email' => 'fiscal@cooperativateste.com.br',
        ]
    );
}

/**
 * @param array{
 *     found: bool,
 *     external_id: string|null,
 *     name: string|null,
 *     domain: string|null,
 *     lifecycle_stage: string|null,
 *     owner_id: string|null,
 *     contacted_count: int,
 *     associated_deals_count: int,
 *     last_contacted_at: string|null,
 *     matched_by: string|null,
 *     matched_value: string|null,
 *     external_url: string|null,
 *     metadata: array<string, mixed>
 * } $result
 */
function fakeCrmProvider(
    array $result
): CrmCompanyProvider {
    return new class($result) implements CrmCompanyProvider
    {
        /**
         * @param array{
         *     found: bool,
         *     external_id: string|null,
         *     name: string|null,
         *     domain: string|null,
         *     lifecycle_stage: string|null,
         *     owner_id: string|null,
         *     contacted_count: int,
         *     associated_deals_count: int,
         *     last_contacted_at: string|null,
         *     matched_by: string|null,
         *     matched_value: string|null,
         *     external_url: string|null,
         *     metadata: array<string, mixed>
         * } $result
         */
        public function __construct(
            private readonly array $result
        ) {}

        public function name(): string
        {
            return 'fake-hubspot';
        }

        public function findCompany(
            Company $company
        ): array {
            return $this->result;
        }
    };
}

/**
 * @return array{
 *     found: bool,
 *     external_id: string|null,
 *     name: string|null,
 *     domain: string|null,
 *     lifecycle_stage: string|null,
 *     owner_id: string|null,
 *     contacted_count: int,
 *     associated_deals_count: int,
 *     last_contacted_at: string|null,
 *     matched_by: string|null,
 *     matched_value: string|null,
 *     external_url: string|null,
 *     metadata: array<string, mixed>
 * }
 */
function crmResult(
    array $overrides = []
): array {
    return array_merge(
        [
            'found' => true,

            'external_id' => '123456',

            'name' => 'Cooperativa CRM Teste',

            'domain' => 'cooperativateste.com.br',

            'lifecycle_stage' => null,

            'owner_id' => '999',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'last_contacted_at' => null,

            'matched_by' => 'domain',

            'matched_value' => 'cooperativateste.com.br',

            'external_url' => 'https://example.com/company/123456',

            'metadata' => [],
        ],
        $overrides
    );
}

it('classifies a CRM customer as client', function () {
    $company =
        crmCompanyForTest();

    $provider =
        fakeCrmProvider(
            crmResult([
                'lifecycle_stage' => 'customer',

                'contacted_count' => 170,

                'associated_deals_count' => 6,
            ])
        );

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        $provider
    );

    expect(
        $check->status
    )->toBe('client');

    expect(
        $check->provider
    )->toBe(
        'fake-hubspot'
    );

    expect(
        $check->matched_by
    )->toBe('domain');

    expect(
        $check->contacted_count
    )->toBe(170);
});

it('classifies a company with deals as opportunity', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'associated_deals_count' => 2,
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'opportunity'
    );
});

it('classifies a previously contacted company as prospected', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'contacted_count' => 4,

                'last_contacted_at' => '2026-09-01T14:30:00-03:00',
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'prospected'
    );
});

it('classifies an unknown CRM company as not found', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'found' => false,

                'external_id' => null,

                'name' => null,

                'domain' => null,

                'matched_by' => null,

                'matched_value' => null,

                'external_url' => null,
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'not_found'
    );
});

it('updates the existing CRM check instead of duplicating it', function () {
    $company =
        crmCompanyForTest();

    $service = app(
        CrmCheckService::class
    );

    $service->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'contacted_count' => 1,
            ])
        )
    );

    $service->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'lifecycle_stage' => 'customer',
            ])
        )
    );

    expect(
        $company
            ->crmCheck()
            ->count()
    )->toBe(1);

    expect(
        $company
            ->crmCheck()
            ->firstOrFail()
            ->status
    )->toBe('client');
});

it('prioritizes the ExportControl customer registry over CRM opportunity status', function () {
    $company =
        crmCompanyForTest();

    CustomerRegistryEntry::query()
        ->create([
            'cnpj_root' => $company->cnpj_root,

            'cnpj' => $company
                ->establishments()
                ->firstOrFail()
                ->cnpj,

            'corporate_name' => $company->corporate_name,

            'normalized_name' => $company->normalized_name,

            'source' => 'exportcontrol-clientes',

            'enabled' => true,
        ]);

    $provider =
        fakeCrmProvider(
            crmResult([
                'lifecycle_stage' => 'opportunity',

                'associated_deals_count' => 3,
            ])
        );

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        $provider
    );

    expect(
        $check->status
    )->toBe('client');

    /*
     * Mantemos a informação original
     * do HubSpot para auditoria.
     */
    expect(
        $check->lifecycle_stage
    )->toBe(
        'opportunity'
    );

    expect(
        data_get(
            $check->metadata,
            'status_source'
        )
    )->toBe(
        'exportcontrol_customer_registry'
    );

    expect(
        data_get(
            $check->metadata,
            'crm_reported_status'
        )
    )->toBe(
        'opportunity'
    );

    expect(
        data_get(
            $check->metadata,
            'crm_conflict'
        )
    )->toBeTrue();

    expect(
        data_get(
            $check->metadata,
            'customer_registry.matched_by'
        )
    )->toBe(
        'cnpj_root'
    );
});
