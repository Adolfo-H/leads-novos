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

it(
    'keeps recent prospected companies in SDR while export research respects cooldown',
    function (): void {
        config([
            'prospector.crm.reprospecting_after_days' => 180,
        ]);

        /*
         * Usamos 15 dias para ficar claramente
         * dentro da faixa de contato recente,
         * sem depender do limite exato de 30 dias.
         */
        $company =
            reprospectingIntegrationCompany(
                15
            );

        $score =
            app(
                SdrScoringService::class
            )->recalculate(
                $company
            );

        /*
         * Regra atual:
         *
         * empresa prospectada não desaparece
         * da fila SDR.
         */
        expect(
            $score->is_eligible
        )->toBeTrue();

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
            data_get(
                $score->metadata,
                'commercial_status'
            )
        )->toBe(
            'known'
        );

        expect(
            data_get(
                $score->metadata,
                'work_status'
            )
        )->toBe(
            'contacting'
        );

        /*
         * Pesquisa externa continua bloqueada
         * enquanto estiver dentro do cooldown.
         */
        $research =
            app(
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
    }
);

it(
    'returns old prospected companies to reprospecting with the current scoring model',
    function (): void {
        config([
            'prospector.crm.reprospecting_after_days' => 180,
        ]);

        $company =
            reprospectingIntegrationCompany(
                220
            );

        $score =
            app(
                SdrScoringService::class
            )->recalculate(
                $company
            );

        /*
         * Score atual:
         *
         * ICP 90 x 60% = 54
         * Reprospecção comercial = 12
         * Acompanhamento reprospecção = 10
         *
         * Total = 76.
         */
        expect(
            $score->score
        )->toBe(76);

        expect(
            $score->is_eligible
        )->toBeTrue();

        expect(
            $score->priority
        )->toBe(
            'high'
        );

        expect(
            $score->is_provisional
        )->toBeTrue();

        expect(
            data_get(
                $score->metadata,
                'commercial_status'
            )
        )->toBe(
            'reprospecting'
        );

        expect(
            data_get(
                $score->metadata,
                'work_status'
            )
        )->toBe(
            'reprospecting'
        );

        $research =
            app(
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
    }
);

it(
    'keeps clients blocked in SDR and export research',
    function (): void {
        $company =
            reprospectingIntegrationCompany(
                500
            );

        $company
            ->crmCheck()
            ->update([
                'status' => 'client',
            ]);

        $company->unsetRelation(
            'crmCheck'
        );

        $score =
            app(
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

        $research =
            app(
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
            'crm_client'
        );
    }
);

it(
    'keeps active opportunities in SDR but blocks export research',
    function (): void {
        $company =
            reprospectingIntegrationCompany(
                15
            );

        $company
            ->crmCheck()
            ->update([
                'status' => 'opportunity',
            ]);

        $company->unsetRelation(
            'crmCheck'
        );

        $score =
            app(
                SdrScoringService::class
            )->recalculate(
                $company
            );

        /*
         * Oportunidade continua no Leads/SDR,
         * pois ainda existe trabalho comercial.
         */
        expect(
            $score->is_eligible
        )->toBeTrue();

        expect(
            $score->priority
        )->not->toBe(
            'blocked'
        );

        /*
         * Mas não gastamos pesquisa externa
         * em uma oportunidade já ativa.
         */
        $research =
            app(
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
            'crm_opportunity'
        );
    }
);
