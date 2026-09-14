<?php

use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\ProspectingRunDetailService;
use Livewire\Livewire;

it('protects a prospecting run from guests', function () {
    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',
        ]);

    $this
        ->get(
            route(
                'prospecting.show',
                $batch
            )
        )
        ->assertRedirect(
            route('login')
        );
});

it('renders company outcomes from a prospecting run', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',

            'total_rows' => 2,

            'processed_rows' => 2,

            'metadata' => [
                'prospecting' => [
                    'discovered_count' => 20,

                    'known_roots_count' => 5,

                    'new_candidates_count' => 2,

                    'filters' => [
                        'states' => [
                            'MT',
                            'GO',
                        ],

                        'cnaes' => [
                            '4622200',
                        ],
                    ],
                ],
            ],
        ]);

    $lead =
        Company::query()->create([
            'cnpj_root' => '11111111',

            'corporate_name' => 'Empresa Lead da Rodada',
        ]);

    $lead
        ->icpScore()
        ->create([
            'score' => 90,

            'grade' => 'A',

            'label' => 'Alta aderência',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    $lead
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'not_found',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $lead
        ->sdrScore()
        ->create([
            'score' => 85,

            'priority' => 'very_high',

            'label' => 'Prioridade muito alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $blocked =
        Company::query()->create([
            'cnpj_root' => '22222222',

            'corporate_name' => 'Empresa Oportunidade da Rodada',
        ]);

    $blocked
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'opportunity',

            'contacted_count' => 1,

            'associated_deals_count' => 1,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $blocked
        ->sdrScore()
        ->create([
            'score' => 0,

            'priority' => 'blocked',

            'label' => 'Não priorizar',

            'is_eligible' => false,

            'is_provisional' => false,

            'blocked_reason' => 'Empresa já possui oportunidade',

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $batch->items()->create([
        'row_number' => 1,

        'raw_cnpj' => '11111111000100',

        'normalized_cnpj' => '11111111000100',

        'status' => 'completed',

        'company_id' => $lead->id,
    ]);

    $batch->items()->create([
        'row_number' => 2,

        'raw_cnpj' => '22222222000100',

        'normalized_cnpj' => '22222222000100',

        'status' => 'completed',

        'company_id' => $blocked->id,
    ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'prospecting.show',
                $batch
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Empresa Lead da Rodada'
        )
        ->assertSee(
            'Empresa Oportunidade da Rodada'
        )
        ->assertSee(
            'Novo no CRM'
        )
        ->assertSee(
            'Oportunidade'
        )
        ->assertSee(
            'Lead'
        )
        ->assertSee(
            'Empresa já possui oportunidade'
        );
});

it('rejects non prospecting batches', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'manual',

            'status' => 'completed',
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'prospecting.show',
                $batch
            )
        )
        ->assertNotFound();
});

it('filters companies inside a prospecting run', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',

            'total_rows' => 2,

            'processed_rows' => 2,
        ]);

    $lead =
        Company::query()->create([
            'cnpj_root' => '33333333',

            'corporate_name' => 'Lead Visível',
        ]);

    $lead
        ->sdrScore()
        ->create([
            'score' => 80,

            'priority' => 'high',

            'label' => 'Prioridade alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $blocked =
        Company::query()->create([
            'cnpj_root' => '44444444',

            'corporate_name' => 'Bloqueada Visível',
        ]);

    $blocked
        ->sdrScore()
        ->create([
            'score' => 0,

            'priority' => 'blocked',

            'label' => 'Não priorizar',

            'is_eligible' => false,

            'is_provisional' => false,

            'blocked_reason' => 'Bloqueio comercial',

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $batch->items()->create([
        'row_number' => 1,

        'raw_cnpj' => '33333333000100',

        'normalized_cnpj' => '33333333000100',

        'status' => 'completed',

        'company_id' => $lead->id,
    ]);

    $batch->items()->create([
        'row_number' => 2,

        'raw_cnpj' => '44444444000100',

        'normalized_cnpj' => '44444444000100',

        'status' => 'completed',

        'company_id' => $blocked->id,
    ]);

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::prospecting.show',
            [
                'batch' => $batch,
            ]
        )
        ->assertSee(
            'Lead Visível'
        )
        ->assertSee(
            'Bloqueada Visível'
        )
        ->set(
            'filter',
            'leads'
        )
        ->assertSee(
            'Lead Visível'
        )
        ->assertDontSee(
            'Bloqueada Visível'
        )
        ->set(
            'filter',
            'blocked'
        )
        ->assertDontSee(
            'Lead Visível'
        )
        ->assertSee(
            'Bloqueada Visível'
        );
});

it('filters companies with identified export activity', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',

            'total_rows' => 2,

            'processed_rows' => 2,
        ]);

    $exporter =
        Company::query()->create([
            'cnpj_root' => '55555555',

            'corporate_name' => 'Exportadora Identificada',
        ]);

    $exporter
        ->exportIntelligence()
        ->create([
            'direct_status' => 'yes',

            'direct_confidence' => 90,

            'direct_confirmed' => false,

            'research_status' => 'completed',

            'metadata' => [],
        ]);

    $nonExporter =
        Company::query()->create([
            'cnpj_root' => '66666666',

            'corporate_name' => 'Empresa Sem Exportação',
        ]);

    $nonExporter
        ->exportIntelligence()
        ->create([
            'direct_status' => 'uncertain',

            'direct_confidence' => 0,

            'direct_confirmed' => false,

            'research_status' => 'completed',

            'metadata' => [],
        ]);

    $batch->items()->create([
        'row_number' => 1,

        'raw_cnpj' => '55555555000100',

        'normalized_cnpj' => '55555555000100',

        'status' => 'completed',

        'company_id' => $exporter->id,
    ]);

    $batch->items()->create([
        'row_number' => 2,

        'raw_cnpj' => '66666666000100',

        'normalized_cnpj' => '66666666000100',

        'status' => 'completed',

        'company_id' => $nonExporter->id,
    ]);

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::prospecting.show',
            [
                'batch' => $batch,
            ]
        )
        ->assertSee(
            'Exportadora Identificada'
        )
        ->assertSee(
            'Empresa Sem Exportação'
        )
        ->set(
            'filter',
            'exporters'
        )
        ->assertSee(
            'Exportadora Identificada'
        )
        ->assertDontSee(
            'Empresa Sem Exportação'
        );
});

