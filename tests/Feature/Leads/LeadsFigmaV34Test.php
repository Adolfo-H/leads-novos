<?php

use App\Models\User;
use Livewire\Livewire;

it('renders leads Figma with actual Livewire views and restrictions', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->assertSee('Sua fila comercial')
        ->assertSee('Atrasados / hoje')
        ->assertSee('Minha fila')
        ->assertSee('Novos para prospectar')
        ->assertSee('Leads no recorte')
        ->assertSee('Refinar fila')
        ->assertSee('Pipeline HubSpot');
});

it('keeps seller data isolated while exposing the same visual navigation', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)
        ->test('pages::leads.index')
        ->assertSee('Sua fila comercial')
        ->assertSee('Minha carteira')
        ->assertDontSee('Atribuição pendente no Prospector');

    Livewire::actingAs($seller)
        ->test('pages::leads.management')
        ->assertForbidden();
});

it('keeps manager distribution preview and an explicit in-page confirmation', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test('pages::leads.management')
        ->assertSee('Gestão Comercial')
        ->assertSee('Distribuição automática')
        ->assertSee('Confirmar distribuição?')
        ->assertSee('Gestores e vendedores')
        ->assertSee('distributionSellerIds', false);
});

it('clears stale filters before applying the overdue queue', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->set('search', 'Pesquisa anterior')
        ->call('applyFigmaFollowUp', 'overdue')
        ->assertSet('search', '')
        ->assertSet('workStatus', 'waiting')
        ->assertSet('followUp', 'overdue');
});

it('preserves the real CRM fiscal linker and a strict confirmed distribution action', function (): void {
    $lead = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $management = file_get_contents(resource_path('views/pages/leads/⚡management.blade.php'));
    $crm = file_get_contents(resource_path('views/partials/hubspot-unmatched-leads.blade.php'));

    expect($lead)->toContain('partials.hubspot-unmatched-leads')
        ->toContain('wire:click="exportExcel"')
        ->toContain('wire:click="resumeLead');
    expect($crm)->toContain('openCompanyLink(')
        ->toContain('Identificar empresa');
    expect($management)->toContain('wire:click="autoDistribute"')
        ->toContain('@include(')
        ->toContain('commercial-distribution-preview')
        ->toContain('lf34-confirm-dialog');
});
