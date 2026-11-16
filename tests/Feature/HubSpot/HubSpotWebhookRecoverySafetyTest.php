<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotWebhookQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function v10WebhookRecoveryEvent(
    string $suffix,
    string $status,
    int $ageMinutes
): HubSpotWebhookEvent {
    $event = HubSpotWebhookEvent::query()->create([
        'event_key' => hash('sha256', 'stage-10-'.$suffix),
        'subscription_type' => 'object.propertyChange',
        'object_type' => 'unknown',
        'object_type_id' => '0-999',
        'object_id' => $suffix,
        'status' => $status,
        'attempts' => 1,
        'payload' => ['test' => $suffix],
    ]);

    DB::table('hubspot_webhook_events')
        ->where('id', $event->id)
        ->update([
            'updated_at' => now()->subMinutes($ageMinutes),
        ]);

    return $event;
}

it('does not recover recent queued or processing events', function () {
    Queue::fake();

    $queued = v10WebhookRecoveryEvent(
        'queued-recent', 'queued', 5
    );

    $processing = v10WebhookRecoveryEvent(
        'processing-recent', 'processing', 20
    );

    $result = app(HubSpotWebhookQueueService::class)
        ->recover(staleMinutes: 1, limit: 100);

    expect($result['selected'])->toBe(0)
        ->and($queued->refresh()->status)->toBe('queued')
        ->and($processing->refresh()->status)->toBe('processing');

    Queue::assertNothingPushed();
});

it('recovers only events older than safe limits', function () {
    Queue::fake();

    $queuedOld = v10WebhookRecoveryEvent(
        'queued-old', 'queued', 16
    );

    $processingOld = v10WebhookRecoveryEvent(
        'processing-old', 'processing', 31
    );

    $queuedRecent = v10WebhookRecoveryEvent(
        'queued-too-new', 'queued', 14
    );

    $processingRecent = v10WebhookRecoveryEvent(
        'processing-too-new', 'processing', 29
    );

    $result = app(HubSpotWebhookQueueService::class)
        ->recover(staleMinutes: 1, limit: 100);

    expect($result['selected'])->toBe(2)
        ->and($result['queued'])->toBe(2)
        ->and($queuedOld->refresh()->status)->toBe('queued')
        ->and($processingOld->refresh()->status)->toBe('queued')
        ->and($queuedRecent->refresh()->status)->toBe('queued')
        ->and($processingRecent->refresh()->status)->toBe('processing');

    Queue::assertPushed(
        ProcessHubSpotWebhookEvent::class,
        2
    );
});

it('rechecks freshness inside the database lock', function () {
    Queue::fake();

    $processing = v10WebhookRecoveryEvent(
        'lock-processing', 'processing', 20
    );

    $queued = v10WebhookRecoveryEvent(
        'lock-queued', 'queued', 5
    );

    $service = app(HubSpotWebhookQueueService::class);

    expect(
        $service->dispatch(
            $processing->id,
            force: true,
            staleMinutes: 1
        )
    )->toBeFalse();

    expect(
        $service->dispatch(
            $queued->id,
            force: true,
            staleMinutes: 1
        )
    )->toBeFalse();

    Queue::assertNothingPushed();
});

it('honors a larger manually requested recovery age', function () {
    Queue::fake();

    v10WebhookRecoveryEvent(
        'queued-fifty', 'queued', 20
    );

    v10WebhookRecoveryEvent(
        'processing-fifty', 'processing', 35
    );

    $result = app(HubSpotWebhookQueueService::class)
        ->recover(staleMinutes: 50, limit: 100);

    expect($result['selected'])->toBe(0);

    Queue::assertNothingPushed();
});
