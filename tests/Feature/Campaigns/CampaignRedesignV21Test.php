<?php

use App\Models\User;
use App\Services\Campaigns\CampaignSendPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ec21RedesignManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows the simplified campaigns home without triggering any email', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $this->actingAs(ec21RedesignManager())
        ->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('Crie sua próxima campanha de e-mail')
        ->assertSee('Selecionar contatos')
        ->assertSee('Escrever mensagem')
        ->assertSee('Ritmo e revisão');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('opens the new recipients screen but does not enable real delivery', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    Livewire::actingAs(ec21RedesignManager())
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->assertSet('screen', 'composer')
        ->assertSet('composerStep', 'audience')
        ->assertSee('Escolha os contatos')
        ->assertSee('Destinatários selecionados');

    expect(config('campaign_mail.live_enabled'))->toBeFalse();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps the real policy while a user selects a desired sending time', function (): void {
    // Uma cota SMTP conservadora mantém o comportamento originalmente testado,
    // independentemente dos limites que o desenvolvedor autorizou no .env.
    config()->set('campaign_mail.smtp_approved_per_hour', 100);
    config()->set('campaign_mail.smtp_approved_per_day', 500);
    config()->set('campaign_mail.smtp_approved_per_tick', 5);

    $policy = app(CampaignSendPolicy::class);

    expect($policy->quote(500, 2)['possible'])->toBeFalse()
        ->and($policy->quote(500, 8)['possible'])->toBeTrue();

    $view = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    expect($view)->not->toBeFalse()
        ->toContain('EC_CAMPAIGN_REDESIGN_V21')
        ->toContain('saveAndStart')
        ->toContain('lawfulBasisConfirmed')
        ->toContain('pacePreview');
});

it('shows one unified settings page and the real commercial safety lock', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $this->actingAs(ec21RedesignManager())
        ->get(route('campaigns.settings'))
        ->assertOk()
        ->assertSee('Configurações de envio')
        ->assertSee('Limites efetivos neste momento')
        ->assertSee('Envios comerciais bloqueados');

    $this->get(route('campaigns.replies'))
        ->assertOk()
        ->assertSee('Caixa de respostas');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps the advanced operational routes available', function (): void {
    foreach ([
        'campaigns.index',
        'campaigns.replies',
        'campaigns.settings',
        'campaigns.queue',
        'campaigns.production.index',
        'campaigns.intelligence.index',
    ] as $name) {
        expect(Route::has($name))->toBeTrue();
    }
});
