<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use Illuminate\Contracts\Queue\Job as QueueJob;

function stageNineWebhookEvent(string $key, string $status, int $attempts = 1): HubSpotWebhookEvent
{
    return HubSpotWebhookEvent::query()->create([
        'event_key' => hash('sha256', 'stage-nine-'.$key),
        'subscription_type' => 'object.propertyChange',
        'object_type' => 'unknown',
        'object_type_id' => '0-999',
        'object_id' => 'test-'.$key,
        'status' => $status,
        'attempts' => $attempts,
        'payload' => ['objectId' => 'test-'.$key],
    ]);
}

function stageNineRunWebhook(HubSpotWebhookEvent $event, int $attempt): void
{
    $job = new ProcessHubSpotWebhookEvent((int) $event->id);
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $job->setJob($queueJob);
    app()->call([$job, 'handle']);
}

it('does not reclaim processing on a duplicate first delivery', function () {
    $event = stageNineWebhookEvent('duplicate', 'processing');
    stageNineRunWebhook($event, 1);

    expect($event->refresh()->status)->toBe('processing')
        ->and($event->attempts)->toBe(1);
});

it('resumes a processing event on the second queue attempt', function () {
    $event = stageNineWebhookEvent('retry', 'processing');
    stageNineRunWebhook($event, 2);

    expect($event->refresh()->status)->toBe('ignored')
        ->and($event->attempts)->toBe(2)
        ->and($event->processed_at)->not->toBeNull();
});

it('never reprocesses completed or blocked events', function () {
    foreach (['processed', 'ignored', 'blocked_scope'] as $status) {
        $event = stageNineWebhookEvent($status, $status);
        stageNineRunWebhook($event, 2);

        expect($event->refresh()->status)->toBe($status)
            ->and($event->attempts)->toBe(1);
    }
});

it('does not overwrite a completed event when an obsolete job fails', function () {
    $finished = stageNineWebhookEvent('finished', 'processed');
    $working = stageNineWebhookEvent('working', 'processing', 2);

    (new ProcessHubSpotWebhookEvent((int) $finished->id))
        ->failed(new RuntimeException('late failure'));

    (new ProcessHubSpotWebhookEvent((int) $working->id))
        ->failed(new RuntimeException('transient failure'));

    expect($finished->refresh()->status)->toBe('processed')
        ->and($working->refresh()->status)->toBe('failed')
        ->and($working->error)->toContain('transient failure');
});
