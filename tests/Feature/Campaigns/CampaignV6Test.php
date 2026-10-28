<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignQueueSimulator;
use App\Services\Campaigns\CampaignReplyReader;
use App\Services\Campaigns\CampaignSendPolicy;
use App\Services\Campaigns\UnsubscribeToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function ecV6Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ecV6Draft(): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Validação V6',
        'subject' => 'Assunto para teste',
        'body' => 'Corpo fictício para um teste que não envia e-mails.',
        'status' => 'draft',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'teste@example.invalid',
    ]);
}

it('protects all three new screens with the manager middleware', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    foreach (['campaigns.replies', 'campaigns.queue', 'campaigns.settings'] as $route) {
        $this->get(route($route))->assertRedirect();
    }
    $this->actingAs($seller);
    foreach (['campaigns.replies', 'campaigns.queue', 'campaigns.settings'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->actingAs(ecV6Manager());
    $this->get(route('campaigns.replies'))->assertOk()->assertSee('Caixa de respostas');
    $this->get(route('campaigns.queue'))->assertOk()->assertSee('Envios e fila');
    $this->get(route('campaigns.settings'))->assertOk()->assertSee('Configurações de envio');
});

it('saves a plan without enabling commercial sending or replacing existing limits', function (): void {
    $user = ecV6Manager();
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.per_hour', 25);
    config()->set('campaign_mail.per_day', 500);
    $policy = app(CampaignSendPolicy::class);

    $policy->save($user, 500, 8, '08:00', false, false);
    expect($policy->snapshot()['active'])->toBeFalse()
        ->and($policy->effective()['hour'])->toBe(25)
        ->and(config('campaign_mail.live_enabled'))->toBeFalse();
});

it('calculates 500 messages in 8 hours as a 63 hourly cap and 2 per tick', function (): void {
    $policy = app(CampaignSendPolicy::class);
    expect($policy->quote(500, 8))->toMatchArray([
        'hourly_limit' => 63,
        'per_tick_limit' => 2,
        'possible' => true,
    ]);
});

it('requires an explicit capacity confirmation to activate a policy', function (): void {
    $user = ecV6Manager();
    config()->set('campaign_mail.live_enabled', false);
    $policy = app(CampaignSendPolicy::class);

    expect(fn () => $policy->save($user, 500, 8, '08:00', true, false))
        ->toThrow(ValidationException::class);
    expect(DB::table('email_campaign_send_policies')->count())->toBe(0);
});

it('enforces the daily window in Sao Paulo without changing the live flag', function (): void {
    $user = ecV6Manager();
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.live_enabled', false);
    $policy = app(CampaignSendPolicy::class);
    $policy->save($user, 500, 8, '08:00', true, true);
    $active = $policy->effective();

    expect($active['hour'])->toBe(63)
        ->and($active['day'])->toBe(500)
        ->and($active['tick'])->toBe(2)
        ->and($active['source'])->toBe('prospector')
        ->and($policy->windowOpen($active, CarbonImmutable::parse('2026-10-16 07:59:00', 'America/Sao_Paulo')))->toBeFalse()
        ->and($policy->windowOpen($active, CarbonImmutable::parse('2026-10-16 08:00:00', 'America/Sao_Paulo')))->toBeTrue()
        ->and($policy->windowOpen($active, CarbonImmutable::parse('2026-10-16 15:59:00', 'America/Sao_Paulo')))->toBeTrue()
        ->and($policy->windowOpen($active, CarbonImmutable::parse('2026-10-16 16:00:00', 'America/Sao_Paulo')))->toBeFalse()
        ->and(config('campaign_mail.live_enabled'))->toBeFalse();
});

it('simulates a saved schedule without queuing or sending mail', function (): void {
    $user = ecV6Manager();
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.per_day', 500);
    app(CampaignSendPolicy::class)->save($user, 500, 8, '08:00', true, true);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 07:58:00', 'America/Sao_Paulo'));
    Mail::fake();
    Queue::fake();
    $report = app(CampaignQueueSimulator::class)->report(500);

    expect($report['hourly_limit'])->toBe(63)
        ->and($report['per_tick_limit'])->toBe(2)
        ->and($report['first_24_hours'])->toBe(500);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('stores already-matched reply bodies encrypted and does not fetch the inbox twice', function (): void {
    $user = ecV6Manager();
    $campaign = ecV6Draft();
    $recipient = $campaign->recipients()->create([
        'email' => 'contato@example.invalid', 'normalized_email' => 'contato@example.invalid',
        'status' => 'sent',
    ]);
    $reply = EmailCampaignReply::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'teste@example.invalid',
        'uid_validity' => '123',
        'imap_uid' => 1234,
        'from_email' => 'contato@example.invalid',
        'subject' => 'Re: Teste',
        'received_at' => now('UTC'),
    ]);
    $reply->update(['body_text' => 'Mensagem privada de teste', 'body_loaded_at' => now('UTC')]);
    $stored = DB::table('email_campaign_replies')->where('id', $reply->id)->value('body_text');

    $this->actingAs($user);
    expect((string) $stored)->not->toContain('Mensagem privada de teste')
        ->and(app(CampaignReplyReader::class)->readCampaignReply((int) $reply->id))
        ->toBe('Mensagem privada de teste');
});

it('shows matched reply metadata in the UI without importing unrelated inbox messages', function (): void {
    $user = ecV6Manager();
    $campaign = ecV6Draft();
    $recipient = $campaign->recipients()->create([
        'email' => 'resposta@example.invalid', 'normalized_email' => 'resposta@example.invalid',
        'status' => 'sent',
    ]);
    EmailCampaignReply::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'teste@example.invalid',
        'uid_validity' => '123',
        'imap_uid' => 10,
        'from_email' => 'resposta@example.invalid',
        'subject' => 'Re: Uma conversa',
        'received_at' => now('UTC'),
    ]);

    $this->actingAs($user)->get(route('campaigns.replies'))
        ->assertOk()->assertSee('resposta@example.invalid');
});

