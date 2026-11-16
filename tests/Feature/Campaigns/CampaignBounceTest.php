<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignSuppression;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignBounceProcessor;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignUnsubscribeLink;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function ecV7Draft(): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Homologacao de bounce V7',
        'subject' => 'Assunto de teste',
        'body' => 'Mensagem simulada, sem SMTP',
        'status' => 'draft',
        'from_address' => 'teste@exportcontrol.com.br',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@exportcontrol.com.br',
    ]);
}

function ecV7Recipient(EmailCampaign $campaign, string $email = 'cliente@example.org'): EmailCampaignRecipient
{
    return $campaign->recipients()->create([
        'email' => $email,
        'normalized_email' => $email,
        'status' => 'sent',
        'message_id' => 'ec-v7-001@exportcontrol.com.br',
        'attempted_at' => now('UTC'),
        'sent_at' => now('UTC'),
    ]);
}

/** @return array<string,string> */
function ecV7Dsn(string $code = '5.1.1', string $to = 'cliente@example.org'): array
{
    return [
        'original_message_id' => 'ec-v7-001@exportcontrol.com.br',
        'final_recipient' => $to,
        'status_code' => $code,
        'action' => str_starts_with($code, '4.') ? 'delayed' : 'failed',
        'classification' => match (true) {
            in_array($code, ['5.1.1', '5.1.2', '5.1.10'], true) => 'invalid',
            str_starts_with($code, '4.') => 'temporary',
            default => 'permanent_other',
        },
        'diagnostic' => 'smtp; erro simulado',
    ];
}

beforeEach(function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();
});

it('records authenticated matched hard invalid DSN and blocks future campaigns, preserving sent history', function (): void {
    $draft = ecV7Draft();
    $sent = ecV7Recipient($draft);
    $another = ecV7Draft()->recipients()->create([
        'email' => 'cliente@example.org', 'normalized_email' => 'cliente@example.org', 'status' => 'pending',
    ]);

    $created = app(CampaignBounceProcessor::class)->record('teste@exportcontrol.com.br', '1001', 30, ecV7Dsn());
    expect($created)->toBeTrue()
        ->and(EmailCampaignBounce::query()->count())->toBe(1)
        ->and(EmailCampaignSuppression::query()->where('normalized_email', 'cliente@example.org')->where('reason', 'hard_bounce')->exists())->toBeTrue()
        ->and($sent->fresh()->status)->toBe('sent')
        ->and($another->fresh()->status)->toBe('suppressed');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not record duplicate DSNs and leaves the suppression unique', function (): void {
    ecV7Recipient(ecV7Draft());
    $processor = app(CampaignBounceProcessor::class);
    expect($processor->record('teste@exportcontrol.com.br', '101', 50, ecV7Dsn()))->toBeTrue()
        ->and($processor->record('teste@exportcontrol.com.br', '101', 50, ecV7Dsn()))->toBeFalse()
        ->and(EmailCampaignBounce::query()->count())->toBe(1)
        ->and(EmailCampaignSuppression::query()->count())->toBe(1);
});

it('records temporary and policy DSNs but does not suppress recipients', function (): void {
    ecV7Recipient(ecV7Draft());
    $processor = app(CampaignBounceProcessor::class);
    expect($processor->record('teste@exportcontrol.com.br', '101', 51, ecV7Dsn('4.2.2')))->toBeTrue()
        ->and($processor->record('teste@exportcontrol.com.br', '101', 52, ecV7Dsn('5.7.1')))->toBeTrue()
        ->and(EmailCampaignBounce::query()->where('classification', 'temporary')->count())->toBe(1)
        ->and(EmailCampaignBounce::query()->where('classification', 'permanent_other')->count())->toBe(1)
        ->and(EmailCampaignSuppression::query()->count())->toBe(0);
});

