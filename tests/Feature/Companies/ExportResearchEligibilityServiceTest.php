<?php

use App\Models\Company;
use App\Services\ExportResearchEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function eligibilityCompany(
    string $crmStatus = 'not_found',
    string $grade = 'A',
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => fake()
                ->unique()
                ->numerify(
                    '########'
                ),

            'corporate_name' => 'Empresa Elegibilidade '
                .fake()->uuid(),
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => $grade === 'A'
                    ? 90
                    : (
                        $grade === 'B'
                            ? 70
                            : 40
                    ),

            'grade' => $grade,

            'label' => 'Teste',

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

it('allows a new high ICP company to be researched', function () {
    $company =
        eligibilityCompany(
            crmStatus: 'not_found',
            grade: 'A',
        );

    $result = app(
        ExportResearchEligibilityService::class
    )->evaluate(
        $company
    );

    expect(
        $result['eligible']
    )->toBeTrue();

    expect(
        $result['reason']
    )->toBe(
        'eligible'
    );
});

it('blocks an existing client', function () {
    $company =
        eligibilityCompany(
            crmStatus: 'client',
            grade: 'A',
        );

    $result = app(
        ExportResearchEligibilityService::class
    )->evaluate(
        $company
    );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'crm_client'
    );
});

it('blocks an existing opportunity', function () {
    $company =
        eligibilityCompany(
            crmStatus: 'opportunity',
            grade: 'A',
        );

    $result = app(
        ExportResearchEligibilityService::class
    )->evaluate(
        $company
    );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'crm_opportunity'
    );
});

it('blocks a low ICP company', function () {
    $company =
        eligibilityCompany(
            crmStatus: 'not_found',
            grade: 'C',
        );

    $result = app(
        ExportResearchEligibilityService::class
    )->evaluate(
        $company
    );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'low_icp'
    );
});

it('blocks a company researched recently', function () {
    $company =
        eligibilityCompany(
            crmStatus: 'not_found',
            grade: 'A',
        );

    $company
        ->exportIntelligence()
        ->create([
            'direct_status' => 'uncertain',

            'direct_confidence' => 50,

            'direct_confirmed' => false,

            'indirect_status' => 'uncertain',

            'indirect_confidence' => 50,

            'indirect_confirmed' => false,

            'trading_status' => 'uncertain',

            'trading_confidence' => 50,

            'trading_confirmed' => false,

            'researched_at' => now()->subDays(5),

            'metadata' => [],
        ]);

    $result = app(
        ExportResearchEligibilityService::class
    )->evaluate(
        $company
    );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'recently_researched'
    );
});
