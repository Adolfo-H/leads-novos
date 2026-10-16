<?php

use App\Jobs\SendCampaignPilotMessage;
use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignPilot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function ecPilotV5Manager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function ecPilotV5Draft(): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Piloto V5',
        'subject' => 'Olá, {{primeiro_nome}}',
        'body' => 'Mensagem de teste isolado do Prospector, empresa {{empresa}}.',
        'status' => 'draft',
        'from_address' => 'prospector@example.org',
        'from_name' => 'ExportControl',
        'reply_to' => 'inbox@example.org',
    ]);
}

beforeEach(function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.pilot_enabled', true);
    config()->set('campaign_mail.pilot_allowlist', 'autorizado1@example.org, autorizado2@example.org');
    config()->set('campaign_mail.mailer', 'smtp');
    config()->set('campaign_mail.from_address', 'prospector@example.org');
    config()->set('campaign_mail.from_name', 'Prospector ExportControl');
    config()->set('campaign_mail.reply_to', 'inbox@example.org');
    config()->set('campaign_mail.imap_enabled', true);
    config()->set('campaign_mail.imap_username', 'inbox@example.org');
    config()->set('campaign_mail.queue', 'campaigns');
    EmailCampaignInboxCursor::query()->create(['mailbox' => 'inbox@example.org', 'uid_validity' => '1000', 'last_uid' => 1]);
    Queue::fake();
    Mail::fake();
});

it('blocks pilots until explicitly enabled and never enables normal commercial sending', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();
    config()->set('campaign_mail.pilot_enabled', false);

    expect(fn () => app(CampaignPilot::class)->queue($user, $campaign))
        ->toThrow(ValidationException::class);
    expect(EmailCampaignTestMessage::query()->count())->toBe(0);
    expect(config('campaign_mail.live_enabled'))->toBeFalse();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('rejects unapproved addresses and more than three addresses', function (): void {
    config()->set('campaign_mail.pilot_allowlist', 'autorizado1@example.org, qualquer*@example.org');
    expect(fn () => app(CampaignPilot::class)->allowedTargets())->toThrow(ValidationException::class);

    config()->set('campaign_mail.pilot_allowlist', 'a@example.org,b@example.org,c@example.org,d@example.org');
    expect(fn () => app(CampaignPilot::class)->allowedTargets())->toThrow(ValidationException::class);

    config()->set('campaign_mail.pilot_allowlist', 'AUTORIZADO1@example.org, autorizado1@example.org');
    expect(app(CampaignPilot::class)->allowedTargets())->toBe(['autorizado1@example.org']);
});

it('enqueues only two allowlisted tests on the campaigns redis queue', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();

    expect(app(CampaignPilot::class)->queue($user, $campaign))->toBe(2);
    expect(EmailCampaignTestMessage::query()->where('status', 'pilot_queued')->count())->toBe(2);
    expect(EmailCampaignRecipient::query()->count())->toBe(0);
    Queue::assertPushed(SendCampaignPilotMessage::class, 2);
    Queue::assertPushed(SendCampaignPilotMessage::class, function (SendCampaignPilotMessage $job): bool {
        return $job->connection === 'redis' && $job->queue === 'campaigns';
    });
    Mail::assertNothingSent();
});

it('does not enqueue the same pilot twice for one campaign', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();
    $service = app(CampaignPilot::class);

    expect($service->queue($user, $campaign))->toBe(2)
        ->and($service->queue($user, $campaign))->toBe(0)
        ->and(EmailCampaignTestMessage::query()->count())->toBe(2);
    Queue::assertPushed(SendCampaignPilotMessage::class, 2);
    Mail::assertNothingSent();
});

it('blocks a queued pilot if its address is removed from the allowlist', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();
    app(CampaignPilot::class)->queue($user, $campaign);
    $test = EmailCampaignTestMessage::query()->where('test_to', 'autorizado1@example.org')->firstOrFail();

    config()->set('campaign_mail.pilot_allowlist', 'autorizado2@example.org');
    (new SendCampaignPilotMessage((int) $test->id))
        ->handle(app(CampaignPilot::class), app(CampaignContent::class));

    expect($test->fresh()->status)->toBe('pilot_blocked');
    Mail::assertNothingSent();
});

it('sends a pilot only once and keeps commercial recipients untouched', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();
    app(CampaignPilot::class)->queue($user, $campaign);
    $test = EmailCampaignTestMessage::query()->where('test_to', 'autorizado1@example.org')->firstOrFail();
    $job = new SendCampaignPilotMessage((int) $test->id);

    $job->handle(app(CampaignPilot::class), app(CampaignContent::class));
    $job->handle(app(CampaignPilot::class), app(CampaignContent::class));

    expect($test->fresh()->status)->toBe('pilot_smtp_accepted')
        ->and($test->fresh()->sent_at)->not->toBeNull()
        ->and(EmailCampaignRecipient::query()->count())->toBe(0);
    Mail::assertSent(ProspectorCampaignMessage::class, 1);
    Mail::assertSent(ProspectorCampaignMessage::class, static function (ProspectorCampaignMessage $message): bool {
        return $message->hasTo('autorizado1@example.org')
            && str_starts_with($message->mailSubject, '[PILOTO]')
            && $message->trackingMessageId !== null
            && $message->unsubscribeUrl === null;
    });
});

