<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Jobs\RefreshCompanyFromHubSpot;
use App\Jobs\SyncCompanyToHubSpot;
use App\Jobs\SyncHubSpotCompanyRealtimeStatus;
use App\Jobs\SyncManualHubSpotOpportunity;

it('routes HubSpot jobs to dedicated priority queues', function () {
    $webhook =
        new ProcessHubSpotWebhookEvent(
            1
        );

    $realtime =
        new RefreshCompanyFromHubSpot(
            1
        );

    $bulk =
        new RefreshCompanyFromHubSpot(
            1,
            10
        );

    $status =
        new SyncHubSpotCompanyRealtimeStatus(
            1
        );

    $manual =
        new SyncManualHubSpotOpportunity(
            1,
            1
        );

    $automatic =
        new SyncCompanyToHubSpot(
            1
        );

    expect(
        $webhook->queue
    )->toBe(
        'hubspot-webhooks'
    );

    expect(
        $realtime->queue
    )->toBe(
        'hubspot-realtime'
    );

    expect(
        $bulk->queue
    )->toBe(
        'hubspot-bulk'
    );

    expect(
        $status->queue
    )->toBe(
        'hubspot-realtime'
    );

    expect(
        $manual->queue
    )->toBe(
        'hubspot-realtime'
    );

    expect(
        $automatic->queue
    )->toBe(
        'hubspot-realtime'
    );
});
