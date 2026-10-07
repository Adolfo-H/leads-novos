<?php

use App\Services\HubSpotTunnelHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config([
        'services.hubspot.webhook_public_url' => 'https://prospector-health.test/webhooks/hubspot',

        'services.hubspot.health_tunnel_check_enabled' => true,

        'services.hubspot.health_tunnel_cache_seconds' => 10,

        'services.hubspot.health_tunnel_timeout_seconds' => 2,
    ]);
});

it('considers a method not allowed response proof that the public webhook is reachable', function () {
    Http::fake([
        '*' => Http::response(
            [],
            405
        ),
    ]);

    $snapshot =
        app(
            HubSpotTunnelHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'configured'
        ]
    )->toBeTrue();

    expect(
        $snapshot[
            'checked'
        ]
    )->toBeTrue();

    expect(
        $snapshot[
            'online'
        ]
    )->toBeTrue();

    expect(
        $snapshot[
            'http_status'
        ]
    )->toBe(
        405
    );
});

it('reports the public webhook unavailable on gateway failure', function () {
    Http::fake([
        '*' => Http::response(
            [],
            502
        ),
    ]);

    $snapshot =
        app(
            HubSpotTunnelHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'online'
        ]
    )->toBeFalse();

    expect(
        $snapshot[
            'http_status'
        ]
    )->toBe(
        502
    );
});