it('rejects mismatched original id, recipient and forged classification', function (): void {
    ecV7Recipient(ecV7Draft());
    $processor = app(CampaignBounceProcessor::class);
    $fakeId = ecV7Dsn();
    $fakeId['original_message_id'] = 'unrelated@example.org';
    $badClassification = ecV7Dsn();
    $badClassification['classification'] = 'invalid';
    $badClassification['status_code'] = '5.7.1';

    expect($processor->record('teste@exportcontrol.com.br', '101', 53, $fakeId))->toBeFalse()
        ->and($processor->record('teste@exportcontrol.com.br', '101', 54, ecV7Dsn('5.1.1', 'outro@example.org')))->toBeFalse()
        ->and($processor->record('teste@exportcontrol.com.br', '101', 55, $badClassification))->toBeFalse()
        ->and(EmailCampaignBounce::query()->count())->toBe(0)
        ->and(EmailCampaignSuppression::query()->count())->toBe(0);
});

it('records pilot DSN without suppressing any commercial recipient', function (): void {
    $draft = ecV7Draft();
    $pilot = EmailCampaignTestMessage::query()->create([
        'email_campaign_id' => $draft->id,
        'test_to' => 'piloto@example.org',
        'message_id' => 'ec-v7-001@exportcontrol.com.br',
        'status' => 'pilot_smtp_accepted',
        'attempted_at' => now('UTC'),
    ]);
    $payload = ecV7Dsn('5.1.1', 'piloto@example.org');

    expect(app(CampaignBounceProcessor::class)->record('teste@exportcontrol.com.br', '201', 12, $payload))->toBeTrue()
        ->and(EmailCampaignBounce::query()->firstOrFail()->email_campaign_test_message_id)->toBe($pilot->id)
        ->and(EmailCampaignSuppression::query()->count())->toBe(0);
});

it('accepts DSN in existing IMAP batch but does not pretend it is a client reply', function (): void {
    ecV7Recipient(ecV7Draft());
    EmailCampaignInboxCursor::query()->create([
        'mailbox' => 'teste@exportcontrol.com.br', 'uid_validity' => '300', 'last_uid' => 10,
    ]);
    $batch = [
        'uidvalidity' => '300', 'last_uid' => 11, 'initialized' => false,
        'messages' => [[
            'uid' => 11, 'from_email' => 'mailer-daemon@example.org',
            'subject' => 'Mail delivery failed', 'auto_submitted' => 'auto-generated',
            'in_reply_to' => '', 'references' => '', 'bounce' => ecV7Dsn(),
        ]],
    ];
    expect(app(CampaignInbox::class)->import('teste@exportcontrol.com.br', $batch))->toBe(0)
        ->and(EmailCampaignBounce::query()->count())->toBe(1)
        ->and(EmailCampaignSuppression::query()->count())->toBe(1);
});

it('generates HTTPS unsubscribe independently of local APP_URL and rejects invalid bases', function (): void {
    config()->set('app.url', 'http://localhost:8000');
    config()->set('campaign_mail.unsubscribe_base_url', 'https://example.ngrok-free.app');
    $recipient = ecV7Recipient(ecV7Draft());
    $service = app(CampaignUnsubscribeLink::class);
    $url = $service->forRecipient($recipient, app(UnsubscribeToken::class));
    expect($url)->toStartWith('https://example.ngrok-free.app/email/descadastro/')
        ->and($service->isReady())->toBeTrue();
    config()->set('campaign_mail.unsubscribe_base_url', 'http://localhost:8000');
    expect($service->isReady())->toBeFalse();
    config()->set('campaign_mail.unsubscribe_base_url', 'https://user:pass@example.org');
    expect($service->isReady())->toBeFalse();
});

it('accepts unsubscribe confirmation without login using a relative POST and suppresses only that email', function (): void {
    $recipient = ecV7Recipient(ecV7Draft());
    $token = app(UnsubscribeToken::class)->forRecipient($recipient);
    $url = route('campaigns.unsubscribe', ['token' => $token], false);

    $this->get($url)->assertOk()->assertSee('Confirmar descadastro');
    $this->post($url)->assertRedirect()->assertSessionHas('unsubscribed', true);
    expect(EmailCampaignSuppression::query()->where('normalized_email', $recipient->normalized_email)->exists())->toBeTrue();
    Mail::assertNothingSent();
});

it('does not enable commercial sending or accidentally enqueue messages', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($user)->get(route('campaigns.queue'))->assertOk()->assertSee('E-mails devolvidos');
    expect(config('campaign_mail.live_enabled'))->toBeFalse();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
