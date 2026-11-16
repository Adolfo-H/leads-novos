<?php

declare(strict_types=1);

use App\Models\EmailCampaign;
use App\Models\HubSpotContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ec74Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ec74SendingConfig(): void
{
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.from_address', 'test@exportcontrol.example');
    config()->set('campaign_mail.reply_to', 'test@exportcontrol.example');
    config()->set('campaign_mail.unsubscribe_base_url', 'https://prospector.exportcontrol.com.br');
    config()->set('campaign_mail.max_recipients', 100);
    config()->set('campaign_mail.per_hour', 100);
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.per_tick', 5);
    Mail::fake();
    Queue::fake();
}

it('confirms one authorized campaign using the real approval flow without immediate SMTP sending', function (): void {
    ec74SendingConfig();
    HubSpotContact::query()->create([
        'hubspot_id' => '748500001',
        'first_name' => 'Maria',
        'last_name' => 'Teste',
        'email' => 'maria.test74@example.net',
    ]);

    Livewire::actingAs(ec74Manager())
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->set('audienceKeys', ['hubspot:748500001'])
        ->set('name', 'Campanha confirmação V74')
        ->set('subject', 'Assunto para revisão')
        ->set('body', 'Mensagem de apresentação da ExportControl com mais de dez caracteres.')
        ->set('lawfulBasisConfirmed', true)
        ->call('goToComposerStep', 'review')
        ->call('requestSendConfirmation')
        ->assertSet('sendConfirmationOpen', true)
        ->call('confirmSendCampaign')
        ->assertHasNoErrors()
        ->assertSet('sendConfirmationOpen', false)
        ->assertSet('screen', 'detail');

    expect(EmailCampaign::query()->count())->toBe(1)
        ->and(EmailCampaign::query()->firstOrFail()->status)->toBe('running')
        ->and(EmailCampaign::query()->firstOrFail()->recipients()->count())->toBe(1);

    // O método aprova; o scheduler envia mais tarde, conforme as cotas.
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('shows a real error instead of an HTTP 500 when a selection exceeds the internal audience cap', function (): void {
    ec74SendingConfig();
    config()->set('campaign_mail.max_recipients', 1500);
    config()->set('campaign_mail.smtp_approved_per_hour', 200);
    config()->set('campaign_mail.smtp_approved_per_day', 2000);
    config()->set('campaign_mail.smtp_approved_per_tick', 5);
    config()->set('campaign_mail.per_hour', 200);
    config()->set('campaign_mail.per_day', 2000);
    $keys = array_map(static fn (int $id): string => 'hubspot:'.$id, range(74000001, 74001001));

    Livewire::actingAs(ec74Manager())
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->set('audienceKeys', $keys)
        ->set('name', 'Campanha com mais de 1000 contatos')
        ->set('subject', 'Assunto com limite interno')
        ->set('body', 'Texto suficiente para exercitar o bloqueio com mensagem de validação.')
        ->set('lawfulBasisConfirmed', true)
        ->set('sendConfirmationOpen', true)
        ->call('confirmSendCampaign')
        ->assertSet('sendConfirmationOpen', false)
        ->assertHasErrors(['recipients']);

    expect(EmailCampaign::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('retains the V73 confirmation while providing diagnostic handling for unexpected failures', function (): void {
    $source = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    expect($source)->not->toBeFalse()
        ->toContain('EC_CAMPAIGNS_REVIEW_AND_CONFIRM_V73')
        ->toContain('EC_CAMPAIGN_CONFIRMATION_DIAGNOSTIC_V74')
        ->toContain('confirmSendCampaign(CampaignManager $manager)')
        ->toContain('requestSendConfirmation')
        ->toContain('saveAndStart($manager)')
        ->toContain('report($exception)');
});
