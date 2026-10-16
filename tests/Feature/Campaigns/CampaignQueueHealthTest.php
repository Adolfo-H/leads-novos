<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignQueueHealth;
use App\Services\Campaigns\UnsubscribeToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function campaignQueueHealthFixture(string $status, ?string $timestamp = null): EmailCampaignRecipient
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'Auditoria interna sem disparos',
        'subject' => 'Somente teste',
        'body' => 'Nenhum destinatario real recebera esta mensagem.',
        'status' => 'draft',
        'from_address' => 'teste@exportcontrol.com.br',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@exportcontrol.com.br',
    ]);

    return EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'nao-enviar@example.invalid',
        'normalized_email' => 'nao-enviar@example.invalid',
        'status' => $status,
        'reserved_at' => $status === 'reserved' ? $timestamp : null,
        'attempted_at' => $status === 'sending' ? $timestamp : null,
    ]);
}

it('reports queue state without changing recipients or sending email', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $recipient = campaignQueueHealthFixture('reserved', CarbonImmutable::now('UTC')->subMinutes(45)->format('Y-m-d H:i:s'));
    $health = app(CampaignQueueHealth::class)->snapshot();

    expect($health['safe_mode'])->toBeTrue()
        ->and($health['reserved'])->toBe(1)
        ->and($health['stale_reserved'])->toBe(1)
        ->and($health['stale_sending'])->toBe(0)
        ->and($recipient->fresh()->status)->toBe('reserved');

    expect(Artisan::call('campaigns:queue-status'))->toBe(0)
        ->and(Artisan::call('campaigns:queue-status', ['--check' => true]))->toBe(1)
        ->and($recipient->fresh()->status)->toBe('reserved');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('detects a stuck attempt without turning it back into a pending message', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    $recipient = campaignQueueHealthFixture('sending', CarbonImmutable::now('UTC')->subMinutes(60)->format('Y-m-d H:i:s'));

    $health = app(CampaignQueueHealth::class)->snapshot();

    expect($health['stale_sending'])->toBe(1)
        ->and($health['uncertain'])->toBe(0)
        ->and($recipient->fresh()->status)->toBe('sending');

    Artisan::call('campaigns:queue-status', ['--check' => true]);

    expect($recipient->fresh()->status)->toBe('sending');
});

it('does not flag recent reserved or sending recipients', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    campaignQueueHealthFixture('reserved', CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i:s'));
    campaignQueueHealthFixture('sending', CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i:s'));

    $health = app(CampaignQueueHealth::class)->snapshot();

    expect($health['stale_reserved'])->toBe(0)
        ->and($health['stale_sending'])->toBe(0)
        ->and(Artisan::call('campaigns:queue-status', ['--check' => true]))->toBe(0);
});

it('does not send a reserved email if campaign is paused', function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    Mail::fake();
    $recipient = campaignQueueHealthFixture('reserved', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    $recipient->campaign->update(['status' => 'paused']);

    (new SendEmailCampaignRecipient((int) $recipient->id))->handle(
        app(CampaignDispatcher::class),
        app(CampaignContent::class),
        app(UnsubscribeToken::class),
    );

    expect($recipient->fresh()->status)->toBe('pending');
    Mail::assertNothingSent();
});

it('does not send a reserved email if campaign was canceled', function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    Mail::fake();
    $recipient = campaignQueueHealthFixture('reserved', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    $recipient->campaign->update(['status' => 'canceled']);

    (new SendEmailCampaignRecipient((int) $recipient->id))->handle(
        app(CampaignDispatcher::class),
        app(CampaignContent::class),
        app(UnsubscribeToken::class),
    );

    expect($recipient->fresh()->status)->toBe('canceled');
    Mail::assertNothingSent();
});

it('does not dispatch or send when commercial sending is disabled', function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();
    $recipient = campaignQueueHealthFixture('reserved', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    $recipient->campaign->update(['status' => 'running']);

    (new SendEmailCampaignRecipient((int) $recipient->id))->handle(
        app(CampaignDispatcher::class),
        app(CampaignContent::class),
        app(UnsubscribeToken::class),
    );

    expect(app(CampaignDispatcher::class)->tick())->toBe(0)
        ->and($recipient->fresh()->status)->toBe('pending');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('ignores the second attempt when a queued job is delivered twice', function (): void {
    config()->set('app.url', 'https://prospector.exportcontrol.com.br');
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.mailer', 'smtp');
    Mail::fake();
    $recipient = campaignQueueHealthFixture('reserved', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    $recipient->campaign->update(['status' => 'running']);
    $job = new SendEmailCampaignRecipient((int) $recipient->id);

    $job->handle(app(CampaignDispatcher::class), app(CampaignContent::class), app(UnsubscribeToken::class));
    $job->handle(app(CampaignDispatcher::class), app(CampaignContent::class), app(UnsubscribeToken::class));

    expect($recipient->fresh()->status)->toBe('sent');
    Mail::assertSent(ProspectorCampaignMessage::class, 1);
});

it('keeps scheduler and campaign worker separation', function (): void {
    $compose = file_get_contents(base_path('compose.yaml'));
    expect($compose)->not->toBeFalse();

    expect($compose)->toContain('    scheduler:')
        ->toContain('    campaigns-worker:')
        ->toContain('--queue=${CAMPAIGN_MAIL_QUEUE:-campaigns}');
});
