<?php

use App\Jobs\HubSpotQueueHeartbeat;
use Illuminate\Support\Facades\Cache;

it('records a heartbeat when a priority HubSpot queue consumes the job', function () {
    Cache::forget(
        HubSpotQueueHeartbeat::cacheKey(
            'hubspot-webhooks'
        )
    );

    $job =
        new HubSpotQueueHeartbeat(
            'hubspot-webhooks'
        );

    $job->handle();

    expect(
        Cache::get(
            HubSpotQueueHeartbeat::cacheKey(
                'hubspot-webhooks'
            )
        )
    )->not->toBeNull();
});
