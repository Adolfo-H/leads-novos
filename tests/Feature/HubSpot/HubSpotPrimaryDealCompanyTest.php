<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Services\HubSpotDealCompanyAssociationService;
use App\Services\HubSpotMirrorCrmSyncService;
use App\Services\HubSpotWebhookAssociationResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it(
    'uses only the primary company of a multiple company deal',
    function (): void {
        $companyA =
            Company::query()
                ->create([
                    'cnpj_root' => '11111111',

                    'corporate_name' => 'Empresa Secundaria',
                ]);

        $companyB =
            Company::query()
                ->create([
                    'cnpj_root' => '22222222',

                    'corporate_name' => 'Empresa Principal',
                ]);

        $hubA =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $companyA->id,

                    'matched_cnpj_root' => '11111111',

                    'matched_company_name' => 'Empresa Secundaria',

                    'match_source' => 'manual_manager',

                    'hubspot_id' => 'hub-secondary',

                    'name' => 'Empresa Secundaria',
                ]);

        $hubB =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $companyB->id,

                    'matched_cnpj_root' => '22222222',

                    'matched_company_name' => 'Empresa Principal',

                    'match_source' => 'manual_manager',

                    'hubspot_id' => 'hub-primary',

                    'name' => 'Empresa Principal',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-multiple',

                    'name' => 'Negocio multiplo',

                    'stage_label' => 'Aceite da proposta',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $deal
            ->companies()
            ->attach(
                $hubA->id,
                [
                    'is_primary' => false,
                ]
            );

        $deal
            ->companies()
            ->attach(
                $hubB->id,
                [
                    'is_primary' => true,
                ]
            );

        $secondary =
            app(
                HubSpotMirrorCrmSyncService::class
            )->preview(
                $companyA
            );

        $primary =
            app(
                HubSpotMirrorCrmSyncService::class
            )->preview(
                $companyB
            );

        expect(
            $secondary[
                'associated_deals_count'
            ]
        )->toBe(0);

        expect(
            $primary[
                'associated_deals_count'
            ]
        )->toBe(1);

        expect(
            data_get(
                $primary,
                'metadata.deals.0.stage_label'
            )
        )->toBe(
            'Aceite da proposta'
        );

        expect(
            app(
                HubSpotWebhookAssociationResolver::class
            )->companyIds(
                objectType: 'deal',

                objectId: 'deal-multiple',
            )
        )->toBe([
            $companyB->id,
        ]);
    }
);

it(
    'does not spread an ambiguous deal to multiple fiscal companies',
    function (): void {
        $companyA =
            Company::query()
                ->create([
                    'cnpj_root' => '33333333',

                    'corporate_name' => 'Empresa A',
                ]);

        $companyB =
            Company::query()
                ->create([
                    'cnpj_root' => '44444444',

                    'corporate_name' => 'Empresa B',
                ]);

        $hubA =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $companyA->id,

                    'hubspot_id' => 'ambiguous-a',

                    'name' => 'Empresa A',
                ]);

        $hubB =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $companyB->id,

                    'hubspot_id' => 'ambiguous-b',

                    'name' => 'Empresa B',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'ambiguous-deal',

                    'name' => 'Deal sem Primary',
                ]);

        $deal
            ->companies()
            ->attach(
                $hubA->id,
                [
                    'is_primary' => false,
                ]
            );

        $deal
            ->companies()
            ->attach(
                $hubB->id,
                [
                    'is_primary' => false,
                ]
            );

        expect(
            app(
                HubSpotWebhookAssociationResolver::class
            )->companyIds(
                objectType: 'deal',

                objectId: 'ambiguous-deal',
            )
        )->toBe([]);
    }
);

it(
    'stores the HubSpot primary company in the deal pivot',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'hub-deal-primary',

                    'name' => 'Deal Primary API',
                ]);

        Http::fake([
            'https://api.hubapi.com/crm/v4/objects/deals/hub-deal-primary/associations/companies*' => Http::response([
                'results' => [
                    [
                        'toObjectId' => 'hs-company-secondary',

                        'associationTypes' => [
                            [
                                'category' => 'HUBSPOT_DEFINED',

                                'typeId' => 5,

                                'label' => null,
                            ],
                        ],
                    ],
                    [
                        'toObjectId' => 'hs-company-primary',

                        'associationTypes' => [
                            [
                                'category' => 'HUBSPOT_DEFINED',

                                'typeId' => 341,

                                'label' => 'Deal with Primary Company',
                            ],
                        ],
                    ],
                ],
            ]),

            'https://api.hubapi.com/crm/v4/associations/deals/companies/labels*' => Http::response([
                'results' => [
                    [
                        'category' => 'HUBSPOT_DEFINED',

                        'typeId' => 5,

                        'label' => null,
                    ],
                    [
                        'category' => 'HUBSPOT_DEFINED',

                        'typeId' => 341,

                        'label' => 'Deal with Primary Company',
                    ],
                ],
            ]),
        ]);

        $result =
            app(
                HubSpotDealCompanyAssociationService::class
            )->syncForDeal(
                $deal
            );

        expect(
            $result[
                'associations'
            ]
        )->toBe(2);

        expect(
            $result[
                'primary'
            ]
        )->toBe(1);

        expect(
            $result[
                'ambiguous'
            ]
        )->toBeFalse();

        $rows =
            DB::table(
                'hubspot_company_deal as hcd'
            )
                ->join(
                    'hubspot_companies as hc',
                    'hc.id',
                    '=',
                    'hcd.hubspot_company_id'
                )
                ->where(
                    'hcd.hubspot_deal_id',
                    $deal->id
                )
                ->get([
                    'hc.hubspot_id',
                    'hcd.is_primary',
                ]);

        $primary =
            $rows->firstWhere(
                'hubspot_id',
                'hs-company-primary'
            );

        $secondary =
            $rows->firstWhere(
                'hubspot_id',
                'hs-company-secondary'
            );

        expect(
            (bool) (
                $primary
                    ?->is_primary
                ?? false
            )
        )->toBeTrue();

        expect(
            (bool) (
                $secondary
                    ?->is_primary
                ?? false
            )
        )->toBeFalse();
    }
);

