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

function ec73CampaignManager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function ec73CreateContact(): void
{
    HubSpotContact::query()->create([
        'hubspot_id' => '731570001',
        'first_name' => 'Ana',
        'last_name' => 'Revisão',
        'email' => 'ana.revisao@example.net',
        'company_name' => 'Empresa de Exemplo',
    ]);
}

it('shows the real selected email addresses and a readable test area on the review step', function (): void {
    config()->set('campaign_mail.max_recipients', 100);
    config()->set('campaign_mail.test_to', 'teste-campanhas@example.net');
    Mail::fake();
    Queue::fake();
    ec73CreateContact();

    Livewire::actingAs(ec73CampaignManager())
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->set('audienceKeys', ['hubspot:731570001'])
        ->set('name', 'Apresentação para revisão V73')
        ->set('subject', 'Apresentação da ExportControl')
        ->set('body', 'Olá, esta é uma mensagem para validar a prévia com segurança.')
        ->call('goToComposerStep', 'review')
        ->assertSet('composerStep', 'review')
        ->assertSee('Para quais e-mails a campanha será enviada?')
        ->assertSee('ana.revisao@example.net')
        ->assertSee('Envio de teste')
        ->assertSee('teste-campanhas@example.net');

    expect(EmailCampaign::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('opens and cancels the in-app confirmation without queuing or sending any email', function (): void {
    config()->set('campaign_mail.max_recipients', 100);
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.unsubscribe_base_url', 'https://prospector.exportcontrol.com.br');
    Mail::fake();
    Queue::fake();
    ec73CreateContact();

    Livewire::actingAs(ec73CampaignManager())
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->set('audienceKeys', ['hubspot:731570001'])
        ->set('name', 'Apresentação V73')
        ->set('subject', 'Assunto de apresentação')
        ->set('body', 'Mensagem de apresentação com texto suficiente.')
        ->set('lawfulBasisConfirmed', true)
        ->call('goToComposerStep', 'review')
        ->call('requestSendConfirmation')
        ->assertSet('sendConfirmationOpen', true)
        ->assertSee('Confirmar o envio da campanha')
        ->assertSee('ana.revisao@example.net')
        ->call('cancelSendConfirmation')
        ->assertSet('sendConfirmationOpen', false);

    expect(EmailCampaign::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps the original backend approval flow and removes native browser send confirmation', function (): void {
    $view = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/campaigns-review-v73.css'));
    $app = file_get_contents(resource_path('css/app.css'));
    $recipients = file_get_contents(resource_path('views/campaigns/review-recipients-v73.blade.php'));
    $confirm = file_get_contents(resource_path('views/campaigns/send-confirmation-v73.blade.php'));

    expect($view)->not->toBeFalse()
        ->toContain('EC_CAMPAIGNS_REVIEW_AND_CONFIRM_V73')
        ->toContain('requestSendConfirmation')
        ->toContain('confirmSendCampaign')
        ->toContain('saveAndStart')
        ->not->toContain('wire:click="saveAndStart" wire:confirm=')
        ->and($app)->toContain("@import './campaigns-review-v73.css';")
        ->and($css)->toContain('ec73-test-block')
        ->and($recipients)->toContain('data-ec73-review-recipients')
        ->and($confirm)->toContain('role="dialog"');
});
