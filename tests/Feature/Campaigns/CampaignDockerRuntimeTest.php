<?php

use App\Jobs\SendEmailCampaignRecipient;

it('defines one dedicated campaign queue worker in the existing background profile', function (): void {
    $compose = file_get_contents(base_path('compose.yaml'));

    expect($compose)->not->toBeFalse();
    expect(substr_count($compose, '    campaigns-worker:'))->toBe(1);

    preg_match('/(?ms)^    campaigns-worker:\n(.*?)(?=^    [a-z][a-z0-9.-]*:|\z)/', $compose, $matches);
    $worker = $matches[1] ?? '';

    expect($worker)->toContain('profiles:')
        ->toContain('- background')
        ->toContain('queue:work')
        ->toContain('- redis')
        ->toContain('--queue=${CAMPAIGN_MAIL_QUEUE:-campaigns}')
        ->toContain('--tries=1')
        ->toContain('restart: unless-stopped');
});

it('dispatches campaigns to redis instead of depending on the app default queue', function (): void {
    $dispatcher = file_get_contents(app_path('Services/Campaigns/CampaignDispatcher.php'));

    expect($dispatcher)->not->toBeFalse();
    expect($dispatcher)->toContain("->onConnection('redis')")
        ->toContain("config('campaign_mail.queue', 'campaigns')");
    expect((new SendEmailCampaignRecipient(1))->tries)->toBe(1);
});

it('keeps campaign sending disabled by default', function (): void {
    $settings = file_get_contents(config_path('campaign_mail.php'));

    expect($settings)->not->toBeFalse();
    expect($settings)->toContain("'live_enabled' => (bool) env('CAMPAIGN_MAIL_LIVE_ENABLED', false)");
});
