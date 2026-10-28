<?php

use App\Services\Campaigns\CampaignSendPolicy;
use Illuminate\Support\Facades\Route;

it('shows the simplified campaign navigation without deleting operational routes', function (): void {
    $nav = file_get_contents(resource_path('views/campaigns/module-nav.blade.php'));
    expect($nav)->not->toBeFalse();
    expect($nav)->toContain('EC_SIMPLE_NAV_PACK20')
        ->toContain('Respostas')
        ->toContain('Configurações gerais')
        ->not->toContain('<span>Envios e fila</span>');
    foreach (['campaigns.index', 'campaigns.replies', 'campaigns.settings', 'campaigns.queue', 'campaigns.production.index', 'campaigns.intelligence.index'] as $name) {
        expect(Route::has($name))->toBeTrue();
    }
});

it('keeps SMTP and daily limits enforced for unrealistic sending plans', function (): void {
    // A asserção verifica limites explicitamente definidos pelo teste,
    // sem assumir que o ambiente real ainda está limitado a 100/h e 500/dia.
    config()->set('campaign_mail.smtp_approved_per_hour', 100);
    config()->set('campaign_mail.smtp_approved_per_day', 500);
    config()->set('campaign_mail.smtp_approved_per_tick', 5);

    $policy = app(CampaignSendPolicy::class);
    // EC77_SMTP_QUOTES_TESTS: não presumir um teto fixo de 100/h após V70.
    $daily = max(1, (int) config('campaign_mail.smtp_approved_per_day', 500));
    expect($policy->quote(500, 1)['hourly_limit'])->toBe(500)
        ->and($policy->quote(500, 2)['hourly_limit'])->toBe(250)
        ->and($policy->quote(500, 8)['hourly_limit'])->toBe(63)
        ->and($policy->quote($daily + 1, 8)['possible'])->toBeFalse();
});

it('keeps actual sending under explicit authorization', function (): void {
    $view = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    expect($view)->not->toBeFalse();
    expect($view)->toContain('saveAndStart')
        ->toContain('lawfulBasisConfirmed')
        ->toContain("config('campaign_mail.live_enabled')")
        ->toContain('policy')
        ->toContain("'pace'");
});
