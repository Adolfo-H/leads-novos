<?php

use App\Models\Company;
use App\Models\ImportBatch;
use App\Services\ProspectingRunSummaryService;

it('summarizes prospecting runs with commercial outcomes', function () {
    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',

            'total_rows' => 3,

            'processed_rows' => 3,

            'metadata' => [
                'prospecting' => [
                    'discovered_count' => 50,

                    'known_roots_count' => 5,

                    'new_candidates_count' => 3,

                    'filters' => [
                        'states' => [
                            'MT',
                            'GO',
                        ],
                    ],
                ],
            ],
        ]);

    $lead =
        Company::query()->create([
            'cnpj_root' => '11111111',

            'corporate_name' => 'Empresa Lead',
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
            'score' => 90,

            'priority' => 'very_high',

            'label' => 'Prioridade muito alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $lead
        ->exportIntelligence()
        ->create([
            'direct_status' => 'yes',

            'direct_confidence' => 90,

            'direct_confirmed' => false,

            'research_status' => 'completed',

            'metadata' => [],
        ]);

    $blocked =
        Company::query()->create([
            'cnpj_root' => '22222222',

            'corporate_name' => 'Empresa Bloqueada',
        ]);

    $blocked
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'opportunity',

            'contacted_count' => 0,

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

    $batch->items()->create([
        'row_number' => 3,

        'raw_cnpj' => '33333333000100',

        'normalized_cnpj' => '33333333000100',

        'status' => 'failed',

        'error_message' => 'Falha de teste',
    ]);

    $runs =
        app(
            ProspectingRunSummaryService::class
        )->recent();

    expect(
        $runs
    )->toHaveCount(1);

    $run =
        $runs[0];

    expect(
        $run[
            'discovered_count'
        ]
    )->toBe(50);

    expect(
        $run[
            'known_count'
        ]
    )->toBe(5);

    expect(
        $run[
            'new_candidates_count'
        ]
    )->toBe(3);

    expect(
        $run[
            'lead_count'
        ]
    )->toBe(1);

    expect(
        $run[
            'crm_blocked_count'
        ]
    )->toBe(1);

    expect(
        $run[
            'researched_count'
        ]
    )->toBe(1);

    expect(
        $run[
            'export_identified_count'
        ]
    )->toBe(1);

    expect(
        $run[
            'failed_count'
        ]
    )->toBe(1);
});
