<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function figmaProspectingManagerV632(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('renders the V63.2 prospecting filters with persistent pickers and visible typed inputs', function (): void {
    $this->actingAs(figmaProspectingManagerV632())
        ->get(route('prospecting.index'))
        ->assertSuccessful()
        ->assertSee('data-ec-prospecting-figma="P01-P02-V63"', false)
        ->assertSee('statePickerOpen', false)
        ->assertSee('cnaePickerOpen', false)
        ->assertSee('class="prv63-picker-trigger"', false)
        ->assertSee('Buscar estado ou sigla')
        ->assertSee('Código CNAE ou descrição')
        ->assertDontSee('Buscar candidato...');
});
