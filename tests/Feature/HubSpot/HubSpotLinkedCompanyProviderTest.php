<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Services\Providers\HubSpotCrmCompanyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it(
    'uses a confirmed HubSpot company id before searching by fiscal name',
    function (): void {
        config([
            'services.hubspot.base_url' => 'https://api.hubapi.com',

            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.portal_id' => '21358298',
        ]);

        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '06065152',

                    'corporate_name' => 'AGROSUL-COMERCIO E ARMAZENAMENTO DE CEREAIS LTDA',
                ]);

        HubSpotCompany::query()
            ->create([
                'company_id' => $company->id,

                'matched_cnpj_root' => '06065152',

                'matched_company_name' => $company->corporate_name,

                'match_source' => 'manual_manager',

                'hubspot_id' => '9258277170',

                'name' => 'AGROSUL CEREAIS',
            ]);

        Http::fake(
            function (
                Request $request
            ) {
                $url =
                    $request->url();

                if (
                    str_contains(
                        $url,
                        '/companies/9258277170/associations/deals'
                    )
                ) {
                    return Http::response([
                        'results' => [
                            [
                                'id' => 'deal-1',
                            ],
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/companies/9258277170'
                    )
                    && $request->method()
                        === 'GET'
                ) {
                    return Http::response([
                        'id' => '9258277170',

                        'properties' => [
                            'name' => 'AGROSUL CEREAIS',

                            'domain' => null,

                            'lifecyclestage' => 'lead',

                            'hubspot_owner_id' => 'owner-1',

                            'num_contacted_notes' => '2',

                            'num_associated_deals' => '1',

                            'notes_last_contacted' => '2026-09-25T13:10:00Z',
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/deals/batch/read'
                    )
                ) {
                    return Http::response([
                        'results' => [
                            [
                                'id' => 'deal-1',

                                'properties' => [
                                    'dealname' => 'Negócio Agrosul',

                                    'dealstage' => 'stage-refused',

                                    'pipeline' => 'default',

                                    'hs_is_closed' => 'true',

                                    'hs_is_closed_won' => 'false',

                                    'closedate' => null,
                                ],
                            ],
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/crm/v3/pipelines/deals'
                    )
                ) {
                    return Http::response([
                        'results' => [
                            [
                                'id' => 'default',

                                'stages' => [
                                    [
                                        'id' => 'stage-refused',

                                        'label' => 'Recusou',
                                    ],
                                ],
                            ],
                        ],
                    ]);
                }

                /*
                 * Se chegar ao search por nome,
                 * o teste deve falhar.
                 */
                if (
                    str_contains(
                        $url,
                        '/companies/search'
                    )
                ) {
                    return Http::response(
                        [
                            'message' => 'Não deveria pesquisar por nome.',
                        ],
                        500
                    );
                }

                return Http::response(
                    [],
                    404
                );
            }
        );

        $result =
            app(
                HubSpotCrmCompanyProvider::class
            )->findCompany(
                $company
            );

        expect(
            $result[
                'found'
            ]
        )->toBeTrue();

        expect(
            $result[
                'external_id'
            ]
        )->toBe(
            '9258277170'
        );

        expect(
            $result[
                'name'
            ]
        )->toBe(
            'AGROSUL CEREAIS'
        );

        expect(
            $result[
                'matched_by'
            ]
        )->toBe(
            'linked_hubspot_id'
        );

        expect(
            $result[
                'associated_deals_count'
            ]
        )->toBe(1);

        expect(
            $result[
                'deals'
            ][0][
                'stage_label'
            ]
        )->toBe(
            'Recusou'
        );

        Http::assertNotSent(
            fn (
                Request $request
            ): bool => str_contains(
                $request->url(),
                '/companies/search'
            )
        );
    }
);
