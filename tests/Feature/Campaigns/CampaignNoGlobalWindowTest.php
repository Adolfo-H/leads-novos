<?php

declare(strict_types=1);

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignSendPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function noWindowManager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function noWindowConfigureLimits(): void
{
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.smtp_approved_per_hour', 1000);
    config()->set('campaign_mail.smtp_approved_per_day', 24000);
    config()->set('campaign_mail.smtp_approved_per_tick', 17);
    config()->set('campaign_mail.per_hour', 1000);
    config()->set('campaign_mail.per_day', 2000);
    config()->set('campaign_mail.per_tick', 17);
}

it('saves only global SMTP quotas without an active clock window', function (): void {
    noWindowConfigureLimits();
    config()->set('campaign_mail.live_enabled', false);
    $policy = app(CampaignSendPolicy::class);

    expect(fn () => $policy->saveLimits(noWindowManager(), 2000, 1000, 17, true, false))
        ->toThrow(ValidationException::class);

    $policy->saveLimits(noWindowManager(), 2000, 1000, 17, true, true);
    $snapshot = $policy->snapshot();
    $effective = $policy->effective();

    expect($snapshot['target_volume'])->toBe(2000)
        ->and($snapshot['duration_hours'])->toBe(24)
        ->and($snapshot['window_start'])->toBe('00:00')
        ->and($effective['day'])->toBe(2000)
        ->and($effective['hour'])->toBe(1000)
        ->and($effective['tick'])->toBe(17)
        ->and($effective['window_active'])->toBeFalse()
        ->and($effective['duration_hours'])->toBe(24)
        ->and(config('campaign_mail.live_enabled'))->toBeFalse();
});

it('rejects limits that exceed the authorized SMTP capacity', function (): void {
    noWindowConfigureLimits();
    $policy = app(CampaignSendPolicy::class);
    $manager = noWindowManager();

    expect(fn () => $policy->saveLimits($manager, 2001, 1000, 17, true, true))
        ->toThrow(ValidationException::class)
        ->and(fn () => $policy->saveLimits($manager, 2000, 1001, 17, true, true))
        ->toThrow(ValidationException::class)
        ->and(fn () => $policy->saveLimits($manager, 2000, 1000, 18, true, true))
        ->toThrow(ValidationException::class);
    expect(DB::table('email_campaign_send_policies')->count())->toBe(0);
});

it('reserves pending recipients outside the old time window without sending real SMTP', function (): void {
    noWindowConfigureLimits();
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    // Simula a política LEGADA configurada para 08:00-10:00.
    app(CampaignSendPolicy::class)->save(noWindowManager(), 2000, 2, '08:00', true, true);

    $this->travelTo(CarbonImmutable::parse('2026-11-17 11:33:00', 'America/Sao_Paulo'));

    $campaign = EmailCampaign::query()->create([
        'name' => 'Teste sem janela',
        'subject' => 'Assunto fictício',
        'body' => 'Nenhuma mensagem real pode ser enviada neste teste.',
        'status' => 'running',
        'from_address' => 'naoenviar@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'naoenviar@example.invalid',
    ]);
    $pending = $campaign->recipients()->create([
        'email' => 'pendente@example.invalid',
        'normalized_email' => 'pendente@example.invalid',
        'status' => 'pending',
    ]);
    $canceled = $campaign->recipients()->create([
        'email' => 'cancelado@example.invalid',
        'normalized_email' => 'cancelado@example.invalid',
        'status' => 'canceled',
    ]);

    Queue::fake();
    Mail::fake();

    expect(app(CampaignDispatcher::class)->tick())->toBe(1)
        ->and($pending->fresh()->status)->toBe('reserved')
        ->and($canceled->fresh()->status)->toBe('canceled')
        ->and($canceled->fresh()->attempts)->toBe(0);
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
    expect(app(CampaignDispatcher::class)->tick())->toBe(0);
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
});

it('has no production call sites that block dispatch, worker or simulation by the old clock', function (): void {
    $paths = [
        app_path('Services/Campaigns/CampaignDispatcher.php'),
        app_path('Jobs/SendEmailCampaignRecipient.php'),
        app_path('Services/Campaigns/CampaignQueueSimulator.php'),
    ];
    foreach ($paths as $path) {
        expect(file_get_contents($path))->not->toContain('->windowOpen(');
    }
    $settings = file_get_contents(resource_path('views/pages/campaigns/⚡settings.blade.php'));
    expect($settings)->not->toContain('ec22-start')
        ->not->toContain('Horário de início da janela')
        ->not->toContain('Duração da janela de envio')
        ->toContain('Limites SMTP globais');
});
