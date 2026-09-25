<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function manualLinkManager(): User
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
    'allows manager to search and manually link an unmatched HubSpot company',
    function (): void {
        $manager =
            manualLinkManager();

        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '88369889',

                    'corporate_name' => 'AGROSUL AGROAVICOLA INDUSTRIAL S.A.',
                ]);

        $hubSpot =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => '9258277170',

                    'name' => 'AGROSUL CEREAIS',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-agrosul-ui',

                    'name' => 'Negócio Agrosul',

                    'stage_label' => 'Leds qualificado',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $hubSpot
            ->deals()
            ->attach(
                $deal->id
            );

        Livewire::actingAs(
            $manager
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'openCompanyLink',
                $hubSpot->id
            )
            ->assertSet(
                'linkingHubSpotCompanyId',
                $hubSpot->id
            )
            ->set(
                'companyLinkSearch',
                'AGROSUL AGROAVICOLA'
            )
            ->assertSee(
                'AGROSUL AGROAVICOLA INDUSTRIAL S.A.'
            )
            ->call(
                'linkHubSpotCompany',
                $company->id
            )
            ->assertSet(
                'linkingHubSpotCompanyId',
                null
            )
            ->assertSee(
                'Vínculo confirmado'
            )
            ->assertSee(
                'AGROSUL AGROAVICOLA INDUSTRIAL S.A.'
            );

        expect(
            $hubSpot
                ->refresh()
                ->company_id
        )->toBe(
            $company->id
        );

        expect(
            $hubSpot
                ->match_source
        )->toBe(
            'manual_manager'
        );
    }
);

it(
    'previews Receita data before importing an unmatched company',
    function (): void {
        config([
            'services.receita_local.base_url' => 'http://receita.test',
        ]);

        Http::fake([
            'http://receita.test/groups/06065152*' => Http::response([
                'company' => [
                    'corporate_name' => 'AGROSUL-COMERCIO E ARMAZENAMENTO DE CEREAIS LTDA',
                ],

                'establishments' => [
                    [
                        'establishment' => [
                            'cnpj' => '06065152000159',

                            'type' => 'matrix',

                            'fantasy_name' => 'AGROSUL CEREAIS',

                            'registration_status' => 'ATIVA',

                            'municipality_name' => 'RIBEIRAO DO SUL',

                            'state' => 'SP',

                            'source' => 'receita-local',
                        ],

                        'cnaes' => [],
                    ],
                ],
            ], 200),
        ]);

        $manager =
            manualLinkManager();

        $hubSpot =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => '9258277170-preview',

                    'name' => 'AGROSUL CEREAIS',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-agrosul-preview',

                    'name' => 'Negócio Agrosul',

                    'stage_label' => 'Leds qualificado',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $hubSpot
            ->deals()
            ->attach(
                $deal->id
            );

        Livewire::actingAs(
            $manager
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'openCompanyLink',
                $hubSpot->id
            )
            ->set(
                'companyLinkSearch',
                '06.065.152/0001-59'
            )
            ->call(
                'lookupCompanyLinkReceita'
            )
            ->assertSee(
                'AGROSUL-COMERCIO E ARMAZENAMENTO DE CEREAIS LTDA'
            )
            ->assertSee(
                'AGROSUL CEREAIS'
            )
            ->assertSee(
                'RIBEIRAO DO SUL'
            )
            ->assertSee(
                'ATIVA'
            )
            ->call(
                'importAndLinkHubSpotCompany'
            )
            ->assertSee(
                'Empresa importada e vinculada'
            );

        expect(
            Company::query()
                ->where(
                    'cnpj_root',
                    '06065152'
                )
                ->exists()
        )->toBeTrue();

        expect(
            $hubSpot
                ->refresh()
                ->company_id
        )->not->toBeNull();
    }
);
