<?php

declare(strict_types=1);

use App\Services\Campaigns\CampaignSendPolicy;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('uses the explicitly authorized SMTP quota rather than an obsolete fixed daily or hourly cap', function (): void {
    Mail::fake();
    Queue::fake();

    config()->set('campaign_mail.smtp_approved_per_hour', 1000);
    config()->set('campaign_mail.smtp_approved_per_day', 24000);
    config()->set('campaign_mail.smtp_approved_per_tick', 17);
    config()->set('campaign_mail.live_enabled', false);

    $policy = app(CampaignSendPolicy::class);

    expect($policy->quote(500, 1)['possible'])->toBeTrue()
        ->and($policy->quote(500, 2)['possible'])->toBeTrue()
        ->and($policy->quote(24000, 24)['possible'])->toBeTrue()
        ->and($policy->quote(24001, 24)['possible'])->toBeFalse()
        ->and(config('campaign_mail.live_enabled'))->toBeFalse();

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('rejects a rate above the explicitly authorized hourly or per-tick SMTP quota', function (): void {
    config()->set('campaign_mail.smtp_approved_per_hour', 100);
    config()->set('campaign_mail.smtp_approved_per_day', 500);
    config()->set('campaign_mail.smtp_approved_per_tick', 5);

    $policy = app(CampaignSendPolicy::class);

    expect($policy->quote(500, 1)['possible'])->toBeFalse()
        ->and($policy->quote(500, 2)['possible'])->toBeFalse()
        ->and($policy->quote(500, 8)['possible'])->toBeTrue();
});
