<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignPacing;
use App\Services\Campaigns\CampaignReplyMailbox;
use App\Services\Campaigns\CampaignTimingPreview;
use App\Services\Campaigns\DedicatedCampaignProbe;
use App\Services\Campaigns\DualCampaignSenders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function ecV26Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ecV26Draft(): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Diagnóstico V26', 'subject' => 'Teste de homologação', 'body' => 'Mensagem fictícia, somente teste.',
        'status' => 'draft', 'from_address' => 'legacy@example.invalid',
        'from_name' => 'Teste', 'reply_to' => 'legacy@example.invalid',
    ]);
}

function ecV26Config(int $minute = 8): void
{
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.imap_enabled', true);
    config()->set('campaign_mail.test_to', 'internal@example.test');
    config()->set('campaign_mail.max_recipients', 1000);
    config()->set('campaign_mail.per_hour', 500);
    config()->set('campaign_mail.per_day', 24000);
    config()->set('campaign_mail.per_tick', $minute);
    config()->set('campaign_mail.smtp_approved_per_hour', 500);
    config()->set('campaign_mail.smtp_approved_per_day', 24000);
    config()->set('campaign_mail.smtp_approved_per_tick', $minute);
    foreach (DualCampaignSenders::KEYS as $key) {
        $address = $key.'@exportcontrol.com.br';
        config()->set('campaign_sender_profiles.'.$key, [
            'address' => $address, 'smtp_host' => 'mail.exportcontrol.com.br',
            'smtp_port' => 465, 'smtp_security' => 'tls', 'smtp_username' => $address,
            'smtp_password' => 'FAKE_ONLY', 'imap_host' => 'mail.exportcontrol.com.br',
            'imap_port' => 993, 'imap_username' => $address, 'imap_password' => 'FAKE_ONLY',
        ]);
        app(CampaignInbox::class)->import($address, [
            'uidvalidity' => '123456', 'last_uid' => 1, 'initialized' => true, 'messages' => [],
        ]);
    }
    DualCampaignSenders::authorize('TESTE — autorização simulada');
}

it('estimates automatic timing from shared quotas and retains manual estimates', function (): void {
    $policy = ['hour' => 500, 'tick' => 8, 'day' => 24000];
    expect(CampaignTimingPreview::estimate(2, 0, $policy))->toBe(['hour' => 500, 'tick' => 8, 'day' => 24000, 'minutes' => 1])
        ->and(CampaignTimingPreview::estimate(9, 0, $policy)['minutes'])->toBe(2)
        ->and(CampaignTimingPreview::estimate(480, 0, $policy)['minutes'])->toBe(60)
        ->and(CampaignTimingPreview::estimate(500, 0, $policy)['minutes'])->toBe(63)
        ->and(CampaignTimingPreview::estimate(2, 8, $policy)['minutes'])->toBe(61)
        ->and(CampaignTimingPreview::estimate(3, 0, ['hour' => 8, 'tick' => 8, 'day' => 2])['minutes'])->toBe(1441);
});

it('defaults the HTTP wizard to global limits and reserves both approved recipients in one fake scheduler tick', function (): void {
    ecV26Config();
    $user = ecV26Manager();
    $wizardId = '93060000-0000-4000-8000-000000000026';
    $sessionKey = 'campaigns_wizard_v93_'.$wizardId;
    HubSpotContact::query()->create(['hubspot_id' => '932601', 'first_name' => 'Ana', 'email' => 'ana@example.test']);
    HubSpotContact::query()->create(['hubspot_id' => '932602', 'first_name' => 'Bia', 'email' => 'bia@example.test']);
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'reiniciar' => 1]))->assertOk();
    expect(session($sessionKey)['hours'])->toBe('0');
    $this->post(route('campaigns.wizard-v93.selection'), [
        'wizard_id' => $wizardId, 'action' => 'continue',
        'visible' => ['hubspot:932601', 'hubspot:932602'], 'keys' => ['hubspot:932601', 'hubspot:932602'],
        'expected' => ['hubspot:932601' => 'ana@example.test', 'hubspot:932602' => 'bia@example.test'],
    ])->assertRedirect();
    $this->post(route('campaigns.wizard-v93.message'), [
        'wizard_id' => $wizardId, 'name' => 'Automático V26', 'subject' => 'Teste de ritmo', 'body' => 'Mensagem fictícia para validar o rascunho.',
    ])->assertRedirect();
    $this->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'ritmo']))
        ->assertOk()->assertSee('Automático — limites globais')->assertSee('1 min');
    $this->post(route('campaigns.wizard-v93.pace'), ['wizard_id' => $wizardId, 'hours' => 0])->assertRedirect();
    $this->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'revisao']))
        ->assertOk()->assertSee('Automático — limites globais')->assertSee('1 min');
    $this->post(route('campaigns.wizard-v93.save'), ['wizard_id' => $wizardId])->assertRedirect();
    $campaign = EmailCampaign::query()->where('name', 'Automático V26')->firstOrFail();
    $pacing = app(CampaignPacing::class);
    expect($campaign->status)->toBe('draft')
        ->and($campaign->recipients()->count())->toBe(2)
        ->and($pacing->limits((int) $campaign->id))->toBeNull()
        ->and($pacing->plannedHours((int) $campaign->id))->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();

    // Prepare the approved state in the isolated test database. Queue remains fake.
    config()->set('campaign_mail.live_enabled', true);
    DB::transaction(static fn () => DualCampaignSenders::prepare($campaign));
    $campaign->update(['status' => 'running']);
    expect(app(CampaignDispatcher::class)->tick())->toBe(2)
        ->and($campaign->recipients()->where('status', 'reserved')->count())->toBe(2)
        ->and($campaign->recipients()->orderBy('id')->pluck('sender_profile')->all())->toBe(['publicidade', 'propaganda']);
    Queue::assertPushed(SendEmailCampaignRecipient::class, 2);
    Mail::assertNothingSent();
});

