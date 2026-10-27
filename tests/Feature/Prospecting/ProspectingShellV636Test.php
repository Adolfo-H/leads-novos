<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the same light global header as Leads with title, search, user and sidebar settings', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($user)
        ->get(route('prospecting.index'))
        ->assertSuccessful()
        ->assertSee('Motor de prospecção')
        ->assertSee('data-ec-prospecting-topbar="v636"', false)
        ->assertSee('Buscar empresas, CNPJ...')
        ->assertSee('Configurações')
        ->assertSee('data-ec-prospecting-figma="P01-P02-V63"', false)
        ->assertSee('Dimensione a busca')
        ->assertDontSee('Inteligência que conecta')
        ->assertDontSee('initSidebarFooter()')
        ->assertDontSee('initPageChrome()');
});

it('keeps Alpine x-data valid and the V63.4 candidate quantity shortcuts intact', function (): void {
    $path = resource_path('views/pages/prospecting/partials/figma-index-v63.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->not->toBeFalse()
        ->toContain('matches(query, text)')
        ->toContain('prv63-input-number')
        ->toContain('prv63-number-quick')
        ->not->toContain('initSidebarFooter()')
        ->not->toContain('initPageChrome()');
});
