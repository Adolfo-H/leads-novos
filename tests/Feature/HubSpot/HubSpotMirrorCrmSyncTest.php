<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Services\HubSpotMirrorCrmSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it(
    'rebuilds CRM using multiple HubSpot companies and deals',
    function () {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '12345678',

                    'corporate_name' => 'Empresa Teste',
                ]);

        $hubA =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $company->id,

                    'hubspot_id' => 'company-a',

                    'name' => 'Empresa Teste',

                    'lifecycle_stage' => 'Oportunidade',

                    'raw_properties' => [
                        'Número de contatos efetuados' => '4.0',
                    ],
                ]);

        $hubB =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $company->id,

                    'hubspot_id' => 'company-b',

                    'name' => 'Empresa Teste Unidade',

                    'raw_properties' => [
                        'Número de contatos efetuados' => '2.0',
                    ],
                ]);

        $openDeal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-open',

                    'name' => 'Negócio Aberto',

                    'stage_label' => 'Proposta apresentada',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $closedDeal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-closed',

                    'name' => 'Negócio Encerrado',

                    'stage_label' => 'Recusou',

                    'is_closed' => true,

                    'is_closed_won' => false,
                ]);

        $hubA
            ->deals()
            ->attach(
                $openDeal->id
            );

        $hubB
            ->deals()
            ->attach(
                $closedDeal->id
            );

        $check =
            app(
                HubSpotMirrorCrmSyncService::class
            )->sync(
                $company
            );

        expect(
            $check->status
        )->toBe(
            'opportunity'
        );

        expect(
            $check->contacted_count
        )->toBe(6);

        expect(
            $check->associated_deals_count
        )->toBe(2);

        expect(
            data_get(
                $check->metadata,
                'deal_summary.total'
            )
        )->toBe(2);

        expect(
            data_get(
                $check->metadata,
                'deal_summary.active'
            )
        )->toBe(1);

        expect(
            data_get(
                $check->metadata,
                'hubspot_company_count'
            )
        )->toBe(2);

        $score =
            $company
                ->fresh()
                ->sdrScore;

        expect(
            $score
                ?->score
        )->toBe(0);

        expect(
            $score
                ?->is_eligible
        )->toBeFalse();
    }
);
