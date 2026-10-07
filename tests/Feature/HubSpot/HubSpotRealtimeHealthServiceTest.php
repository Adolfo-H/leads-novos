<?php

use App\Jobs\HubSpotQueueHeartbeat;
use App\Models\HubSpotRefreshRun;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotRealtimeHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config([
        'services.hubspot.webhook_public_url' => null,

        'services.hubspot.health_worker_stale_seconds' => 180,
    ]);

    Cache::put(
        HubSpotQueueHeartbeat::cacheKey(
            'hubspot-webhooks'
        ),
        now()->toIso8601String(),
        now()->addMinutes(10),
    );

    Cache::put(
        HubSpotQueueHeartbeat::cacheKey(
            'hubspot-realtime'
        ),
        now()->toIso8601String(),
        now()->addMinutes(10),
    );
});

it('reports healthy when queues are alive and there is no backlog', function () {
    HubSpotWebhookEvent::query()
        ->create([
            'event_key' => hash(
                'sha256',
                'healthy-event'
            ),

            'subscription_type' => 'object.propertyChange',

            'object_type' => 'task',

            'object_id' => 'task-health-1',

            'property_name' => 'hs_timestamp',

            'occurred_at' => now()
                ->subSeconds(5),

            'status' => 'processed',

            'attempts' => 1,

            'payload' => [],

            'processed_at' => now(),
        ]);

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'status'
        ]
    )->toBe(
        'healthy'
    );

    expect(
        $snapshot[
            'pending'
        ]
    )->toBe(
        0
    );

    expect(
        $snapshot[
            'webhook_worker_online'
        ]
    )->toBeTrue();

    expect(
        $snapshot[
            'realtime_worker_online'
        ]
    )->toBeTrue();
});

it('reports degraded when the realtime worker heartbeat becomes stale', function () {
    Cache::put(
        HubSpotQueueHeartbeat::cacheKey(
            'hubspot-realtime'
        ),
        now()
            ->subMinutes(5)
            ->toIso8601String(),
        now()
            ->addMinutes(10),
    );

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'status'
        ]
    )->toBe(
        'degraded'
    );

    expect(
        $snapshot[
            'realtime_worker_online'
        ]
    )->toBeFalse();

    expect(
        $snapshot[
            'webhook_worker_online'
        ]
    )->toBeTrue();
});

it('reports delayed when a webhook waits more than one minute', function () {
    $event =
        HubSpotWebhookEvent::query()
            ->create([
                'event_key' => hash(
                    'sha256',
                    'delayed-event'
                ),

                'subscription_type' => 'object.propertyChange',

                'object_type' => 'task',

                'object_id' => 'task-health-2',

                'property_name' => 'hs_timestamp',

                'occurred_at' => now()
                    ->subMinutes(2),

                'status' => 'queued',

                'attempts' => 0,

                'payload' => [],
            ]);

    $event->forceFill([
        'created_at' => now()
            ->subMinutes(2),

        'updated_at' => now()
            ->subMinutes(2),
    ])->save();

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'status'
        ]
    )->toBe(
        'delayed'
    );

    expect(
        $snapshot[
            'pending'
        ]
    )->toBe(
        1
    );
});

it('reports the active bulk refresh progress', function () {
    HubSpotRefreshRun::query()
        ->create([
            'scope' => 'bulk',

            'status' => 'running',

            'total' => 100,

            'processed' => 47,

            'changed' => 10,

            'unchanged' => 37,

            'failed' => 3,

            'started_at' => now(),
        ]);

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'bulk_active'
        ]
    )->toBeTrue();

    expect(
        $snapshot[
            'bulk_progress'
        ]
    )->toBe(
        50
    );

    expect(
        $snapshot[
            'bulk_label'
        ]
    )->toBe(
        '50 / 100'
    );
});

it('treats a recent real webhook as proof that the public endpoint is reachable', function () {
    config([
        'services.hubspot.webhook_public_url' => 'https://prospector-health.test/webhooks/hubspot',

        'services.hubspot.health_tunnel_check_enabled' => true,

        'services.hubspot.health_tunnel_cache_seconds' => 10,

        'services.hubspot.health_public_evidence_minutes' => 5,
    ]);

    /*
     * Simula justamente o problema observado:
     *
     * o container não consegue testar a própria
     * URL pública...
     */
    Http::fake([
        '*' => Http::response(
            [],
            502
        ),
    ]);

    /*
     * ...mas o HubSpot REAL acabou de conseguir
     * entregar um webhook.
     */
    HubSpotWebhookEvent::query()
        ->create([
            'event_key' => hash(
                'sha256',
                'real-public-evidence'
            ),

            'subscription_type' => 'object.propertyChange',

            'object_type' => 'task',

            'object_id' => 'task-public-evidence',

            'property_name' => 'hs_timestamp',

            'occurred_at' => now()
                ->subSeconds(10),

            'status' => 'processed',

            'attempts' => 1,

            'payload' => [],

            'processed_at' => now(),
        ]);

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'status'
        ]
    )->toBe(
        'healthy'
    );

    expect(
        $snapshot[
            'tunnel_online'
        ]
    )->toBeTrue();

    expect(
        $snapshot[
            'tunnel_label'
        ]
    )->toContain(
        'webhook'
    );

    expect(
        $snapshot[
            'label'
        ]
    )->toBe(
        'HubSpot tempo real OK'
    );
});

it('does not report one hundred percent before the bulk refresh is actually complete', function () {
    HubSpotRefreshRun::query()
        ->create([
            'scope' => 'bulk',

            'status' => 'running',

            'total' => 1146,

            'processed' => 1145,

            'changed' => 100,

            'unchanged' => 1045,

            'failed' => 0,

            'started_at' => now(),
        ]);

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'bulk_progress'
        ]
    )->toBe(
        99
    );

    expect(
        $snapshot[
            'bulk_label'
        ]
    )->toBe(
        '1.145 / 1.146'
    );
});

it('reports one hundred percent only when the bulk refresh is complete', function () {
    HubSpotRefreshRun::query()
        ->create([
            'scope' => 'bulk',

            'status' => 'running',

            'total' => 1146,

            'processed' => 1146,

            'changed' => 101,

            'unchanged' => 1045,

            'failed' => 0,

            'started_at' => now(),
        ]);

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot[
            'bulk_progress'
        ]
    )->toBe(
        100
    );

    expect(
        $snapshot[
            'bulk_label'
        ]
    )->toBe(
        '1.146 / 1.146'
    );
});
