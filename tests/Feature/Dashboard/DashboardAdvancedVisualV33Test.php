<?php

use App\Livewire\DashboardOverview;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('renders three real coverage metrics and interactive state and structure panels', function (): void {
    Http::preventStrayRequests();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->call('openBase')
        ->assertSee('Indicadores cadastrais avançados')
        ->assertSee('Cobertura cadastral')
        ->assertSee('Situação ativa')
        ->assertSee('E-mail informado')
        ->assertSee('Telefone informado')
        ->assertSee('Base por UF')
        ->assertSee('Estrutura empresarial')
        ->assertSee('data-ef33="cadastral-polished"', false)
        ->assertSee('ef33-uf-columns', false);

    Http::assertNothingSent();
});

it('preserves original Livewire filtering and excludes advanced management data for sellers', function (): void {
    $view = file_get_contents(resource_path('views/livewire/dashboard-overview/base-figma-v32.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));
    $newCss = file_get_contents(resource_path('css/dashboard-advanced-v33.css'));

    expect($view)->not->toBeFalse()
        ->toContain("openExplorer('state',")
        ->toContain("openExplorer('matrix')")
        ->toContain("openExplorer('branch')")
        ->toContain('eo-meter')
        ->toContain('eo-region-meter')
        ->toContain('eo-structure-meter');

    expect($appCss)->toContain("@import './dashboard-base-v32.css';")
        ->toContain("@import './dashboard-advanced-v33.css';");
    expect(strpos((string) $appCss, "@import './dashboard-advanced-v33.css';"))
        ->toBeGreaterThan(strpos((string) $appCss, "@import './dashboard-base-v32.css';"));
    expect($newCss)->toContain('.ef33-advanced')
        ->toContain('.eo-coverage-title strong')
        ->toContain('.eo-region-pct')
        ->toContain('.eo-structure-stat strong');

    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)->test(DashboardOverview::class)
        ->assertDontSee('data-ef33="cadastral-polished"', false);
});
