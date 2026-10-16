<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignQueueSimulator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('does not reset the global 500-recipient limit at midnight', function (): void {
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.per_hour', 100);
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.per_tick', 1);

    $start = Carbon::parse('2026-10-15 23:58:00', 'America/Sao_Paulo');
    $this->travelTo($start);
    $campaign = EmailCampaign::query()->create([
        'name' => 'Simulacao bloqueio 500',
        'subject' => 'Somente teste',
        'body' => 'Nenhuma mensagem e enviada neste teste.',
        'status' => 'running',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'teste@example.invalid',
    ]);

    // Reservas feitas duas horas antes: contam em 24h, mas nao em 60 minutos.
    $reservedAt = now('UTC')->subHours(2)->format('Y-m-d H:i:s');
    foreach (range(0, 4) as $batch) {
        $rows = [];
        for ($offset = 0; $offset < 100; $offset++) {
            $i = $batch * 100 + $offset;
            $email = 'reservado'.$i.'@example.invalid';
            $rows[] = [
                'email_campaign_id' => $campaign->id,
                'email' => $email,
                'normalized_email' => $email,
                'status' => 'sent',
                'reserved_at' => $reservedAt,
            ];
        }
        EmailCampaignRecipient::query()->insert($rows);
    }
    $pending = EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'pendente@example.invalid',
        'normalized_email' => 'pendente@example.invalid',
        'status' => 'pending',
    ]);

    Mail::fake();
    Queue::fake();
    expect(app(CampaignDispatcher::class)->tick())->toBe(0);
    $this->travelTo($start->copy()->addMinutes(5));
    expect(app(CampaignDispatcher::class)->tick())->toBe(0)
        ->and($pending->fresh()->status)->toBe('pending');
    Queue::assertNothingPushed();
    Mail::assertNothingSent();

    // Apenas quando as 500 reservas passam de 24h uma nova reserva pode entrar.
    $this->travelTo($start->copy()->addDay()->addMinutes(3));
    expect(app(CampaignDispatcher::class)->tick())->toBe(1)
        ->and($pending->fresh()->status)->toBe('reserved');
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
});

it('simulates 500 in 24h only when the hourly limit allows it', function (): void {
    $this->travelTo(Carbon::parse('2026-10-15 08:00:00', 'America/Sao_Paulo'));
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.per_tick', 1);
    Mail::fake();
    Queue::fake();

    config()->set('campaign_mail.per_hour', 10);
    $slow = app(CampaignQueueSimulator::class)->report(500);
    expect($slow['daily_limit'])->toBe(500)
        ->and($slow['first_24_hours'])->toBeLessThan(500)
        ->and($slow['safe_mode'])->toBeTrue();

    config()->set('campaign_mail.per_hour', 25);
    $fast = app(CampaignQueueSimulator::class)->report(500);
    expect($fast['first_24_hours'])->toBe(500)
        ->and($fast['remaining'])->toBe(0)
        ->and($fast['hourly_limit'])->toBe(25);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('models a rolling 24h quota even when the simulation crosses midnight', function (): void {
    $this->travelTo(Carbon::parse('2026-10-15 23:58:00', 'America/Sao_Paulo'));
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.per_day', 30);
    config()->set('campaign_mail.per_hour', 100);
    config()->set('campaign_mail.per_tick', 5);

    $projection = app(CampaignQueueSimulator::class)->report(100);
    expect($projection['first_calendar_day'])->toBe(10)
        ->and($projection['first_24_hours'])->toBe(30)
        ->and($projection['daily_limit'])->toBe(30);
});

it('never queues an actual campaign while the live switch is off', function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.per_day', 500);
    Mail::fake();
    Queue::fake();

    expect(app(CampaignDispatcher::class)->tick())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
