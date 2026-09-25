<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadWorkState;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\HubSpotCompanyLinkService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it(
    'manually links HubSpot company and rebuilds commercial projections',
    function (): void {
        $seller =
            User::factory()
                ->create([
                    'name' => 'Adolfo Heerdt',

                    'email_verified_at' => now(),
                ]);

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

                    'owner_name' => 'Adolfo Heerdt',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-agrosul-link',

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

        $lead =
            app(
                HubSpotCompanyLinkService::class
            )->link(
                hubSpotCompany: $hubSpot,

                company: $company,
            );

        $hubSpot->refresh();

        expect(
            $hubSpot->company_id
        )->toBe(
            $company->id
        );

        expect(
            $hubSpot->matched_cnpj_root
        )->toBe(
            '88369889'
        );

        expect(
            $hubSpot->matched_company_name
        )->toBe(
            'AGROSUL AGROAVICOLA INDUSTRIAL S.A.'
        );

        expect(
            $hubSpot->match_source
        )->toBe(
            'manual_manager'
        );

        $crm =
            $company
                ->crmCheck()
                ->first();

        expect(
            $crm?->status
        )->toBe(
            'opportunity'
        );

        expect(
            $crm?->external_id
        )->toBe(
            '9258277170'
        );

        expect(
            $crm?->associated_deals_count
        )->toBe(1);

        expect(
            $company
                ->sdrScore()
                ->exists()
        )->toBeTrue();

        expect(
            $lead
        )->toBeInstanceOf(
            CompanyHubSpotLead::class
        );

        expect(
            $lead->hubspot_company_id
        )->toBe(
            '9258277170'
        );

        expect(
            $lead->hubspot_deal_id
        )->toBe(
            'deal-agrosul-link'
        );

        $state =
            CompanyLeadWorkState::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->first();

        expect(
            $state
                ?->assigned_user_id
        )->toBe(
            $seller->id
        );
    }
);

it(
    'refuses to overwrite an existing company link with another company',
    function (): void {
        $first =
            Company::query()
                ->create([
                    'cnpj_root' => '11112222',

                    'corporate_name' => 'Empresa Original',
                ]);

        $second =
            Company::query()
                ->create([
                    'cnpj_root' => '33334444',

                    'corporate_name' => 'Empresa Incorreta',
                ]);

        $hubSpot =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $first->id,

                    'matched_cnpj_root' => $first->cnpj_root,

                    'matched_company_name' => $first->corporate_name,

                    'match_source' => 'manual_manager',

                    'hubspot_id' => 'hubspot-protected-link',

                    'name' => 'Empresa HubSpot',
                ]);

        expect(
            fn () => app(
                HubSpotCompanyLinkService::class
            )->link(
                hubSpotCompany: $hubSpot,

                company: $second,
            )
        )->toThrow(
            DomainException::class
        );

        expect(
            $hubSpot
                ->refresh()
                ->company_id
        )->toBe(
            $first->id
        );
    }
);

it(
    'imports company from Receita and links it to HubSpot',
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

        $hubSpot =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => '9258277170-import',

                    'name' => 'AGROSUL CEREAIS',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-agrosul-import',

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

        $lead =
            app(
                HubSpotCompanyLinkService::class
            )->importAndLink(
                hubSpotCompany: $hubSpot,

                cnpj: '06.065.152/0001-59',
            );

        $company =
            Company::query()
                ->where(
                    'cnpj_root',
                    '06065152'
                )
                ->first();

        expect(
            $company
        )->not->toBeNull();

        expect(
            $company
                ?->corporate_name
        )->toBe(
            'AGROSUL-COMERCIO E ARMAZENAMENTO DE CEREAIS LTDA'
        );

        expect(
            $company
                ?->matrix
                ?->cnpj
        )->toBe(
            '06065152000159'
        );

        expect(
            $company
                ?->matrix
                ?->registration_status
        )->toBe(
            'ATIVA'
        );

        expect(
            $hubSpot
                ->refresh()
                ->company_id
        )->toBe(
            $company?->id
        );

        expect(
            $hubSpot
                ->matched_cnpj_root
        )->toBe(
            '06065152'
        );

        expect(
            $hubSpot
                ->match_source
        )->toBe(
            'manual_manager'
        );

        expect(
            $lead->company_id
        )->toBe(
            $company?->id
        );

        Http::assertSentCount(1);
    }
);
