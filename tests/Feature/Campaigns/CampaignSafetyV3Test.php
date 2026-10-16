<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function ecCampaignV3Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ecCampaignV3Fixture(string $status = 'running', int $total = 1): EmailCampaign
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'Homologação V3',
        'subject' => 'Teste de segurança {{primeiro_nome}}',
        'body' => 'Esta mensagem é usada apenas em testes automatizados.',
        'status' => $status,
        'from_address' => 'teste@exportcontrol.com.br',
        'from_name' => 'ExportControl Testes',
        'reply_to' => 'teste@exportcontrol.com.br',
    ]);

    foreach (range(1, $total) as $index) {
        EmailCampaignRecipient::query()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'destino'.$campaign->id.'-'.$index.'@example.invalid',
            'normalized_email' => 'destino'.$campaign->id.'-'.$index.'@example.invalid',
            'contact_name' => 'Contato V3 '.$index,
            'status' => 'pending',
        ]);
    }

    return $campaign;
}

function ecCampaignV3Handle(SendEmailCampaignRecipient $job): void
{
    $job->handle(
        app(CampaignDispatcher::class),
        app(CampaignContent::class),
        app(UnsubscribeToken::class),
    );
}

beforeEach(function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.from_address', 'teste@exportcontrol.com.br');
    config()->set('campaign_mail.per_hour', 25);
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.per_tick', 1);
    config()->set('campaign_mail.queue', 'campaigns');
    config()->set('app.url', 'https://prospector.exportcontrol.com.br');
    Queue::fake();
    Mail::fake();
});