it('keeps manual pacing for existing drafts and cannot alter an already running campaign through automatic mode', function (): void {
    Mail::fake();
    $draft = ecV26Draft();
    $draft->recipients()->create(['email' => 'a@example.test', 'normalized_email' => 'a@example.test', 'status' => 'pending']);
    $pacing = app(CampaignPacing::class);
    $pacing->save($draft, 8);
    expect($pacing->plannedHours((int) $draft->id))->toBe(8);
    $pacing->useGlobal($draft);
    expect($pacing->limits((int) $draft->id))->toBeNull();
    $pacing->save($draft, 8);
    $draft->update(['status' => 'running']);
    expect(fn () => $pacing->useGlobal($draft))->toThrow(InvalidArgumentException::class);
    expect($pacing->plannedHours((int) $draft->id))->toBe(8);
    Mail::assertNothingSent();
});

it('allows distinct manual internal probes beyond two per hour but deduplicates the request and honors the global minute limit', function (): void {
    ecV26Config(3);
    $user = ecV26Manager();
    $campaign = ecV26Draft();
    $probe = app(DedicatedCampaignProbe::class);
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    $third = (string) Str::uuid();
    $probe->send($user, $campaign, 'publicidade', $first);
    expect(fn () => $probe->send($user, $campaign, 'publicidade', $first))->toThrow(ValidationException::class);
    $probe->send($user, $campaign, 'publicidade', $second);
    $probe->send($user, $campaign, 'propaganda', $third);
    expect(fn () => $probe->send($user, $campaign, 'propaganda', (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(DedicatedCampaignProbe::attemptsSince(now('UTC')->subHour()))->toBe(3)
        ->and(EmailCampaignTestMessage::query()->distinct()->count('message_id'))->toBe(3)
        ->and(EmailCampaignRecipient::query()->count())->toBe(0)
        ->and($campaign->fresh()->status)->toBe('draft');
    Mail::assertSentCount(3);
    Queue::assertNothingPushed();
    foreach (EmailCampaignTestMessage::query()->orderBy('id')->get() as $i => $test) {
        $mailbox = $i < 2 ? 'publicidade@exportcontrol.com.br' : 'propaganda@exportcontrol.com.br';
        expect($test->test_to)->toBe('internal@example.test')
            ->and(CampaignReplyMailbox::dedicatedTestAddress((string) $test->message_id, (int) $campaign->id))->toBe($mailbox);
        $uid = $i + 2;
        expect(app(CampaignInbox::class)->import($mailbox, [
            'uidvalidity' => '123456', 'last_uid' => $uid, 'initialized' => false,
            'messages' => [[
                'uid' => $uid, 'from_email' => 'internal@example.test', 'subject' => 'Re: teste',
                'in_reply_to' => '<'.$test->message_id.'>', 'references' => '', 'auto_submitted' => '', 'received_at' => now()->toIso8601String(),
            ]],
        ]))->toBe(1);
        expect($test->fresh()->getAttribute('reply_mailbox'))->toBe($mailbox);
    }
    expect(EmailCampaignReply::query()->count())->toBe(0);
    DualCampaignSenders::halt();
    expect(fn () => $probe->send($user, $campaign, 'publicidade', (string) Str::uuid()))->toThrow(ValidationException::class);
    Mail::assertSentCount(3);
});