it(
    'preserves existing deal companies when association data is malformed',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $company =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'known-company',

                    'name' => 'Vínculo conhecido',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-malformed-associations',

                    'name' => 'Negócio com resposta inválida',
                ]);

        $deal
            ->companies()
            ->attach(
                $company->id,
                [
                    'is_primary' => true,
                ]
            );

        Http::fake(
            function (
                Request $request
            ) {
                if (
                    str_contains(
                        $request->url(),
                        '/crm/v4/objects/deals/deal-malformed-associations/associations/companies'
                    )
                ) {
                    return Http::response([
                        'unexpected' => [],
                    ]);
                }

                return Http::response([], 404);
            }
        );

        expect(
            fn () => app(
                HubSpotDealCompanyAssociationService::class
            )->syncForDeal(
                $deal
            )
        )->toThrow(
            RuntimeException::class,
            'Resposta inválida das associações Deal -> Company'
        );

        expect(
            $deal
                ->companies()
                ->whereKey(
                    $company->id
                )
                ->exists()
        )->toBeTrue();
    }
);

it(
    'fails instead of completing synchronization when HubSpot repeats an association cursor',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $hubSpotCompanies = collect([
            'deal-company-a',
            'deal-company-b',
            'deal-company-c',
        ])->map(
            fn (string $hubSpotId): HubSpotCompany => HubSpotCompany::query()
                ->create([
                    'hubspot_id' => $hubSpotId,

                    'name' => $hubSpotId,
                ])
        );

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-repeated-association-cursor',

                    'name' => 'Negócio com cursor repetido',
                ]);

        $deal
            ->companies()
            ->attach(
                $hubSpotCompanies
                    ->mapWithKeys(
                        fn (HubSpotCompany $company): array => [
                            $company->id => [
                                'is_primary' => false,
                            ],
                        ]
                    )
                    ->all()
            );

        Http::fake(
            function (
                Request $request
            ) {
                $url =
                    $request->url();

                if (
                    str_contains(
                        $url,
                        '/crm/v4/objects/deals/deal-repeated-association-cursor/associations/companies'
                    )
                ) {
                    $query = [];

                    parse_str(
                        (string) parse_url(
                            $url,
                            PHP_URL_QUERY
                        ),
                        $query
                    );

                    $isSecondPage =
                        ($query['after'] ?? null)
                        === 'repeated-cursor';

                    return Http::response([
                        'results' => [
                            [
                                'toObjectId' => $isSecondPage
                                    ? 'deal-company-b'
                                    : 'deal-company-a',

                                'associationTypes' => [
                                    [
                                        'typeId' => $isSecondPage
                                            ? 5
                                            : 341,
                                    ],
                                ],
                            ],
                        ],

                        'paging' => [
                            'next' => [
                                'after' => 'repeated-cursor',
                            ],
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/crm/v4/associations/deals/companies/labels'
                    )
                ) {
                    return Http::response([
                        'results' => [
                            [
                                'typeId' => 5,

                                'label' => 'Deal associated company',
                            ],
                            [
                                'typeId' => 341,

                                'label' => 'Deal with Primary Company',
                            ],
                        ],
                    ]);
                }

                return Http::response([], 404);
            }
        );

        expect(
            fn () => app(
                HubSpotDealCompanyAssociationService::class
            )->syncForDeal(
                $deal
            )
        )->toThrow(
            RuntimeException::class,
            'HubSpot repetiu o cursor de associações'
        );

        expect(
            $deal
                ->companies()
                ->count()
        )->toBe(
            3
        );
    }
);

it(
    'preserves existing deal companies when a later association page returns 404',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $company =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'company-before-404',

                    'name' => 'Vínculo conhecido antes do 404',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-association-page-404',

                    'name' => 'Negócio com página indisponível',
                ]);

        $deal
            ->companies()
            ->attach(
                $company->id,
                [
                    'is_primary' => true,
                ]
            );

        Http::fake(
            function (
                Request $request
            ) {
                if (
                    ! str_contains(
                        $request->url(),
                        '/crm/v4/objects/deals/deal-association-page-404/associations/companies'
                    )
                ) {
                    return Http::response([], 404);
                }

                $query = [];

                parse_str(
                    (string) parse_url(
                        $request->url(),
                        PHP_URL_QUERY
                    ),
                    $query
                );

                if (
                    ($query['after'] ?? null)
                    === 'page-2'
                ) {
                    return Http::response([], 404);
                }

                return Http::response([
                    'results' => [
                        [
                            'toObjectId' => 'company-before-404',
                        ],
                    ],

                    'paging' => [
                        'next' => [
                            'after' => 'page-2',
                        ],
                    ],
                ]);
            }
        );

        expect(
            fn () => app(
                HubSpotDealCompanyAssociationService::class
            )->syncForDeal(
                $deal
            )
        )->toThrow(
            RuntimeException::class,
            'Falha ao paginar associações Deal -> Company. HTTP 404.'
        );

        expect(
            $deal
                ->companies()
                ->whereKey(
                    $company->id
                )
                ->exists()
        )->toBeTrue();
    }
);
