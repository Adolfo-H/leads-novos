<?php

use App\Livewire\DashboardOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the strategic layout with bounded globe and map assets', function () {
    Http::preventStrayRequests();

    $manager = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $manager->forceFill([
        'commercial_role' => User::ROLE_MANAGER,
    ])->save();

    Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->assertSee('Dashboard estratégico')
        ->assertSee('Leads cadastrados')
        ->assertSee('Meus negócios por etapa')
        ->assertSee('Ações rápidas')
        ->assertSee('Concentração por estado')
        ->assertSee('Prioridades comerciais')
        ->assertSee('ds-v7-map-svg')
        ->assertSee('globe-hero.webp')
        ->assertSee('Cobertura cadastral');

    Http::assertNothingSent();
});

it('opens existing company explorer with the global search and protects the action', function () {
    $manager = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $manager->forceFill([
        'commercial_role' => User::ROLE_MANAGER,
    ])->save();

    Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->call('dashboardGlobalSearch', '  Exemplo CNPJ  ')
        ->assertSet('dataset', 'companies')
        ->assertSet('search', 'Exemplo CNPJ');

    $seller = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $seller->forceFill([
        'commercial_role' => User::ROLE_SELLER,
    ])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->call('dashboardGlobalSearch', 'restrito')
        ->assertForbidden();
});

it('does not render sensitive management widgets to sellers', function () {
    $seller = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $seller->forceFill([
        'commercial_role' => User::ROLE_SELLER,
    ])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertSee('Minha fila de leads')
        ->assertDontSee('Empresas com mais estabelecimentos')
        ->assertDontSee('Negócios no HubSpot');
});

it('compiles the CSS rules that keep the map and globe bounded', function () {
    $css = file_get_contents(resource_path('css/dashboard-strategic-v5.css'));

    expect($css)
        ->toContain('.ds-v5-globe')
        ->toContain('.ds-v5-map')
        ->toContain('max-height: 176px')
        ->toContain('.ds-v5-kpis')
        ->toContain('grid-template-columns: repeat(3, minmax(0,1fr))');
});
