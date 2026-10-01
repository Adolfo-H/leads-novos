<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function phaseFourWebhookEvent(
    string $key,
    string $status,
    int $attempts = 0,
): HubSpotWebhookEvent {
    return HubSpotWebhookEvent::query()
        ->create([
            'event_key' => hash(
                'sha256',
                $key
            ),

            'subscription_type' => 'object.propertyChange',

            'object_type' => 'deal',

            'object_type_id' => '0-3',

            'object_id' => (string)
                    random_int(
                        1000,
                        999999
                    ),

            'status' => $status,

            'attempts' => $attempts,

            'payload' => [
                'test' => $key,
            ],
        ]);
}

it('recovers received failed and stale webhook events', function () {
    Queue::fake();

    $received =
        phaseFourWebhookEvent(
            'received',
            'received',
        );

    $failed =
        phaseFourWebhookEvent(
            'failed',
            'failed',
            5,
        );

    $queued =
        phaseFourWebhookEvent(
            'queued-stale',
            'queued',
        );

    $processing =
        phaseFourWebhookEvent(
            'processing-stale',
            'processing',
            1,
        );

    $exhausted =
        phaseFourWebhookEvent(
            'failed-exhausted',
            'failed',
            10,
        );

    $processed =
        phaseFourWebhookEvent(
            'processed',
            'processed',
            1,
        );

    /*
     * Somente queued/processing dependem
     * da idade para recuperação.
     */
    DB::table(
        'hubspot_webhook_events'
    )
        ->whereIn(
            'id',
            [
                $queued->id,
                $processing->id,
            ]
        )
        ->update([
            'updated_at' => now()
                ->subMinutes(
                    20
                ),
        ]);

    $exitCode =
        Artisan::call(
            'hubspot:webhooks-recover',
            [
                '--minutes' => 10,

                '--limit' => 100,
            ]
        );

    expect(
        $exitCode
    )->toBe(
        0
    );

    Queue::assertPushed(
        ProcessHubSpotWebhookEvent::class,
        4
    );

    foreach (
        [
            $received,
            $failed,
            $queued,
            $processing,
        ] as $event
    ) {
        expect(
            $event
                ->refresh()
                ->status
        )->toBe(
            'queued'
        );
    }

    expect(
        $exhausted
            ->refresh()
            ->status
    )->toBe(
        'failed'
    );

    expect(
        $processed
            ->refresh()
            ->status
    )->toBe(
        'processed'
    );

    Queue::assertNotPushed(
        ProcessHubSpotWebhookEvent::class,
        fn (
            ProcessHubSpotWebhookEvent $job
        ): bool => $job->eventId
                === $exhausted->id
            || $job->eventId
                === $processed->id
    );
});

it('does not recover a recent queued or processing event', function () {
    Queue::fake();

    $queued =
        phaseFourWebhookEvent(
            'queued-recent',
            'queued',
        );

    $processing =
        phaseFourWebhookEvent(
            'processing-recent',
            'processing',
            1,
        );

    Artisan::call(
        'hubspot:webhooks-recover',
        [
            '--minutes' => 10,

            '--limit' => 100,
        ]
    );

    Queue::assertNothingPushed();

    expect(
        $queued
            ->refresh()
            ->status
    )->toBe(
        'queued'
    );

    expect(
        $processing
            ->refresh()
            ->status
    )->toBe(
        'processing'
    );
});
