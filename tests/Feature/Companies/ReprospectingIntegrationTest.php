<?php

use App\Models\Company;
use App\Services\ExportResearchEligibilityService;
use App\Services\SdrScoringService;

function reprospectingIntegrationCompany(
    int $daysSinceLastActivity
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => fake()
                ->unique()
                ->numerify('########'),

            'corporate_name' => 'Empresa Reprospecção Integração '
                .fake()->uuid(),
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

            'status' => 'prospected',

            'contacted_count' => 5,

            'associated_deals_count' => 1,

            'last_contacted_at' => now()
                ->subDays(
                    $daysSinceLastActivity
                )
                ->toIso8601String(),

            'metadata' => [
                'deals' => [
                    [
                        'id' => 'deal-lost',

                        'name' => 'Negócio encerrado',

                        'stage_id' => 'closedlost',

                        'stage_label' => 'Recusado',

                        'pipeline_id' => 'default',

                        'is_closed' => true,

                        'is_closed_won' => false,

                        'closed_at' => now()
                            ->subDays(
                                $daysSinceLastActivity
                            )
                            ->toIso8601String(),
                    ],
                ],
            ],

            'checked_at' => now(),
        ]);

    return $company;
}

it('blocks recent prospected companies in SDR and export research', function () {
    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);

    $company =
        reprospectingIntegrationCompany(
            30
        );

    $score = app(
        SdrScoringService::class
    )->recalculate(
        $company
    );

    expect(
        $score->is_eligible
    )->toBeFalse();

    expect(
        $score->priority
    )->toBe(
        'blocked'
    );

    expect(
        data_get(
            $score->metadata,
            'reprospecting.reason'
        )
    )->toBe(
        'cooldown_active'
    );

    $research = app(
        ExportResearchEligibilityService::class
    )->evaluate(
        $company
    );

    expect(
        $research['eligible']
    )->toBeFalse();

    expect(
        $research['reason']
    )->toBe(
        'crm_prospected_cooldown_active'
    );
});

it('returns old prospected companies to SDR and export research', function () {
    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);

    $company =
        reprospectingIntegrationCompany(
            220
        );

    $score = app(
        SdrScoringService::class
    )->recalculate(
        $company
    );

    /*
     * ICP A = 30 pontos.
     *
     * Como já foi prospectada,
     * não recebe o bônus de empresa
     * nova no CRM.
     *
     * Exportação ainda não pesquisada.
     */
    expect(
        $score->score
    )->toBe(30);

    expect(
        $score->is_eligible
    )->toBeTrue();

    expect(
        $score->priority
    )->toBe(
        'low'
    );

    expect(
        $score->is_provisional
    )->toBeTrue();

    expect(
        data_get(
            $score->metadata,
            'reprospecting.reason'
        )
    )->toBe(
        'cooldown_elapsed'
    );

    $research = app(
        ExportResearchEligibilityService::class
    )->evaluate(
        $company
    );

    expect(
        $research['eligible']
    )->toBeTrue();

    expect(
        $research['reason']
    )->toBe(
        'eligible'
    );
});

it('keeps clients and active opportunities blocked regardless of age', function () {
    foreach (
        [
            'client',
            'opportunity',
        ] as $status
    ) {
        $company =
            reprospectingIntegrationCompany(
                500
            );

        $company
            ->crmCheck()
            ->update([
                'status' => $status,
            ]);

        $company->unsetRelation(
            'crmCheck'
        );

        $score = app(
            SdrScoringService::class
        )->recalculate(
            $company
        );

        expect(
            $score->is_eligible
        )->toBeFalse();

        expect(
            $score->priority
        )->toBe(
            'blocked'
        );

        $research = app(
            ExportResearchEligibilityService::class
        )->evaluate(
            $company
        );

        expect(
            $research['eligible']
        )->toBeFalse();

        expect(
            $research['reason']
        )->toBe(
            'crm_'.$status
        );
    }
});
