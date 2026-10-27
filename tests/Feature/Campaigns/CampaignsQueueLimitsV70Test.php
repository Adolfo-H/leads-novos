<?php

declare(strict_types=1);

use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignQueueSimulator;
use App\Services\Campaigns\CampaignSendPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('keeps the V70 queue on actual records, with a custom simulation and no technical tools section', function (): void {
    $queue = file_get_contents(resource_path('views/pages/campaigns/⚡queue.blade.php'));
    $widget = file_get_contents(resource_path('views/campaigns/fila-v70.blade.php'));
    $settings = file_get_contents(resource_path('views/pages/campaigns/⚡settings.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($queue)->toContain("@include('campaigns.fila-v70')")
        ->toContain('public function simulateCustom()')
        ->and($widget)->toContain('Envios e fila')
        ->toContain('data-campaign-queue="M10-V70"')
        ->toContain('wire:click="simulateCustom"')
        ->toContain("\$this->simulation['first_24_hours']")
        ->toContain('$this->health[$key]')
        ->toContain('E-mails devolvidos')
        ->and($settings)->not->toContain('Ferramentas e informações')
        ->and($css)->toContain("@import './campaigns-queue-v70.css';");
});

it('allows volumes over 500 when the approved SMTP capacity is explicitly configured', function (): void {
    config()->set('campaign_mail.smtp_approved_per_day', 2400);
    config()->set('campaign_mail.smtp_approved_per_hour', 250);
    config()->set('campaign_mail.smtp_approved_per_tick', 5);
    config()->set('campaign_mail.per_day', 2400);
    config()->set('campaign_mail.per_hour', 250);
    config()->set('campaign_mail.per_tick', 5);

    $policy = app(CampaignSendPolicy::class);
    expect($policy->quote(1200, 8)['possible'])->toBeTrue()
        ->and($policy->effective()['day'])->toBe(2400)
        ->and($policy->effective()['hour'])->toBe(250);

    $simulation = app(CampaignQueueSimulator::class)->report(1200);
    expect($simulation['requested'])->toBe(1200)
        ->and($simulation['daily_limit'])->toBe(2400)
        ->and($simulation['hourly_limit'])->toBe(250)
        ->and($simulation['first_24_hours'])->toBe(1200);
});

it('still blocks amounts higher than the authorized SMTP cap', function (): void {
    config()->set('campaign_mail.smtp_approved_per_day', 500);
    config()->set('campaign_mail.smtp_approved_per_hour', 100);
    config()->set('campaign_mail.smtp_approved_per_tick', 5);
    expect(app(CampaignSendPolicy::class)->quote(600, 24)['possible'])->toBeFalse();
});

it('never starts real sends when the live switch remains off after installing V70', function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.smtp_approved_per_day', 3000);
    Mail::fake();
    Queue::fake();

    expect(app(CampaignDispatcher::class)->tick())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
