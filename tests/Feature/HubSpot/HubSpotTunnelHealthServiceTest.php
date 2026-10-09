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
        )->refresh();

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
        )->refresh();

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

// V24103: um GET da pagina nao pode executar HEAD no tunel publico.
it('never makes an external request while rendering a lead page', function (): void {
    Http::preventStrayRequests();

    $status = app(HubSpotTunnelHealthService::class)->snapshot();

    expect($status['configured'])->toBeTrue()
        ->and($status['checked'])->toBeFalse()
        ->and($status['online'])->toBeNull()
        ->and($status['label'])->toBe('verificação agendada');

    Http::assertNothingSent();
});

it('serves a previously refreshed status from cache without extra HTTP calls', function (): void {
    Http::fake(['*' => Http::response([], 405)]);

    $service = app(HubSpotTunnelHealthService::class);
    expect($service->refresh()['online'])->toBeTrue();
    Http::assertSentCount(1);

    $status = $service->snapshot();
    expect($status['checked'])->toBeTrue()
        ->and($status['http_status'])->toBe(405);

    Http::assertSentCount(1);
});

it('refreshes the probe from the scheduled artisan command', function (): void {
    Http::fake(['*' => Http::response([], 405)]);

    $this->artisan('hubspot:tunnel-health-refresh')->assertExitCode(0);
    Http::assertSentCount(1);
    expect(app(HubSpotTunnelHealthService::class)->snapshot()['online'])->toBeTrue();
    Http::assertSentCount(1);
});