it('does not pilot when commercial sending is enabled', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();
    config()->set('campaign_mail.live_enabled', true);
    expect(fn () => app(CampaignPilot::class)->queue($user, $campaign))
        ->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('IMAP matches pilot replies without incrementing commercial reply metrics', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();
    app(CampaignPilot::class)->queue($user, $campaign);
    $test = EmailCampaignTestMessage::query()->where('test_to', 'autorizado1@example.org')->firstOrFail();
    (new SendCampaignPilotMessage((int) $test->id))
        ->handle(app(CampaignPilot::class), app(CampaignContent::class));
    $messageId = (string) $test->message_id;

    $batch = [
        'uidvalidity' => '1111', 'last_uid' => 100, 'initialized' => false,
        'messages' => [
            ['uid' => 100, 'from_email' => 'autorizado1@example.org', 'in_reply_to' => '<'.$messageId.'>',
                'references' => '', 'subject' => 'Re: Piloto', 'received_at' => now()->toIso8601String()],
        ],
    ];

    expect(app(CampaignInbox::class)->import('inbox@example.org', $batch))->toBe(1);
    expect(app(CampaignInbox::class)->import('inbox@example.org', $batch))->toBe(0);
    expect($test->fresh()->replied_at)->not->toBeNull()
        ->and($campaign->replies()->count())->toBe(0);
});

it('enforces six pilot messages per rolling hour across different campaigns', function (): void {
    $user = ecPilotV5Manager();
    $pilot = app(CampaignPilot::class);
    for ($i = 0; $i < 3; $i++) {
        expect($pilot->queue($user, ecPilotV5Draft()))->toBe(2);
    }
    expect(fn () => $pilot->queue($user, ecPilotV5Draft()))
        ->toThrow(ValidationException::class);
    expect(EmailCampaignTestMessage::query()->where('status', 'pilot_queued')->count())->toBe(6);
    Mail::assertNothingSent();
});

it('blocks pilot bursts across midnight using UTC instants', function (): void {
    config()->set('app.timezone', 'America/Sao_Paulo');
    $start = Carbon::parse('2026-10-16 23:40:00', 'America/Sao_Paulo');
    $this->travelTo($start);

    $user = ecPilotV5Manager();
    $pilot = app(CampaignPilot::class);
    for ($i = 0; $i < 3; $i++) {
        expect($pilot->queue($user, ecPilotV5Draft()))->toBe(2);
    }

    $this->travelTo($start->copy()->addMinutes(59));
    expect(fn () => $pilot->queue($user, ecPilotV5Draft()))
        ->toThrow(ValidationException::class);

    $this->travelTo($start->copy()->addMinutes(61));
    expect($pilot->queue($user, ecPilotV5Draft()))->toBe(2)
        ->and(EmailCampaignTestMessage::query()->where('status', 'pilot_queued')->count())->toBe(8);
    Queue::assertPushed(SendCampaignPilotMessage::class, 8);
    Mail::assertNothingSent();
    $this->travelBack();
});

it('conservatively counts pilot records written with the previous local timestamp', function (): void {
    config()->set('app.timezone', 'America/Sao_Paulo');
    $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'America/Sao_Paulo'));
    $user = ecPilotV5Manager();
    $campaign = ecPilotV5Draft();

    // A versao anterior preenchia created_at automaticamente no horario local.
    for ($i = 0; $i < 5; $i++) {
        EmailCampaignTestMessage::query()->create([
            'email_campaign_id' => $campaign->id,
            'test_to' => 'legado'.$i.'@example.org',
            'message_id' => 'legado'.$i.'@example.org',
            'status' => 'pilot_queued',
            'created_by' => $user->id,
        ]);
    }

    expect(fn () => app(CampaignPilot::class)->queue($user, ecPilotV5Draft()))
        ->toThrow(ValidationException::class);
    expect(EmailCampaignTestMessage::query()->count())->toBe(5);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    $this->travelBack();
});

it('does not recount a UTC pilot as a legacy timestamp three hours later', function (): void {
    config()->set('app.timezone', 'America/Sao_Paulo');
    $start = Carbon::parse('2026-10-16 10:00:00', 'America/Sao_Paulo');
    $this->travelTo($start);
    $user = ecPilotV5Manager();
    $pilot = app(CampaignPilot::class);
    for ($i = 0; $i < 3; $i++) {
        expect($pilot->queue($user, ecPilotV5Draft()))->toBe(2);
    }
    $message = EmailCampaignTestMessage::query()->firstOrFail();
    expect(str_starts_with((string) $message->message_id, 'utc-'))->toBeTrue();

    $this->travelTo($start->copy()->addHours(3)->addMinutes(30));
    expect($pilot->queue($user, ecPilotV5Draft()))->toBe(2);
    Queue::assertPushed(SendCampaignPilotMessage::class, 8);
    Mail::assertNothingSent();
    $this->travelBack();
});

it('shows pilot panel in a draft without making commercial sending available', function (): void {
    $campaign = ecPilotV5Draft();
    $user = ecPilotV5Manager();
    $this->actingAs($user)->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('data-ec-pilot-v5', false)
        ->assertSee('Piloto seguro V5');
    expect($campaign->status)->toBe('draft')
        ->and(config('campaign_mail.live_enabled'))->toBeFalse();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});
