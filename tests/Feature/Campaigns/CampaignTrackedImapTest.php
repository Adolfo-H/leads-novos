<?php

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignTrackedTest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function trackedCampaignSetup(): array
{
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.imap_enabled', true);
    config()->set('campaign_mail.from_address', 'adolfo@exportcontrol.com.br');
    config()->set('campaign_mail.reply_to', 'adolfo@exportcontrol.com.br');
    config()->set('campaign_mail.imap_username', 'adolfo@exportcontrol.com.br');
    config()->set('campaign_mail.tracked_test_to', 'teste.autorizado@example.net');
    config()->set('campaign_mail.mailer', 'smtp');
    config()->set('campaign_mail.from_name', 'Adolfo | ExportControl');

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    RateLimiter::clear('campaigns-tracked-test:'.$manager->id);
    $campaign = EmailCampaign::query()->create([
        'name' => 'Teste rastreado',
        'subject' => 'Olá {{nome}}',
        'body' => 'Mensagem de teste autorizada para {{empresa}}.',
        'status' => 'draft',
        'from_address' => 'adolfo@exportcontrol.com.br',
        'from_name' => 'Adolfo | ExportControl',
        'reply_to' => 'adolfo@exportcontrol.com.br',
        'created_by' => $manager->id,
    ]);
    EmailCampaignInboxCursor::query()->create([
        'mailbox' => 'adolfo@exportcontrol.com.br',
        'uid_validity' => '12345',
        'last_uid' => 100,
    ]);

    return [$manager, $campaign];
}

it('sends tracked tests only to the explicitly configured second mailbox', function (): void {
    [$manager, $campaign] = trackedCampaignSetup();
    Mail::fake();

    $test = app(CampaignTrackedTest::class)->send($manager, $campaign);

    expect($test->status)->toBe('smtp_accepted');
    expect($test->sent_at)->not->toBeNull();
    expect($test->message_id)->toContain('@exportcontrol.com.br');
    expect(EmailCampaignRecipient::query()->count())->toBe(0);
    expect(EmailCampaignReply::query()->count())->toBe(0);
    expect($campaign->fresh()->status)->toBe('draft');
    Mail::assertSent(ProspectorCampaignMessage::class, function (ProspectorCampaignMessage $mail) use ($test): bool {
        return $mail->hasTo('teste.autorizado@example.net')
            && $mail->trackingMessageId === $test->message_id
            && $mail->isTest
            && $mail->unsubscribeUrl === null;
    });
});

it('refuses the same mailbox as tracked test destination', function (): void {
    [$manager, $campaign] = trackedCampaignSetup();
    config()->set('campaign_mail.tracked_test_to', 'adolfo@exportcontrol.com.br');
    Mail::fake();

    expect(fn () => app(CampaignTrackedTest::class)->send($manager, $campaign))
        ->toThrow(ValidationException::class);
    expect(EmailCampaignTestMessage::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('rejects sending before IMAP cursor is initialized', function (): void {
    [$manager, $campaign] = trackedCampaignSetup();
    EmailCampaignInboxCursor::query()->delete();
    Mail::fake();

    expect(fn () => app(CampaignTrackedTest::class)->send($manager, $campaign))
        ->toThrow(ValidationException::class);
    Mail::assertNothingSent();
});

it('recognizes a tracked response by Message-ID and sender, without changing commercial metrics', function (): void {
    [$manager, $campaign] = trackedCampaignSetup();
    Mail::fake();
    $test = app(CampaignTrackedTest::class)->send($manager, $campaign);

    $batch = [
        'uidvalidity' => '12345', 'last_uid' => 103, 'initialized' => false,
        'messages' => [
            [
                'uid' => 101,
                'from_email' => 'alguem@example.net',
                'in_reply_to' => '<'.$test->message_id.'>',
                'references' => '',
                'auto_submitted' => '',
                'subject' => 'Resposta de outro endereço',
                'received_at' => now()->toIso8601String(),
            ],
            [
                'uid' => 102,
                'from_email' => 'teste.autorizado@example.net',
                'in_reply_to' => '',
                'references' => '<'.$test->message_id.'>',
                'auto_submitted' => '',
                'subject' => 'Re: Olá Adolfo',
                'received_at' => now()->toIso8601String(),
            ],
            [
                'uid' => 103,
                'from_email' => 'teste.autorizado@example.net',
                'in_reply_to' => '',
                'references' => '',
                'auto_submitted' => '',
                'subject' => 'Assunto semelhante sem referência',
                'received_at' => now()->toIso8601String(),
            ],
        ],
    ];

    expect(app(CampaignInbox::class)->import('adolfo@exportcontrol.com.br', $batch))->toBe(1);
    expect(app(CampaignInbox::class)->import('adolfo@exportcontrol.com.br', $batch))->toBe(0);
    expect($test->fresh()->replied_at)->not->toBeNull();
    expect($test->fresh()->reply_subject)->toBe('Re: Olá Adolfo');
    expect($test->fresh()->reply_imap_uid)->toBe(102);
    expect(EmailCampaignRecipient::query()->count())->toBe(0);
    expect(EmailCampaignReply::query()->count())->toBe(0);
});

it('permits seller navigation but restricts tracked SMTP testing to managers', function (): void {
    [$manager, $campaign] = trackedCampaignSetup();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $this->actingAs($seller)->get(route('campaigns.index'))->assertOk();
    // A página é compartilhada, mas o teste SMTP rastreado exige gestor.
    Mail::fake();
    expect(fn () => app(CampaignTrackedTest::class)->send($seller, $campaign))
        ->toThrow(HttpException::class);
    Mail::assertNothingSent();

    Livewire::actingAs($manager)
        ->test('pages::campaigns.index')
        ->call('openCampaign', $campaign->id)
        ->assertSee('Enviar teste rastreado');
});
