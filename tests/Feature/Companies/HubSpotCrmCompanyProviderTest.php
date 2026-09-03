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

    Http::fake([
        'api.hubapi.com/*' => Http::response([
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
        ]),
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

    Http::assertSentCount(1);
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

    Http::assertSentCount(2);
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