it('keeps settings inaccessible to sellers even when invoked through Livewire', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    Livewire::actingAs($seller)->test('pages::campaigns.settings')->assertSee('Configurações de envio');

    // A tela é consultável, mas o serviço de configuração global continua 403.
    expect(fn () => app(CampaignSendPolicy::class)
        ->save($seller, 100, 8, '08:00', false, false))
        ->toThrow(HttpException::class);
});

it('does not start an already-reserved SMTP job outside the configured window', function (): void {
    $user = ecV6Manager();
    config()->set('app.timezone', 'America/Sao_Paulo');
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.live_enabled', false);
    app(CampaignSendPolicy::class)->save($user, 500, 8, '08:00', true, true);

    $this->travelTo(CarbonImmutable::parse('2026-10-16 16:05:00', 'America/Sao_Paulo'));
    $campaign = ecV6Draft();
    $campaign->update(['status' => 'running']);
    $token = 'v6-test-reservation-token';
    $recipient = $campaign->recipients()->create([
        'email' => 'naoenviar@example.invalid',
        'normalized_email' => 'naoenviar@example.invalid',
        'status' => 'reserved',
        'reserved_at' => now('UTC'),
        'reservation_token' => $token,
    ]);
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    Mail::fake();
    (new SendEmailCampaignRecipient((int) $recipient->id, $token))->handle(
        app(CampaignDispatcher::class),
        app(CampaignContent::class),
        app(UnsubscribeToken::class),
    );
    expect($recipient->fresh()->status)->toBe('pending');
    Mail::assertNothingSent();
});

it('treats a smaller activated target as the real 24-hour global ceiling', function (): void {
    $user = ecV6Manager();
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.live_enabled', false);
    app(CampaignSendPolicy::class)->save($user, 120, 8, '09:00', true, true);
    $limits = app(CampaignSendPolicy::class)->effective();
    expect($limits['day'])->toBe(120)
        ->and($limits['hour'])->toBe(15)
        ->and($limits['tick'])->toBe(1);
});
