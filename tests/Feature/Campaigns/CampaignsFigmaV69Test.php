<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

it('shows simplified campaigns homepage with actual sending readiness and unchanged workflows', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($user)
        ->get(route('campaigns.index'))
        ->assertSuccessful()
        ->assertSee('data-ec-campaigns-simple="M01-M10-V69"', false)
        ->assertSee('Minhas campanhas')
        ->assertSee('Comece por uma mensagem')
        ->assertSee('Configurações gerais')
        ->assertDontSee('Mais opções')
        ->assertSee('Nova campanha');

    Livewire::actingAs($user)
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->assertSet('screen', 'composer')
        ->assertSee('Selecione os contatos')
        ->assertSee('Ritmo e revisão');
});

it('renders the clean M07 reply layout with functional filters and accessible long messages', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($user)
        ->get(route('campaigns.replies'))
        ->assertSuccessful()
        ->assertSee('data-ec69-replies="M07-V69"', false)
        ->assertSee('Encontre uma conversa')
        ->assertSee('Remetente ou assunto')
        ->assertSee('Comerciais')
        ->assertSee('Respostas de teste')
        ->assertSee('Respostas registradas')
        ->assertDontSee('Ferramentas de follow-up');
});

it('keeps campaign backend protections and moves advanced options away from primary navigation', function (): void {
    $index = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $replies = file_get_contents(resource_path('views/pages/campaigns/⚡replies.blade.php'));
    $nav = file_get_contents(resource_path('views/campaigns/module-nav.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));
    $style = file_get_contents(resource_path('css/campaigns-simple-v69.css'));

    expect($index)->not->toBeFalse()
        ->and($replies)->not->toBeFalse()
        ->and($nav)->not->toBeFalse()
        ->and($css)->not->toBeFalse()
        ->and($style)->not->toBeFalse();

    expect((string) $index)->toContain('saveAndStart')
        ->toContain('startCampaign')
        ->toContain('lawfulBasisConfirmed')
        ->toContain('queuePilot')
        ->toContain('CampaignUnsubscribeLink');
    expect((string) $replies)->toContain('wire:click="loadBody"')
        ->toContain('chooseKind(\'pilot\')');
    expect((string) $nav)->toContain('EC_CAMPAIGNS_CORE_V72')
        ->not->toContain('ec69-more-options')
        ->toContain('Configurações gerais')
        ->toContain('Respostas');
    expect((string) $style)->toContain('.ec69-reply-filter-card')
        ->toContain('.ec21-inbox-message');
    expect(substr_count((string) $css, "@import './campaigns-simple-v69.css';"))->toBe(1);
});