it('blocks new reservations while live sending is disabled', function (): void {
    $campaign = ecCampaignV3Fixture();
    config()->set('campaign_mail.live_enabled', false);

    expect(app(CampaignDispatcher::class)->tick())->toBe(0)
        ->and($campaign->recipients()->firstOrFail()->status)->toBe('pending');
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('reserves with a unique UUID carried by the Redis job', function (): void {
    $campaign = ecCampaignV3Fixture();

    expect(app(CampaignDispatcher::class)->tick())->toBe(1);
    $recipient = $campaign->recipients()->firstOrFail();
    expect($recipient->status)->toBe('reserved')
        ->and($recipient->reservation_token)->toBeString()
        ->and(strlen((string) $recipient->reservation_token))->toBe(36);

    Queue::assertPushed(SendEmailCampaignRecipient::class, function (SendEmailCampaignRecipient $job) use ($recipient): bool {
        return $job->recipientId === $recipient->id
            && $job->reservationToken === $recipient->reservation_token
            && $job->connection === 'redis'
            && $job->queue === 'campaigns';
    });
    Mail::assertNothingSent();
});

it('invalidates an old queued job when paused and resumed', function (): void {
    $manager = ecCampaignV3Manager();
    $campaign = ecCampaignV3Fixture();
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $tokenBeforePause = (string) $recipient->reservation_token;

    app(CampaignManager::class)->pause($manager, $campaign);
    expect($campaign->fresh()->status)->toBe('paused')
        ->and($recipient->fresh()->status)->toBe('pending')
        ->and($recipient->fresh()->reservation_token)->toBeNull();

    app(CampaignManager::class)->resume($manager, $campaign->fresh());
    expect(app(CampaignDispatcher::class)->tick())->toBe(1);
    $recipient->refresh();
    $tokenAfterResume = (string) $recipient->reservation_token;
    expect($tokenAfterResume)->not->toBe($tokenBeforePause);

    ecCampaignV3Handle(new SendEmailCampaignRecipient((int) $recipient->id, $tokenBeforePause));
    expect($recipient->fresh()->status)->toBe('reserved');
    Mail::assertNothingSent();

    ecCampaignV3Handle(new SendEmailCampaignRecipient((int) $recipient->id, $tokenAfterResume));
    expect($recipient->fresh()->status)->toBe('sent');
    Mail::assertSentCount(1);
});

it('cancels reserved and pending recipients without altering in-flight attempts', function (): void {
    $manager = ecCampaignV3Manager();
    $campaign = ecCampaignV3Fixture('running', 3);
    app(CampaignDispatcher::class)->tick();
    $reserved = $campaign->recipients()->where('status', 'reserved')->firstOrFail();
    $oldToken = (string) $reserved->reservation_token;
    $inFlight = $campaign->recipients()->where('status', 'pending')->firstOrFail();
    $inFlight->update(['status' => 'sending', 'attempts' => 1, 'attempted_at' => now('UTC')]);

    app(CampaignManager::class)->cancel($manager, $campaign);
    expect($campaign->fresh()->status)->toBe('canceled')
        ->and($reserved->fresh()->status)->toBe('canceled')
        ->and($reserved->fresh()->reservation_token)->toBeNull()
        ->and($inFlight->fresh()->status)->toBe('sending')
        ->and($campaign->recipients()->where('status', 'pending')->count())->toBe(0);

    ecCampaignV3Handle(new SendEmailCampaignRecipient((int) $reserved->id, $oldToken));
    Mail::assertNothingSent();
});

it('never resends a successful SMTP acceptance when the job is executed again', function (): void {
    $campaign = ecCampaignV3Fixture();
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $job = new SendEmailCampaignRecipient((int) $recipient->id, (string) $recipient->reservation_token);

    ecCampaignV3Handle($job);
    ecCampaignV3Handle($job);

    expect($recipient->fresh()->status)->toBe('sent')
        ->and($recipient->fresh()->attempts)->toBe(1);
    Mail::assertSentCount(1);
});

it('invalidates a reservation when live sending is turned off before job execution', function (): void {
    $campaign = ecCampaignV3Fixture();
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $job = new SendEmailCampaignRecipient((int) $recipient->id, (string) $recipient->reservation_token);

    config()->set('campaign_mail.live_enabled', false);
    ecCampaignV3Handle($job);

    expect($recipient->fresh()->status)->toBe('pending')
        ->and($recipient->fresh()->reservation_token)->toBeNull()
        ->and($recipient->fresh()->reserved_at)->toBeNull();
    Mail::assertNothingSent();
});

it('blocks previously reserved jobs for unsubscribed addresses', function (): void {
    $campaign = ecCampaignV3Fixture();
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $job = new SendEmailCampaignRecipient((int) $recipient->id, (string) $recipient->reservation_token);
    app(CampaignManager::class)->suppress((string) $recipient->email);

    ecCampaignV3Handle($job);
    expect($recipient->fresh()->status)->toBe('suppressed')
        ->and($recipient->fresh()->reservation_token)->toBeNull();
    Mail::assertNothingSent();
});

it('respects one shared hourly quota across two running campaigns', function (): void {
    $first = ecCampaignV3Fixture();
    $second = ecCampaignV3Fixture();
    config()->set('campaign_mail.per_hour', 1);
    config()->set('campaign_mail.per_day', 500);

    expect(app(CampaignDispatcher::class)->tick())->toBe(1);
    // Mesmo com outra campanha ativa, a reserva global da hora já foi usada.
    expect(app(CampaignDispatcher::class)->tick())->toBe(0);
    expect($first->recipients()->where('status', 'reserved')->count()
        + $second->recipients()->where('status', 'reserved')->count())->toBe(1);
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
});

it('marks an SMTP exception as uncertain and does not retry automatically', function (): void {
    $campaign = ecCampaignV3Fixture();
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $job = new SendEmailCampaignRecipient((int) $recipient->id, (string) $recipient->reservation_token);

    // Nunca utiliza uma conexão de rede. A fachada Mail lança o erro simulado.
    Mail::shouldReceive('mailer')->once()->andThrow(new RuntimeException('SMTP fictício indisponível'));
    ecCampaignV3Handle($job);
    ecCampaignV3Handle($job);

    expect($recipient->fresh()->status)->toBe('uncertain')
        ->and($recipient->fresh()->attempts)->toBe(1)
        ->and($recipient->fresh()->message_id)->not->toBeNull()
        ->and($job->tries)->toBe(1);
});
