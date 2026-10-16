<?php

use App\Livewire\CampaignQueueLab;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\Campaigns\CampaignQueueSimulator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function campaignQueueSimulationManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('projects hourly and calendar-day limits without real dispatches', function (): void {
    $this->travelTo(Carbon::parse('2026-10-15 08:00:00', 'America/Sao_Paulo'));
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.per_hour', 10);
    config()->set('campaign_mail.per_day', 30);
    config()->set('campaign_mail.per_tick', 1);

    Mail::fake();
    Queue::fake();
    $report = app(CampaignQueueSimulator::class)->report(50);

    expect($report['requested'])->toBe(50)
        ->and($report['safe_mode'])->toBeTrue()
        ->and($report['first_hour'])->toBe(10)
        ->and($report['first_calendar_day'])->toBe(30)
        ->and($report['within_seven_days'])->toBe(50)
        ->and($report['remaining'])->toBe(0)
        ->and($report['completed'])->toBeTrue()
        ->and($report['completion_minutes'])->toBeInt();

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('respects stricter limits for hypothetical recipients and does not invent capacity', function (): void {
    $this->travelTo(Carbon::parse('2026-10-15 08:00:00', 'America/Sao_Paulo'));
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.per_hour', 1);
    config()->set('campaign_mail.per_day', 1);
    config()->set('campaign_mail.per_tick', 1);

    $report = app(CampaignQueueSimulator::class)->report(100);

    expect($report['first_hour'])->toBe(1)
        ->and($report['first_calendar_day'])->toBe(1)
        ->and($report['within_seven_days'])->toBeLessThan(100)
        ->and($report['remaining'])->toBeGreaterThan(0)
        ->and($report['completed'])->toBeFalse()
        ->and($report['completion_minutes'])->toBeNull();
});

it('shows real queue states without modifying them or invoking mail and jobs', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $campaign = EmailCampaign::query()->create([
        'name' => 'Somente auditoria',
        'subject' => 'Nao enviar',
        'body' => 'Dados ficticios para teste automatizado.',
        'status' => 'draft',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'teste@example.invalid',
    ]);
    $recipient = EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'nao-enviar@example.invalid',
        'normalized_email' => 'nao-enviar@example.invalid',
        'status' => 'uncertain',
    ]);
    $report = app(CampaignQueueSimulator::class)->report(10);

    expect($report['actual']['uncertain'])->toBe(1)
        ->and($report['actual']['drafts'])->toBe(1)
        ->and($recipient->fresh()->status)->toBe('uncertain');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('provides an artisan simulation with no effects on campaigns', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    expect(Artisan::call('campaigns:simulate', ['--recipients' => '50']))->toBe(0);
    expect(Artisan::output())->toContain('SIMULAÇÃO')
        ->toContain('NENHUM ENVIO REAL')
        ->toContain('50');
    expect(Artisan::call('campaigns:simulate', ['--recipients' => '0']))->toBe(1);
    expect(Artisan::call('campaigns:simulate', ['--recipients' => '999']))->toBe(1);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
    expect(EmailCampaignRecipient::query()->count())->toBe(0);
});

it('restricts the simulation widget to commercial managers', function (): void {
    $manager = campaignQueueSimulationManager();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($manager)->test(CampaignQueueLab::class)
        ->assertSee('Simulador de fila')
        ->assertSee('SEM ENVIOS')
        ->set('recipients', 100)
        ->assertSet('recipients', 100)
        ->call('refresh')
        ->assertSee('100 contatos');

    Livewire::actingAs($seller)->test(CampaignQueueLab::class)
        ->assertForbidden();
});

it('includes the simulator inside the campaign UX rather than registering another scheduler', function (): void {
    $view = (string) file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $routes = (string) file_get_contents(base_path('routes/console.php'));

    expect($view)->toContain('<livewire:campaign-queue-lab />')
        ->and($routes)->not->toContain('campaigns:simulate');
});
