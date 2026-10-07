<?php

use App\Services\HubSpotAssociationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

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

it('creates a default HubSpot association', function () {
    Http::fake([
        '*' => Http::response(
            [],
            200
        ),
    ]);

    app(
        HubSpotAssociationService::class
    )->associate(
        fromType: 'companies',

        fromId: 'company-100',

        toType: 'contacts',

        toId: 'contact-200',
    );

    Http::assertSent(
        function (
            Request $request
        ): bool {
            return
                $request->method()
                    === 'PUT'
                && $request->url()
                    ===
                    'https://api.hubapi.com'
                    .'/crm/v4/objects/'
                    .'companies/company-100/'
                    .'associations/default/'
                    .'contacts/contact-200';
        }
    );
});

it('rejects an association with an empty identifier', function () {
    Http::fake();

    expect(
        fn () => app(
            HubSpotAssociationService::class
        )->associate(
            fromType: 'companies',

            fromId: '',

            toType: 'contacts',

            toId: 'contact-200',
        )
    )->toThrow(
        InvalidArgumentException::class
    );

    Http::assertNothingSent();
});

it('does not retry an association write after a server error', function () {
    Http::fake([
        '*' => Http::response(
            [
                'message' => 'temporary failure',
            ],
            500
        ),
    ]);

    expect(
        fn () => app(
            HubSpotAssociationService::class
        )->associate(
            fromType: 'tasks',

            fromId: 'task-100',

            toType: 'contacts',

            toId: 'contact-200',
        )
    )->toThrow(
        RuntimeException::class,
        'Erro ao associar tasks com contacts'
    );

    expect(
        Http::recorded()
    )->toHaveCount(
        1
    );
});
