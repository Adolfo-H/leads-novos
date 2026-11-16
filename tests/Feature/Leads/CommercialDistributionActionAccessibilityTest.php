<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps the approved L04 button text and the accessible distribution action name', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test('pages::leads.management')
        ->assertSee('Gestão Comercial')
        ->assertSee('Distribuir carteira')
        ->assertSee('Distribuição automática')
        ->assertSee('aria-label="Distribuição automática — abrir prévia de distribuição"', false)
        ->assertSee('Confirmar distribuição');

    $source = file_get_contents(resource_path('views/pages/leads/⚡management.blade.php'));
    expect($source)->not->toBeFalse();
    expect($source)->toContain('data-ecgm-figma="L04-L05-V62"')
        ->toContain('ecgm62-kpis')
        ->toContain('ecgm62-load-card')
        ->toContain('ecgm62-attention-details')
        ->toContain('ecgm62-role-card')
        ->toContain('x-on:click="view=\'distribution\'; showConfirm=false"')
        ->not->toContain('ecgm-seller-card-head');
});
