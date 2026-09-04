<?php

use App\Models\Company;
use App\Services\SdrScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sdrCompany(
    string $crmStatus = 'not_found',
    string $grade = 'A',
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => fake()
                ->unique()
                ->numerify('########'),

            'corporate_name' => 'Empresa Score SDR '
                .fake()->uuid(),
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => $grade === 'A'
                    ? 100
                    : (
                        $grade === 'B'
                            ? 70
                            : 40
                    ),

            'grade' => $grade,

            'label' => 'Teste SDR',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => $crmStatus,

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    return $company;
}

it('blocks an existing client regardless of ICP', function () {
    $company =
        sdrCompany(
            crmStatus: 'client',
            grade: 'A',
        );

    $score = app(
        SdrScoringService::class
    )->recalculate(
        $company
    );

    expect($score->score)
        ->toBe(0);

    expect($score->priority)
        ->toBe('blocked');

    expect($score->is_eligible)
        ->toBeFalse();

    expect($score->label)
        ->toBe('Não priorizar');
});

it('gives very high priority to a strong new exporter', function () {
    $company =
        sdrCompany(
            crmStatus: 'not_found',
            grade: 'A',
        );

    $company
        ->exportIntelligence()
        ->create([
            'direct_status' => 'yes',

            'direct_confidence' => 90,

            'direct_confirmed' => false,

            'indirect_status' => 'yes',

            'indirect_confidence' => 80,

            'indirect_confirmed' => false,

            'trading_status' => 'yes',

            'trading_confidence' => 70,

            'trading_confirmed' => false,

            'metadata' => [],
        ]);

    $score = app(
        SdrScoringService::class
    )->recalculate(
        $company
    );

    /*
     * ICP     30
     * CRM     10
     * Direta  23
     * Indireta20
     * Trading  7
     * ----------
     * Total    90
     */
    expect($score->score)
        ->toBe(90);

    expect($score->priority)
        ->toBe('very_high');

    expect($score->label)
        ->toBe(
            'Prioridade muito alta'
        );

    expect($score->is_provisional)
        ->toBeFalse();
});

it('keeps the score provisional before export research', function () {
    $company =
        sdrCompany(
            crmStatus: 'not_found',
            grade: 'A',
        );

    $score = app(
        SdrScoringService::class
    )->recalculate(
        $company
    );

    expect($score->score)
        ->toBe(40);

    expect($score->priority)
        ->toBe('low');

    expect($score->is_provisional)
        ->toBeTrue();
});

it('updates the same SDR score instead of duplicating it', function () {
    $company =
        sdrCompany();

    $service = app(
        SdrScoringService::class
    );

    $service->recalculate(
        $company
    );

    $service->recalculate(
        $company
    );

    expect(
        $company
            ->sdrScore()
            ->count()
    )->toBe(1);
});
