<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps the HubSpot health in a compact expandable indicator', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->assertSeeHtml('lv14-hubspot-status')
        ->assertSeeHtml('lv14-hubspot-summary')
        ->assertSeeHtml('rf-hubspot-health-checks')
        ->assertSee('Exportar Excel')
        ->assertSee('Limpar filtros')
        ->assertDontSee('Filtros avançados');
});

it('preserves the original filters and workflows', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/leads-hubspot-compact-v14.css'));

    expect($view)->toContain('wire:model.live="crmSituation"')
        ->toContain('wire:model.live="owner"')
        ->not->toContain('wire:model.live="crm"')
        ->toContain('wire:click="applyHubSpotOnlyView"')
        ->toContain('wire:click="clearFilters"')
        ->toContain('wire:click="exportExcel"')
        ->toContain('lv13-lead-line')
        ->not->toContain('lv11-filters-toggle');
    expect($css)->toContain('.lv14-hubspot-status')
        ->toContain('.rf-hubspot-health-checks');
});
