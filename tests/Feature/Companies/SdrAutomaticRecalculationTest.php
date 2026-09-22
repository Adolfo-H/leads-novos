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
    )->toBe(74);

    expect(
        data_get(
            $score->metadata,
            'icp_raw_score'
        )
    )->toBe(90);

    expect(
        data_get(
            $score->metadata,
            'commercial_status'
        )
    )->toBe('new');

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

                    'last_contacted_at' => now()
                        ->subDays(5)
                        ->toIso8601String(),

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
        $score->score
    )->toBe(76);

    expect(
        $score->priority
    )->toBe(
        'high'
    );

    expect(
        $score->blocked_reason
    )->toBeNull();

    expect(
        $score->is_eligible
    )->toBeTrue();

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
     * ICP real 90 x 60% = 54
     * Situação Novo     = 15
     * Acompanhamento    = 5
     * Exportação direta = 4
     * -----------------------
     * Total             = 78
     */
    expect(
        $score->score
    )->toBe(78);

    expect(
        $score->priority
    )->toBe(
        'high'
    );

    expect(
        $score->is_provisional
    )->toBeTrue();
});
