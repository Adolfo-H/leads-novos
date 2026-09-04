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
 * @param  array<string, mixed>  $overrides
 * @return array{
 *     id: string,
 *     name: string|null,
 *     stage_id: string|null,
 *     stage_label: string|null,
 *     pipeline_id: string|null,
 *     is_closed: bool,
 *     is_closed_won: bool,
 *     closed_at: string|null
 * }
 */
function crmDeal(
    array $overrides = []
): array {
    return array_merge(
        [
            'id' => 'deal-1',

            'name' => 'Negócio Teste',

            'stage_id' => 'stage-test',

            'stage_label' => 'Prospecto',

            'pipeline_id' => 'default',

            'is_closed' => false,

            'is_closed_won' => false,

            'closed_at' => null,
        ],
        $overrides
    );
}

/**
 * @param  array<string, mixed>  $result
 */
function fakeCrmProvider(
    array $result
): CrmCompanyProvider {
    return new class($result) implements CrmCompanyProvider
    {
        /**
         * @param  array<string, mixed>  $result
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
            /** @var array{
             *     found: bool,
             *     external_id: string|null,
             *     name: string|null,
             *     domain: string|null,
             *     lifecycle_stage: string|null,
             *     owner_id: string|null,
             *     contacted_count: int,
             *     associated_deals_count: int,
             *     deals: list<array{
             *         id: string,
             *         name: string|null,
             *         stage_id: string|null,
             *         stage_label: string|null,
             *         pipeline_id: string|null,
             *         is_closed: bool,
             *         is_closed_won: bool,
             *         closed_at: string|null
             *     }>,
             *     last_contacted_at: string|null,
             *     matched_by: string|null,
             *     matched_value: string|null,
             *     external_url: string|null,
             *     metadata: array<string, mixed>
             * } $result
             */
            $result =
                $this->result;

            return $result;
        }
    };
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
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

            'deals' => [],

            'last_contacted_at' => null,

            'matched_by' => 'domain',

            'matched_value' => 'cooperativateste.com.br',

            'external_url' => 'https://example.com/company/123456',

            'metadata' => [],
        ],
        $overrides
    );
}

it('does not classify lifecycle customer as client by itself', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'lifecycle_stage' => 'customer',
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'known'
    );

    expect(
        $check->lifecycle_stage
    )->toBe(
        'customer'
    );

    expect(
        data_get(
            $check->metadata,
            'lifecycle_used_for_status'
        )
    )->toBeFalse();
});

it('classifies a company with a won deal as client', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'associated_deals_count' => 1,

                'deals' => [
                    crmDeal([
                        'stage_label' => 'Negócio fechado',

                        'is_closed' => true,

                        'is_closed_won' => true,
                    ]),
                ],
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'client'
    );

    expect(
        data_get(
            $check->metadata,
            'deal_summary.won'
        )
    )->toBe(1);
});

it('classifies a company with an active deal as opportunity', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'associated_deals_count' => 1,

                'deals' => [
                    crmDeal([
                        'stage_label' => 'Proposta apresentada',

                        'is_closed' => false,

                        'is_closed_won' => false,
                    ]),
                ],
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'opportunity'
    );

    expect(
        data_get(
            $check->metadata,
            'deal_summary.active'
        )
    )->toBe(1);
});

it('classifies only closed lost deals as prospected', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'associated_deals_count' => 2,

                'deals' => [
                    crmDeal([
                        'id' => 'deal-recusado',

                        'stage_label' => 'Recusado',

                        'is_closed' => true,

                        'is_closed_won' => false,
                    ]),

                    crmDeal([
                        'id' => 'deal-cancelado',

                        'stage_label' => 'Cancelado',

                        'is_closed' => true,

                        'is_closed_won' => false,
                    ]),
                ],
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'prospected'
    );

    expect(
        data_get(
            $check->metadata,
            'deal_summary.closed_lost'
        )
    )->toBe(2);
});

it('does not classify deal count alone as active opportunity', function () {
    $company =
        crmCompanyForTest();

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'associated_deals_count' => 4,

                'deals' => [],
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'prospected'
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
                'associated_deals_count' => 1,

                'deals' => [
                    crmDeal([
                        'is_closed' => true,

                        'is_closed_won' => true,

                        'stage_label' => 'Negócio fechado',
                    ]),
                ],
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
    )->toBe(
        'client'
    );
});

it('prioritizes the ExportControl customer registry over HubSpot status', function () {
    $company =
        crmCompanyForTest();

    CustomerRegistryEntry::query()
        ->create([
            'cnpj_root' => $company->cnpj_root,

            'cnpj' => $company
                ->establishments()
                ->firstOrFail()
                ->cnpj,

            'corporate_name' => $company
                ->corporate_name,

            'normalized_name' => $company
                ->normalized_name,

            'source' => 'exportcontrol-clientes',

            'enabled' => true,
        ]);

    $check = app(
        CrmCheckService::class
    )->check(
        $company,
        fakeCrmProvider(
            crmResult([
                'lifecycle_stage' => 'customer',

                'associated_deals_count' => 1,

                'deals' => [
                    crmDeal([
                        'stage_label' => 'Recusado',

                        'is_closed' => true,

                        'is_closed_won' => false,
                    ]),
                ],
            ])
        )
    );

    expect(
        $check->status
    )->toBe(
        'client'
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
        'prospected'
    );

    expect(
        data_get(
            $check->metadata,
            'crm_conflict'
        )
    )->toBeTrue();
});
