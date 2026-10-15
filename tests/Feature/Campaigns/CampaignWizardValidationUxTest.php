<?php

use App\Models\EmailCampaign;
use App\Models\HubSpotContact;
use App\Models\User;
use Livewire\Livewire;

it('shows a missing campaign name on the message step instead of failing silently', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotContact::query()->create([
        'hubspot_id' => '99192281',
        'first_name' => 'Contato',
        'last_name' => 'Autorizado',
        'email' => 'teste.autorizado@example.net',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->set('audienceKeys', ['hubspot:99192281'])
        ->call('goToComposerStep', 'message')
        ->assertSet('composerStep', 'message')
        ->assertSee('Nome interno da campanha')
        ->set('subject', 'Apresentação ExportControl')
        ->set('body', 'Olá, gostaria de conhecer melhor suas operações de exportação.')
        ->call('goToComposerStep', 'review')
        ->assertSet('composerStep', 'message')
        ->assertHasErrors(['name' => 'required'])
        ->assertSee('Não foi possível avançar')
        ->set('name', 'Teste de navegação no Prospector')
        ->call('goToComposerStep', 'review')
        ->assertSet('composerStep', 'review')
        ->assertHasNoErrors()
        ->assertSee('Enviar teste');

    expect(EmailCampaign::query()->count())->toBe(0);
});
