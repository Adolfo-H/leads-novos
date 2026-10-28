<?php

declare(strict_types=1);

use App\Models\EmailCampaignSuppression;
use App\Models\HubSpotContact;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ec77Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows the opt-out filter and only real unsubscribe registrations', function (): void {
    EmailCampaignSuppression::query()->create(['normalized_email' => 'cancelou@example.test', 'reason' => 'unsubscribe']);
    EmailCampaignSuppression::query()->create(['normalized_email' => 'outro@example.test', 'reason' => 'bounce']);
    Mail::fake();
    Queue::fake();

    Livewire::actingAs(ec77Manager())->test('pages::campaigns.replies')
        ->assertSee('Descadastrados')
        ->call('chooseKind', 'suppressed')
        ->assertSee('cancelou@example.test')
        ->assertDontSee('outro@example.test')
        ->assertSee('Descadastro solicitado');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not let sellers browse unsubscribe addresses', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get(route('campaigns.replies'))->assertOk();
});

it('keeps selection review and UI clearly identifying real unsubscribe blocks', function (): void {
    $review = file_get_contents(resource_path('views/campaigns/review-recipients-v73.blade.php'));
    $view = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($review)->not->toBeFalse()
        ->and($review)->toContain('Descadastrado — envio impedido')
        ->and($view)->toContain('ec77-unsubscribed-row')
        ->and($view)->toContain('wire:click="toggleAudiencePage"')
        ->and($css)->toContain("@import './campaigns-replies-optout-v77.css';");
});

it('does not enable SMTP or initiate an IMAP connection when showing the reply health', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.imap_enabled', false);

    Livewire::actingAs(ec77Manager())->test('pages::campaigns.replies')
        ->assertSee('Sincronização de respostas')
        ->assertSee('Desativado')
        ->call('refreshImapStatus')
        ->assertSee('Atualizar estado');

    expect(config('campaign_mail.live_enabled'))->toBeFalse();
});

it('explains an unsubscribed contact before any campaign send and excludes them from bulk selection', function (): void {
    HubSpotContact::query()->create([
        'hubspot_id' => '771277',
        'first_name' => 'Pessoa',
        'last_name' => 'Descadastrada',
        'email' => 'optout@example.test',
    ]);
    EmailCampaignSuppression::query()->create([
        'normalized_email' => 'optout@example.test',
        'reason' => 'unsubscribe',
    ]);
    Mail::fake();
    Queue::fake();

    Livewire::actingAs(ec77Manager())->test('pages::campaigns.index')
        ->call('newCampaign')
        ->assertSee('Descadastrado · envio impedido')
        ->call('toggleAudiencePage')
        ->assertSet('audienceKeys', [])
        ->call('addAllFiltered')
        ->assertSet('audienceKeys', [])
        ->set('audienceKeys', ['hubspot:771277'])
        ->set('composerStep', 'pace')
        ->assertSee('Descadastrado — envio impedido');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
