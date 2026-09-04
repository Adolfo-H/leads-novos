<?php

use App\Services\CompanyService;
use App\Services\Providers\HubSpotCrmCompanyProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.hubspot.access_token' => 'fake-token',

        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.portal_id' => '123456',
    ]);
});

it('finds a company by corporate email domain', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'COOPERATIVA TESTE',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'email' => 'fiscal@cooperativateste.com.br',
        ]
    );

    Http::fakeSequence()
        ->push([
            'total' => 1,

            'results' => [
                [
                    'id' => '999',

                    'properties' => [
                        'name' => 'Cooperativa Teste',

                        'domain' => 'cooperativateste.com.br',

                        'lifecyclestage' => 'customer',

                        'hubspot_owner_id' => '123',

                        'num_contacted_notes' => '10',

                        'num_associated_deals' => '2',

                        'notes_last_contacted' => '2026-09-01T10:00:00Z',
                    ],
                ],
            ],
        ])
        /*
         * A empresa foi localizada,
         * mas neste teste não precisamos
         * carregar negócios reais.
         */
        ->push([
            'results' => [],
        ]);

    $result = app(
        HubSpotCrmCompanyProvider::class
    )->findCompany(
        $company
    );

    expect(
        $result['found']
    )->toBeTrue();

    expect(
        $result['matched_by']
    )->toBe('domain');

    expect(
        $result['matched_value']
    )->toBe(
        'cooperativateste.com.br'
    );

    expect(
        $result['lifecycle_stage']
    )->toBe('customer');

    expect(
        $result['contacted_count']
    )->toBe(10);

    Http::assertSentCount(2);
});

it('falls back to normalized company name', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'AGRO INDUSTRIAL TESTE S.A.',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'email' => 'fiscal@agroteste.com.br',
        ]
    );

    Http::fakeSequence()
        ->push([
            'total' => 0,

            'results' => [],
        ])
        ->push([
            'total' => 1,

            'results' => [
                [
                    'id' => '555',

                    'properties' => [
                        'name' => 'Agro Industrial Teste S.A.',

                        'domain' => null,

                        'lifecyclestage' => 'lead',

                        'hubspot_owner_id' => null,

                        'num_contacted_notes' => '0',

                        'num_associated_deals' => '0',

                        'notes_last_contacted' => null,
                    ],
                ],
            ],
        ])
        /*
         * Consulta das associações da
         * empresa encontrada.
         */
        ->push([
            'results' => [],
        ]);

    $result = app(
        HubSpotCrmCompanyProvider::class
    )->findCompany(
        $company
    );

    expect(
        $result['found']
    )->toBeTrue();

    expect(
        $result['matched_by']
    )->toBe('name');

    expect(
        $result['external_id']
    )->toBe('555');

    Http::assertSentCount(3);
});

it('returns not found when neither domain nor name match', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'EMPRESA SEM CRM',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'email' => 'contato@semcrm.com.br',
        ]
    );

    Http::fake([
        'api.hubapi.com/*' => Http::response([
            'total' => 0,

            'results' => [],
        ]),
    ]);

    $result = app(
        HubSpotCrmCompanyProvider::class
    )->findCompany(
        $company
    );

    expect(
        $result['found']
    )->toBeFalse();

    expect(
        $result['matched_by']
    )->toBeNull();

    Http::assertSentCount(2);
});

it('loads associated deals with their real commercial state', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'ATVOS TESTE',
        ],
        [
            'cnpj' => '08.070.566/0001-00',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'email' => 'fiscal@atvosteste.com.br',
        ]
    );

    Http::fakeSequence()
        /*
         * Busca da empresa por domínio.
         */
        ->push([
            'total' => 1,

            'results' => [
                [
                    'id' => '8600495755',

                    'properties' => [
                        'name' => 'Atvos Teste',

                        'domain' => 'atvosteste.com.br',

                        'lifecyclestage' => 'customer',

                        'hubspot_owner_id' => '123',

                        'num_contacted_notes' => '10',

                        'num_associated_deals' => '2',

                        'notes_last_contacted' => '2026-09-01T10:00:00Z',
                    ],
                ],
            ],
        ])
        /*
         * Associações empresa -> negócios.
         */
        ->push([
            'results' => [
                [
                    'id' => 'deal-open',
                    'type' => 'company_to_deal',
                ],
                [
                    'id' => 'deal-lost',
                    'type' => 'company_to_deal',
                ],
            ],
        ])
        /*
         * Batch read dos negócios.
         */
        ->push([
            'results' => [
                [
                    'id' => 'deal-open',

                    'properties' => [
                        'dealname' => 'Atvos - Licenciamento',

                        'dealstage' => '13185626',

                        'pipeline' => 'default',

                        'hs_is_closed' => 'false',

                        'hs_is_closed_won' => 'false',

                        'closedate' => null,
                    ],
                ],
                [
                    'id' => 'deal-lost',

                    'properties' => [
                        'dealname' => 'Atvos - Retroativas',

                        'dealstage' => 'closedlost',

                        'pipeline' => 'default',

                        'hs_is_closed' => 'true',

                        'hs_is_closed_won' => 'false',

                        'closedate' => '2025-04-02T15:03:46Z',
                    ],
                ],
            ],
        ])
        /*
         * Pipelines / labels.
         */
        ->push([
            'results' => [
                [
                    'id' => 'default',

                    'label' => 'Pipeline Comercial',

                    'stages' => [
                        [
                            'id' => '13185626',

                            'label' => 'Proposta apresentada',
                        ],
                        [
                            'id' => 'closedlost',

                            'label' => 'Recusado',
                        ],
                    ],
                ],
            ],
        ]);

    $result = app(
        HubSpotCrmCompanyProvider::class
    )->findCompany(
        $company
    );

    expect(
        $result['deals']
    )->toHaveCount(2);

    expect(
        $result[
            'associated_deals_count'
        ]
    )->toBe(2);

    expect(
        $result['deals'][0]['name']
    )->toBe(
        'Atvos - Licenciamento'
    );

    expect(
        $result['deals'][0]['stage_label']
    )->toBe(
        'Proposta apresentada'
    );

    expect(
        $result['deals'][0]['is_closed']
    )->toBeFalse();

    expect(
        $result['deals'][0]['is_closed_won']
    )->toBeFalse();

    expect(
        $result['deals'][1]['stage_label']
    )->toBe(
        'Recusado'
    );

    expect(
        $result['deals'][1]['is_closed']
    )->toBeTrue();

    Http::assertSentCount(4);
});
