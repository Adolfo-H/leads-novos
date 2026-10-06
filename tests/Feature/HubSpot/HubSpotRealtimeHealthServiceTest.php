<?php

use App\Models\HubSpotRefreshRun;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotRealtimeHealthService;

it('reports healthy when there is no webhook backlog', function () {
    $event =
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

    expect(
        $event->exists
    )->toBeTrue();

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot['status']
    )->toBe(
        'healthy'
    );

    expect(
        $snapshot['pending']
    )->toBe(
        0
    );
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
        'created_at' => now()->subMinutes(2),

        'updated_at' => now()->subMinutes(2),
    ])->save();

    $snapshot =
        app(
            HubSpotRealtimeHealthService::class
        )->snapshot();

    expect(
        $snapshot['status']
    )->toBe(
        'delayed'
    );

    expect(
        $snapshot['pending']
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
        $snapshot['bulk_active']
    )->toBeTrue();

    expect(
        $snapshot['bulk_progress']
    )->toBe(
        50
    );

    expect(
        $snapshot['bulk_label']
    )->toBe(
        '50 / 100'
    );
});
