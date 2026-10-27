<?php

use App\Models\EmailCampaign;
use App\Models\HubSpotContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ux22Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows three clear campaign steps without sending messages', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $this->actingAs(ux22Manager())->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('Crie sua próxima campanha de e-mail')
        ->assertSee('Selecionar contatos')
        ->assertSee('Escrever mensagem')
        ->assertSee('Ritmo e revisão');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps message validation, real policy and test button in the three-step wizard', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();
    HubSpotContact::query()->create([
        'hubspot_id' => '22001001',
        'first_name' => 'Exemplo',
        'last_name' => 'Teste',
        'email' => 'ux22@example.invalid',
    ]);

    Livewire::actingAs(ux22Manager())->test('pages::campaigns.index')
        ->call('newCampaign')
        ->assertSee('Destinatários selecionados')
        ->set('audienceKeys', ['hubspot:22001001'])
        ->call('goToComposerStep', 'message')
        ->assertSee('Pré-visualização')
        ->set('subject', 'Mensagem de teste para ExportControl')
        ->set('body', 'Este texto é apenas uma validação automatizada do editor.')
        ->call('goToComposerStep', 'pace')
        ->assertHasErrors(['name' => 'required'])
        ->assertSee('Não foi possível avançar')
        ->set('name', 'Campanha de teste UX 22')
        ->call('goToComposerStep', 'pace')
        ->assertSet('composerStep', 'pace')
        ->assertSee('Em quanto tempo você quer enviar?')
        ->assertSee('Enviar teste')
        ->assertSee('Modo seguro ativo.');

    expect(EmailCampaign::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps settings, replies and advanced tools accessible only to managers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get(route('campaigns.advanced'))->assertForbidden();

    $this->actingAs(ux22Manager());

    // CAMPANHAS_V701_TESTE_STATUS_ENVIO: valida os dois estados da UI
    // sem depender do .env do desenvolvedor ou mudar a configuração persistente.
    $originalLiveEnabled = config('campaign_mail.live_enabled');
    try {
        config()->set('campaign_mail.live_enabled', false);
        $this->get(route('campaigns.settings'))->assertOk()
            ->assertSee('Configurações de envio')
            ->assertSee('Limites efetivos neste momento')
            ->assertSee('Envios comerciais bloqueados')
            ->assertDontSee('Envio comercial habilitado');

        config()->set('campaign_mail.live_enabled', true);
        $this->get(route('campaigns.settings'))->assertOk()
            ->assertSee('Envio comercial habilitado')
            ->assertDontSee('Envios comerciais bloqueados');
    } finally {
        config()->set('campaign_mail.live_enabled', $originalLiveEnabled);
    }
    $this->get(route('campaigns.replies'))->assertOk()->assertSee('Caixa de respostas');
    $this->get(route('campaigns.advanced'))->assertOk()
        ->assertSee('Central avançada')
        ->assertSee('Fila e entregabilidade');
});

it('preserves operational routes and never enables commercial sending in a UX test', function (): void {
    foreach (['campaigns.index', 'campaigns.replies', 'campaigns.settings',
        'campaigns.queue', 'campaigns.production.index', 'campaigns.intelligence.index',
        'campaigns.advanced'] as $route) {
        expect(Route::has($route))->toBeTrue();
    }
    config()->set('campaign_mail.live_enabled', false);
    expect(config('campaign_mail.live_enabled'))->toBeFalse();
});
