<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Livewire\Livewire;

function hubSpotOnlyManager(): User
{
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $user->forceFill([
        'commercial_role' => User::ROLE_MANAGER,
    ])->save();

    return $user->refresh();
}

it(
    'shows an unmatched HubSpot company when searched by CRM name',
    function (): void {
        config([
            'services.hubspot.portal_id' => '21358298',
        ]);

        $user =
            hubSpotOnlyManager();

        $hubSpotCompany =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => '9258277170',

                    'name' => 'AGROSUL CEREAIS',

                    'state' => 'SP',

                    'owner_name' => 'Adolfo Heerdt',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-agrosul',

                    'name' => 'Negócio Agrosul',

                    'stage_label' => 'Leds qualificado',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $hubSpotCompany
            ->deals()
            ->attach(
                $deal->id
            );

        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->set(
                'search',
                'agrosul cereais'
            )
            ->assertSee(
                'AGROSUL CEREAIS'
            )
            ->assertSee(
                'HubSpot sem vínculo fiscal'
            )
            ->assertSee(
                'CNPJ não identificado'
            )
            ->assertSee(
                'Leds qualificado'
            )
            ->assertSee(
                'https://app.hubspot.com/contacts/21358298/record/0-2/9258277170',
                false
            );
    }
);

it(
    'finds a mapped company using its HubSpot alias without duplicating it as CRM only',
    function (): void {
        $user =
            hubSpotOnlyManager();

        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '88369889',

                    'corporate_name' => 'AGROSUL AGROAVICOLA INDUSTRIAL S.A.',
                ]);

        $company
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

        HubSpotCompany::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_id' => 'hubspot-alias-agrosul',

                'name' => 'AGROSUL CEREAIS',
            ]);

        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->set(
                'search',
                'agrosul cereais'
            )
            ->assertSee(
                'AGROSUL AGROAVICOLA INDUSTRIAL S.A.'
            )
            ->assertDontSee(
                'CNPJ não identificado'
            );
    }
);

it(
    'keeps unmatched HubSpot companies hidden from sellers without a local portfolio link',
    function (): void {
        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        $user->forceFill([
            'commercial_role' => User::ROLE_SELLER,
        ])->save();

        $hubSpotCompany =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'hubspot-restricted',

                    'name' => 'EMPRESA HUBSPOT SEM CARTEIRA',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-restricted',

                    'name' => 'Negócio restrito',

                    'stage_label' => 'Prospects',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $hubSpotCompany
            ->deals()
            ->attach(
                $deal->id
            );

        Livewire::actingAs(
            $user->refresh()
        )
            ->test(
                'pages::leads.index'
            )
            ->set(
                'search',
                'empresa hubspot sem carteira'
            )
            ->assertDontSee(
                'EMPRESA HUBSPOT SEM CARTEIRA'
            );
    }
);
