<?php

use App\Models\Company;
use App\Services\HubSpotContactResolverService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.hubspot.access_token' => 'test-token',

        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.http_retry_delays_ms' => [
            0,
            0,
            0,
        ],

        'services.hubspot.http_retry_max_delay_ms' => 0,
    ]);
});

it('creates a new contact with every supplied channel and owner', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '97979797',

            'corporate_name' => 'EMPRESA RESOLVER CONTACT',
        ]);

    Http::fake(
        function (
            Request $request
        ) {
            if (
                $request->method() === 'POST'
                && str_ends_with(
                    $request->url(),
                    '/contacts/search'
                )
            ) {
                return Http::response([
                    'results' => [],
                ]);
            }

            if (
                $request->method() === 'POST'
                && str_ends_with(
                    $request->url(),
                    '/contacts'
                )
            ) {
                return Http::response(
                    [
                        'id' => 'contact-new-1',
                    ],
                    201
                );
            }

            return Http::response(
                [],
                404
            );
        }
    );

    $id =
        app(
            HubSpotContactResolverService::class
        )->resolve(
            company: $company,

            email: 'comercial@resolver.test',

            phone: '45999990001',

            mobilePhone: '45999990002',

            ownerId: 'owner-100',
        );

    expect(
        $id
    )->toBe(
        'contact-new-1'
    );

    Http::assertSent(
        function (
            Request $request
        ): bool {
            if (
                $request->method() !== 'POST'
                || ! str_ends_with(
                    $request->url(),
                    '/crm/v3/objects/contacts'
                )
            ) {
                return false;
            }

            return
                data_get(
                    $request->data(),
                    'properties.email'
                )
                    === 'comercial@resolver.test'
                && data_get(
                    $request->data(),
                    'properties.phone'
                )
                    === '45999990001'
                && data_get(
                    $request->data(),
                    'properties.mobilephone'
                )
                    === '45999990002'
                && data_get(
                    $request->data(),
                    'properties.hubspot_owner_id'
                )
                    === 'owner-100';
        }
    );
});

it('keeps an existing contact and only fills missing channels', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '98989898',

            'corporate_name' => 'EMPRESA CONTACT EXISTENTE',
        ]);

    Http::fake(
        function (
            Request $request
        ) {
            if (
                $request->method() === 'POST'
                && str_ends_with(
                    $request->url(),
                    '/contacts/search'
                )
            ) {
                return Http::response([
                    'results' => [
                        [
                            'id' => 'contact-existing',
                        ],
                    ],
                ]);
            }

            if (
                $request->method() === 'GET'
                && str_contains(
                    $request->url(),
                    '/contacts/contact-existing'
                )
            ) {
                return Http::response([
                    'id' => 'contact-existing',

                    'properties' => [
                        'company' => null,

                        'phone' => null,

                        /*
                         * Já existente:
                         * não deve ser substituído.
                         */
                        'mobilephone' => '11911112222',
                    ],
                ]);
            }

            if (
                $request->method() === 'PATCH'
                && str_contains(
                    $request->url(),
                    '/contacts/contact-existing'
                )
            ) {
                return Http::response(
                    [
                        'id' => 'contact-existing',
                    ],
                    200
                );
            }

            return Http::response(
                [],
                404
            );
        }
    );

    $id =
        app(
            HubSpotContactResolverService::class
        )->resolve(
            company: $company,

            email: 'existente@resolver.test',

            phone: '45999993333',

            mobilePhone: '45999994444',

            ownerId: 'owner-que-nao-deve-ser-transferido',
        );

    expect(
        $id
    )->toBe(
        'contact-existing'
    );

    Http::assertSent(
        function (
            Request $request
        ) use (
            $company
        ): bool {
            if (
                $request->method() !== 'PATCH'
                || ! str_contains(
                    $request->url(),
                    '/contacts/contact-existing'
                )
            ) {
                return false;
            }

            $properties =
                data_get(
                    $request->data(),
                    'properties',
                    []
                );

            return
                data_get(
                    $properties,
                    'company'
                )
                    === $company->corporate_name
                && data_get(
                    $properties,
                    'phone'
                )
                    === '45999993333'
                && ! array_key_exists(
                    'mobilephone',
                    $properties
                )
                && ! array_key_exists(
                    'hubspot_owner_id',
                    $properties
                );
        }
    );

    Http::assertNotSent(
        fn (
            Request $request
        ): bool => $request->method() === 'POST'
            && $request->url()
                === 'https://api.hubapi.com/crm/v3/objects/contacts'
    );
});
