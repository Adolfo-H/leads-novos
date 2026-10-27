<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

it('renders the real M01 Figma table instead of the old split home', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($user)
        ->get(route('campaigns.index'))
        ->assertSuccessful()
        ->assertSee('data-ec-campaigns-figma="M01-M10-V68"', false)
        ->assertSee('data-ec68-screen="M01"', false)
        ->assertSee('Minhas campanhas')
        ->assertSee('Aceitos hoje SMTP')
        ->assertSee('Comece por uma mensagem')
        ->assertSee('Ver respostas')
        ->assertSee('Nova campanha');
});

it('keeps navigation into campaign composer with original safe workflow', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($user)
        ->test('pages::campaigns.index')
        ->call('newCampaign')
        ->assertSet('screen', 'composer')
        ->assertSet('composerStep', 'audience')
        ->assertSee('Selecione os contatos')
        ->assertSee('Nova campanha')
        ->assertSee('Ritmo e revisão');
});

it('keeps the full original campaign actions and imported V68 styles', function (): void {
    $view = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));
    $partial = file_get_contents(resource_path('views/campaigns/figma-home-v68.blade.php'));
    $style = file_get_contents(resource_path('css/campaigns-figma-v68.css'));
    $detailHeader = file_get_contents(resource_path('views/campaigns/figma-detail-header-v761.blade.php'));

    expect($view)->not->toBeFalse()
        ->and($css)->not->toBeFalse()
        ->and($partial)->not->toBeFalse()
        ->and($style)->not->toBeFalse()
        ->and($detailHeader)->not->toBeFalse();

    expect((string) $view)
        ->toContain("@include('campaigns.figma-home-v68')")
        ->toContain("@include('campaigns.figma-detail-header-v761')")
        ->toContain('saveDraft')
        ->toContain('saveAndStart')
        ->toContain('lawfulBasisConfirmed')
        ->toContain('queuePilot')
        ->toContain('pauseCampaign')
        ->toContain('cancelCampaign')
        ->toContain('public function changeCampaignTablePage');

    expect(substr_count((string) $css, "@import './campaigns-figma-v68.css';"))->toBe(1);
    expect((string) $style)->toContain('.ec68-home')->toContain('.ecm2-shell.is-composer');
    expect((string) $partial)->toContain('openCampaign')->toContain('startWithTemplate');
    expect((string) $detailHeader)
        ->toContain('data-ec76-detail="M05-V761"')
        ->toContain('wire:click');
});
