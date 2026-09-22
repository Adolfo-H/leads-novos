<?php

use App\Models\Company;
use App\Services\LeadQualificationScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it(
    'uses the real ICP score instead of collapsing every ICP A into 30 points',
    function (): void {
        $companyA =
            Company::query()
                ->create([
                    'cnpj_root' => '70111111',

                    'corporate_name' => 'Empresa ICP 100',
                ]);

        $companyA
            ->icpScore()
            ->create([
                'score' => 100,

                'grade' => 'A',

                'label' => 'Alta aderência',

                'version' => 'test',

                'factors' => [],

                'calculated_at' => now(),
            ]);

        $companyA
            ->crmCheck()
            ->create([
                'provider' => 'hubspot',

                'status' => 'not_found',

                'contacted_count' => 0,

                'associated_deals_count' => 0,

                'metadata' => [],

                'checked_at' => now(),
            ]);

        $companyB =
            Company::query()
                ->create([
                    'cnpj_root' => '70222222',

                    'corporate_name' => 'Empresa ICP 76',
                ]);

        $companyB
            ->icpScore()
            ->create([
                'score' => 76,

                'grade' => 'A',

                'label' => 'Alta aderência',

                'version' => 'test',

                'factors' => [],

                'calculated_at' => now(),
            ]);

        $companyB
            ->crmCheck()
            ->create([
                'provider' => 'hubspot',

                'status' => 'not_found',

                'contacted_count' => 0,

                'associated_deals_count' => 0,

                'metadata' => [],

                'checked_at' => now(),
            ]);

        $service =
            app(
                LeadQualificationScoreService::class
            );

        $scoreA =
            $service
                ->calculate(
                    $companyA
                );

        $scoreB =
            $service
                ->calculate(
                    $companyB
                );

        expect(
            $scoreA[
                'score'
            ]
        )->toBe(80);

        expect(
            $scoreB[
                'score'
            ]
        )->toBe(66);

        expect(
            $scoreA[
                'score'
            ]
        )->toBeGreaterThan(
            $scoreB[
                'score'
            ]
        );

        expect(
            $scoreA[
                'priority'
            ]
        )->toBe(
            'high'
        );

        expect(
            $scoreB[
                'priority'
            ]
        )->toBe(
            'medium'
        );
    }
);
