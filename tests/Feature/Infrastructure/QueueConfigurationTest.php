<?php

use App\Jobs\EnrichImportItem;
use App\Jobs\HubSpotQueueHeartbeat;
use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Jobs\QueueWorkerHeartbeat;
use App\Jobs\ResearchCompanyExports;
use App\Jobs\SendCampaignPilotMessage;
use App\Jobs\SendEmailCampaignRecipient;
use App\Jobs\SyncCompanyToHubSpot;
use App\Jobs\SyncHubSpotCompanyRealtimeStatus;
use App\Jobs\SyncManualHubSpotOpportunity;
use Illuminate\Console\Scheduling\Schedule;

it('keeps redis retry after above the longest queued job timeout', function () {
    $retryAfter =
        (int) config(
            'queue.connections.redis.retry_after'
        );

    $longestJobTimeout =
        max(
            (new EnrichImportItem(1))->timeout,
            (new ResearchCompanyExports(1))->timeout,
            (new SyncCompanyToHubSpot(1))->timeout,
            (new ProcessHubSpotWebhookEvent(1))->timeout,
            (new SendEmailCampaignRecipient(1))->timeout,
            (new SendCampaignPilotMessage(1))->timeout,
            (new SyncHubSpotCompanyRealtimeStatus(1))->timeout,
            (new SyncManualHubSpotOpportunity(1, 1))->timeout,
            (new HubSpotQueueHeartbeat('hubspot-webhooks'))->timeout,
            (new QueueWorkerHeartbeat('default'))->timeout,
        );

    expect(
        $retryAfter
    )->toBeGreaterThan(
        $longestJobTimeout
    );
});

it('keeps database retry after above the longest queued job timeout', function () {
    $retryAfter =
        (int) config(
            'queue.connections.database.retry_after'
        );

    $longestJobTimeout =
        max(
            (new EnrichImportItem(1))->timeout,
            (new ResearchCompanyExports(1))->timeout,
            (new SyncCompanyToHubSpot(1))->timeout,
            (new ProcessHubSpotWebhookEvent(1))->timeout,
            (new SendEmailCampaignRecipient(1))->timeout,
            (new SendCampaignPilotMessage(1))->timeout,
            (new SyncHubSpotCompanyRealtimeStatus(1))->timeout,
            (new SyncManualHubSpotOpportunity(1, 1))->timeout,
            (new HubSpotQueueHeartbeat('hubspot-webhooks'))->timeout,
            (new QueueWorkerHeartbeat('default'))->timeout,
        );

    expect(
        $retryAfter
    )->toBeGreaterThan(
        $longestJobTimeout
    );
});

it('keeps failed jobs persisted and worker queues isolated', function () {
    $compose = file_get_contents(base_path('compose.yaml'));

    expect(config('queue.failed.driver'))->toBe('database-uuids');
    expect(config('queue.failed.table'))->toBe('failed_jobs');
    expect($compose)->not->toBeFalse();
    expect($compose)->toContain('--queue=hubspot-webhooks');
    expect($compose)->toContain('--queue=hubspot-realtime');
    expect($compose)->toContain('--queue=hubspot-bulk');
    expect($compose)->toContain('--queue=${CAMPAIGN_MAIL_QUEUE:-campaigns}');
});

it('keeps scheduled commands single-node and protected from overlap', function () {
    $events = app(Schedule::class)->events();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->onOneServer)->toBeTrue();
        expect($event->withoutOverlapping)->toBeTrue();
    }
});
