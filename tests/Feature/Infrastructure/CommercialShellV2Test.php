<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the reorganized menu to managers', function () {
    $manager = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $manager->forceFill([
        'commercial_role' => User::ROLE_MANAGER,
    ])->save();

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Dashboard')
        ->assertSee('Leads')
        ->assertSee('Gestão Comercial')
        ->assertSee('Motor de Prospecção')
        ->assertSee('Empresas')
        ->assertSee('Importações');
});

it('hides management navigation from sellers', function () {
    $seller = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $seller->forceFill([
        'commercial_role' => User::ROLE_SELLER,
    ])->save();

    $this->actingAs($seller)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Dashboard')
        ->assertSee('Leads')
        ->assertDontSee('Motor de Prospecção')
        ->assertDontSee('Gestão Comercial')
        ->assertDontSee('Importações');
});
