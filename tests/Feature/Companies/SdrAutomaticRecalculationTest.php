<?php

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Services\CompanyService;
use App\Services\CrmCheckService;
use App\Services\ExportIntelligenceService;
use App\Services\IcpScoringService;

it('recalculates SDR score automatically after ICP calculation', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Empresa Auto Score ICP',

            'share_capital' => 5000000,

            'size_code' => '05',

            'size_description' => 'Demais',

            'legal_nature_code' => '2143',

            'legal_nature_description' => 'Cooperativa',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'state' => 'MT',

            'municipality_name' => 'Sorriso',
        ],
        [
            [
                'code' => '4622200',

                'description' => 'Comércio atacadista de soja',

                'is_primary' => true,
            ],
        ]
    );

    app(
        IcpScoringService::class
    )->calculate(
        $company
    );

    $score =
        $company
            ->sdrScore()
            ->firstOrFail();

    expect(
        $score->score
    )->toBe(30);

    expect(
        data_get(
            $score->metadata,
            'icp_grade'
        )
    )->toBe('A');

    expect(
        $score->is_provisional
    )->toBeTrue();
});

it('recalculates SDR score automatically after CRM change', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '11223344',

            'corporate_name' => 'Empresa Auto Score CRM',
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => 90,

            'grade' => 'A',

            'label' => 'Alta aderência',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    $provider =
        new class implements CrmCompanyProvider
        {
            public function name(): string
            {
                return 'fake-auto-score';
            }

            public function findCompany(
                Company $company
            ): array {
                return [
                    'found' => true,

                    'external_id' => 'crm-auto-1',

                    'name' => 'Empresa Auto Score CRM',

                    'domain' => 'autoscore.test',

                    'lifecycle_stage' => 'customer',

                    'owner_id' => null,

                    'contacted_count' => 5,

                    'associated_deals_count' => 1,

                    'deals' => [
                        [
                            'id' => 'deal-open',

                            'name' => 'Negócio ativo',

                            'stage_id' => 'proposal',

                            'stage_label' => 'Proposta apresentada',

                            'pipeline_id' => 'default',

                            'is_closed' => false,

                            'is_closed_won' => false,

                            'closed_at' => null,
                        ],
                    ],

                    'last_contacted_at' => '2026-09-01T10:00:00Z',

                    'matched_by' => 'domain',

                    'matched_value' => 'autoscore.test',

                    'external_url' => null,

                    'metadata' => [],
                ];
            }
        };

    app(
        CrmCheckService::class
    )->check(
        $company,
        $provider
    );

    $score =
        $company
            ->sdrScore()
            ->firstOrFail();

    expect(
        $score->priority
    )->toBe(
        'blocked'
    );

    expect(
        $score->blocked_reason
    )->toBe(
        'Empresa já possui oportunidade'
    );

    expect(
        data_get(
            $score->metadata,
            'crm_status'
        )
    )->toBe(
        'opportunity'
    );
});

it('recalculates SDR score automatically after export classification', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '55667788',

            'corporate_name' => 'Empresa Auto Score Export',
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => 90,

            'grade' => 'A',

            'label' => 'Alta aderência',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
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

    app(
        ExportIntelligenceService::class
    )->classify(
        company: $company,
        dimension: 'direct',
        status: 'yes',
        confidence: 100,
    );

    $score =
        $company
            ->sdrScore()
            ->firstOrFail();

    /*
     * ICP A     = 30
     * CRM novo  = 10
     * Direta    = 25
     * ----------------
     * Total     = 65
     */
    expect(
        $score->score
    )->toBe(65);

    expect(
        $score->priority
    )->toBe(
        'medium'
    );

    expect(
        $score->is_provisional
    )->toBeTrue();
});
