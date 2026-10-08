<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders four compact controls without legacy action-card classes', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($user)->test('pages::leads.index')
        ->assertSee('Atrasados')
        ->assertSee('Vencem hoje')
        ->assertSee('Minha fila')
        ->assertSee('Novos')
        ->assertSeeHtml('lv12-card-grid')
        ->assertSee('Pipeline HubSpot')
        ->assertSee('Exportar Excel');
});

it('keeps filtering and real commercial actions available', function () {
    $blade = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $partial = file_get_contents(resource_path('views/partials/leads-action-center-v11.blade.php'));
    $css = file_get_contents(resource_path('css/leads-visual-v12.css'));

    expect($blade)
        ->toContain('lv12-next-step')
        ->toContain('wire:click="exportExcel"')
        ->toContain('wire:model.live="owner"')
        ->toContain('wire:model.live="crm"')
        ->toContain('assignOwner(')
        ->toContain('Abrir dossiê')
        ->toContain('HubSpot ↗')
        ->toContain('private function filteredLeadsQuery()');

    expect($partial)
        ->toContain('applyDailyView')
        ->toContain('applyFollowUpView')
        ->toContain('applyReprospectingReadyView')
        ->not->toContain('rf-action-card');

    expect($css)
        ->toContain('.lv12-card-copy')
        ->toContain('.lv12-card-value')
        ->toContain('.rf-lead-row')
        ->toContain('@media (max-width: 600px)');
});
