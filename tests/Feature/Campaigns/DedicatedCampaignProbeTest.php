<?php

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\DedicatedCampaignProbe;
use App\Services\Campaigns\DualCampaignSenders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function ecProbeV22Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ecProbeV22Draft(): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Diagnóstico interno', 'subject' => 'Assunto', 'body' => 'Texto fictício',
        'status' => 'draft', 'from_address' => 'legacy@example.invalid',
        'from_name' => 'Teste', 'reply_to' => 'legacy@example.invalid',
    ]);
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.imap_enabled', true);
    config()->set('campaign_mail.test_to', 'internal@example.test');
    config()->set('campaign_mail.per_hour', 100);
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.per_tick', 2);
    config()->set('campaign_mail.smtp_approved_per_hour', 100);
    config()->set('campaign_mail.smtp_approved_per_day', 500);
    config()->set('campaign_mail.smtp_approved_per_tick', 2);
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
});

it('sends only one internal diagnostic per account without starting the draft', function (): void {
    $user = ecProbeV22Manager();
    $campaign = ecProbeV22Draft();
    $recipient = $campaign->recipients()->create(['email' => 'external@example.invalid', 'normalized_email' => 'external@example.invalid', 'status' => 'pending']);
    $probe = app(DedicatedCampaignProbe::class);
    foreach (DualCampaignSenders::KEYS as $key) {
        $probe->send($user, $campaign, $key);
        Mail::assertSent(ProspectorCampaignMessage::class, static function (ProspectorCampaignMessage $mail) use ($key): bool {
            $envelope = $mail->envelope();

            return $mail->hasTo('internal@example.test') && $mail->isTest
                && $envelope->from->address === $key.'@exportcontrol.com.br'
                && $envelope->replyTo[0]->address === $key.'@exportcontrol.com.br';
        });
    }
    Mail::assertSentCount(2);
    expect($campaign->fresh()->status)->toBe('draft')
        ->and($recipient->fresh()->status)->toBe('pending')
        ->and($recipient->fresh()->getAttribute('sender_profile'))->toBeNull()
        ->and(EmailCampaignTestMessage::query()->where('status', 'smtp_accepted')->count())->toBe(2);
    expect(DedicatedCampaignProbe::attemptsSince(now('UTC')->subHour()))->toBe(2);
    Queue::assertNothingPushed();
});

it('rejects repeated tests and refuses an exhausted minute quota', function (): void {
    config()->set('campaign_mail.per_tick', 1);
    $user = ecProbeV22Manager();
    $campaign = ecProbeV22Draft();
    $probe = app(DedicatedCampaignProbe::class);
    $probe->send($user, $campaign, 'publicidade');
    expect(fn () => $probe->send($user, $campaign, 'publicidade'))->toThrow(ValidationException::class);
    expect(fn () => $probe->send($user, $campaign, 'propaganda'))->toThrow(ValidationException::class);
    Mail::assertSentCount(1);
});

it('blocks the diagnostic during commercial sending or a persistent SMTP hold', function (): void {
    $user = ecProbeV22Manager();
    $campaign = ecProbeV22Draft();
    $other = ecProbeV22Draft();
    $other->update(['status' => 'running']);
    $probe = app(DedicatedCampaignProbe::class);
    expect(fn () => $probe->send($user, $campaign, 'publicidade'))->toThrow(ValidationException::class);
    $other->update(['status' => 'paused']);
    DualCampaignSenders::halt();
    expect(fn () => $probe->send($user, $campaign, 'propaganda'))->toThrow(ValidationException::class);
    Mail::assertNothingSent();
});

it('refuses approval while a diagnostic is in an unresolved initiated state', function (): void {
    $campaign = ecProbeV22Draft();
    EmailCampaignTestMessage::query()->create([
        'email_campaign_id' => $campaign->id, 'test_to' => 'internal@example.test',
        'message_id' => 'ec-dedicated-test-99-publicidade@exportcontrol.com.br',
        'status' => 'initiated', 'attempted_at' => now('UTC'),
    ]);
    expect(DedicatedCampaignProbe::inProgress())->toBeTrue();
    expect(fn () => DB::transaction(static fn () => DualCampaignSenders::prepare($campaign)))->toThrow(ValidationException::class);
    Mail::assertNothingSent();
});
