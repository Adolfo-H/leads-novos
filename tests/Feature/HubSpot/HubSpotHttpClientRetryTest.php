<?php

use App\Support\HubSpotHttpClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.hubspot.access_token' => 'test-token',

        /*
         * Testes não precisam realmente
         * aguardar backoff.
         */
        'services.hubspot.http_retry_delays_ms' => [
            0,
            0,
            0,
        ],

        'services.hubspot.http_retry_max_delay_ms' => 0,

        'services.hubspot.http_retry_attempts' => 4,
    ]);
});

it(
    'retries a transient HubSpot server error',
    function (): void {
        Http::fakeSequence()
            ->push(
                [
                    'message' => 'temporarily unavailable',
                ],
                503
            )
            ->push(
                [
                    'ok' => true,
                ],
                200
            );

        $response =
            HubSpotHttpClient::make()
                ->get(
                    'https://api.hubapi.com/test-retry'
                );

        expect(
            $response->status()
        )->toBe(
            200
        );

        expect(
            Http::recorded()
        )->toHaveCount(
            2
        );
    }
);

it(
    'retries HubSpot rate limiting',
    function (): void {
        Http::fakeSequence()
            ->push(
                [
                    'message' => 'rate limited',
                ],
                429,
                [
                    'Retry-After' => '0',
                ]
            )
            ->push(
                [
                    'ok' => true,
                ],
                200
            );

        $response =
            HubSpotHttpClient::make()
                ->get(
                    'https://api.hubapi.com/test-rate-limit'
                );

        expect(
            $response->status()
        )->toBe(
            200
        );

        expect(
            Http::recorded()
        )->toHaveCount(
            2
        );
    }
);

it(
    'does not retry authentication failures',
    function (): void {
        Http::fakeSequence()
            ->push(
                [
                    'message' => 'unauthorized',
                ],
                401
            )
            ->push(
                [
                    'ok' => true,
                ],
                200
            );

        $response =
            HubSpotHttpClient::make()
                ->get(
                    'https://api.hubapi.com/test-auth'
                );

        expect(
            $response->status()
        )->toBe(
            401
        );

        expect(
            Http::recorded()
        )->toHaveCount(
            1
        );
    }
);

it(
    'does not automatically retry a HubSpot write',
    function (): void {
        Http::fakeSequence()
            ->push(
                [
                    'message' => 'temporary error',
                ],
                503
            )
            ->push(
                [
                    'id' => 'created-twice',
                ],
                201
            );

        $response =
            HubSpotHttpClient::make(
                asJson: true,
            )
                ->post(
                    'https://api.hubapi.com/crm/v3/objects/companies',
                    [
                        'properties' => [
                            'name' => 'Nao duplicar',
                        ],
                    ]
                );

        expect(
            $response->status()
        )->toBe(
            503
        );

        /*
         * Se fossem duas chamadas poderíamos
         * criar uma Company duplicada caso a
         * primeira gravação tivesse ocorrido
         * antes da resposta falhar.
         */
        expect(
            Http::recorded()
        )->toHaveCount(
            1
        );
    }
);

it(
    'can retry a POST when the endpoint is explicitly read only',
    function (): void {
        Http::fakeSequence()
            ->push(
                [
                    'message' => 'temporary error',
                ],
                503
            )
            ->push(
                [
                    'results' => [],
                ],
                200
            );

        $response =
            HubSpotHttpClient::make(
                asJson: true,

                retryMethods: [
                    'GET',
                    'POST',
                ],
            )
                ->post(
                    'https://api.hubapi.com/crm/v3/objects/companies/search',
                    [
                        'filterGroups' => [],
                    ]
                );

        expect(
            $response->status()
        )->toBe(
            200
        );

        expect(
            Http::recorded()
        )->toHaveCount(
            2
        );
    }
);