it('builds the exact HubSpot company record url', function () {
    config([
        'services.hubspot.portal_id' => '12345678',
    ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',

            'total_rows' => 1,

            'processed_rows' => 1,
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '99999999',

            'corporate_name' => 'Empresa HubSpot Direto',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'prospected',

            'external_id' => '31043441589',

            /*
             * Mesmo sem external_url salva,
             * o Prospector deve conseguir
             * montar a URL direta.
             */
            'external_url' => null,

            'contacted_count' => 1,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $batch
        ->items()
        ->create([
            'row_number' => 1,

            'raw_cnpj' => '99999999000100',

            'normalized_cnpj' => '99999999000100',

            'status' => 'completed',

            'company_id' => $company->id,
        ]);

    $detail =
        app(
            ProspectingRunDetailService::class
        )->build(
            $batch
        );

    expect(
        $detail[
            'items'
        ][0][
            'hubspot_url'
        ]
    )->toBe(
        'https://app.hubspot.com/contacts/12345678/record/0-2/31043441589'
    );
});

it('segments prospecting companies by crm commercial status', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',

            'total_rows' => 4,

            'processed_rows' => 4,
        ]);

    $statuses = [
        [
            'root' => '10101010',

            'name' => 'Empresa Nova CRM',

            'status' => 'not_found',
        ],

        [
            'root' => '20202020',

            'name' => 'Empresa Reprospecção',

            'status' => 'prospected',
        ],

        [
            'root' => '30303030',

            'name' => 'Empresa Oportunidade',

            'status' => 'opportunity',
        ],

        [
            'root' => '40404040',

            'name' => 'Empresa Cliente',

            'status' => 'client',
        ],
    ];

    foreach (
        $statuses as $index => $data
    ) {
        $company =
            Company::query()->create([
                'cnpj_root' => $data[
                        'root'
                    ],

                'corporate_name' => $data[
                        'name'
                    ],
            ]);

        $company
            ->crmCheck()
            ->create([
                'provider' => 'hubspot',

                'status' => $data[
                        'status'
                    ],

                'contacted_count' => $data[
                        'status'
                    ] === 'not_found'
                        ? 0
                        : 1,

                'associated_deals_count' => in_array(
                    $data[
                        'status'
                    ],
                    [
                        'opportunity',
                        'client',
                    ],
                    true
                )
                        ? 1
                        : 0,

                'metadata' => [],

                'checked_at' => now(),
            ]);

        $batch
            ->items()
            ->create([
                'row_number' => $index + 1,

                'raw_cnpj' => $data[
                        'root'
                    ]
                    .'000100',

                'normalized_cnpj' => $data[
                        'root'
                    ]
                    .'000100',

                'status' => 'completed',

                'company_id' => $company->id,
            ]);
    }

    $component =
        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::prospecting.show',
                [
                    'batch' => $batch,
                ]
            );

    $component
        ->set(
            'filter',
            'new_crm'
        )
        ->assertSee(
            'Empresa Nova CRM'
        )
        ->assertDontSee(
            'Empresa Reprospecção'
        )
        ->assertDontSee(
            'Empresa Oportunidade'
        )
        ->assertDontSee(
            'Empresa Cliente'
        );

    $component
        ->set(
            'filter',
            'reprospecting'
        )
        ->assertDontSee(
            'Empresa Nova CRM'
        )
        ->assertSee(
            'Empresa Reprospecção'
        )
        ->assertDontSee(
            'Empresa Oportunidade'
        )
        ->assertDontSee(
            'Empresa Cliente'
        );

    $component
        ->set(
            'filter',
            'opportunities'
        )
        ->assertDontSee(
            'Empresa Nova CRM'
        )
        ->assertDontSee(
            'Empresa Reprospecção'
        )
        ->assertSee(
            'Empresa Oportunidade'
        )
        ->assertDontSee(
            'Empresa Cliente'
        );

    $component
        ->set(
            'filter',
            'clients'
        )
        ->assertDontSee(
            'Empresa Nova CRM'
        )
        ->assertDontSee(
            'Empresa Reprospecção'
        )
        ->assertDontSee(
            'Empresa Oportunidade'
        )
        ->assertSee(
            'Empresa Cliente'
        );
});
