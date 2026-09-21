<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it(
    'recovers only explicit and unambiguous HubSpot CNPJs',
    function () {
        $local =
            Company::query()
                ->create([
                    'cnpj_root' => '07863768',

                    'corporate_name' => 'Central Energetica Vicentina Ltda',
                ]);

        $safe =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'hubspot-safe',

                    'name' => 'Central Energetica Vicentina',

                    'raw_properties' => [
                        'Associated Note' => 'CNPJ: 07.863.768/0001-38',
                    ],
                ]);

        $multiple =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'hubspot-multiple',

                    'name' => 'Empresa com múltiplos CNPJs',

                    'raw_properties' => [
                        'Associated Note' => 'CNPJ 07.863.768/0001-38 e CNPJ 33.000.167/0001-01',
                    ],
                ]);

        $withoutKeyword =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'hubspot-without-keyword',

                    'name' => 'Empresa sem indicação explícita',

                    'raw_properties' => [
                        'Descrição' => 'Número 07.863.768/0001-38',
                    ],
                ]);

        $this
            ->artisan(
                'hubspot:recover-cnpjs',
                [
                    '--apply' => true,
                ]
            )
            ->assertSuccessful();

        $safe->refresh();

        expect(
            $safe
                ->matched_cnpj_root
        )->toBe(
            '07863768'
        );

        expect(
            $safe
                ->company_id
        )->toBe(
            $local->id
        );

        expect(
            $safe
                ->match_source
        )->toBe(
            'hubspot_export_explicit_cnpj'
        );

        expect(
            $multiple
                ->refresh()
                ->matched_cnpj_root
        )->toBeNull();

        expect(
            $withoutKeyword
                ->refresh()
                ->matched_cnpj_root
        )->toBeNull();
    }
);
