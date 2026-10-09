<?php

use App\Livewire\DashboardOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the real redesigned dashboard with globe, interactive map and actions', function (): void {
    Http::preventStrayRequests();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->assertSee('Dashboard estratégico')
        ->assertSee('Leads cadastrados')
        ->assertSee('Meus negócios por etapa')
        ->assertSee('Ações rápidas')
        ->assertSee('Concentração por estado')
        ->assertSee('Prioridades comerciais')
        ->assertSee('globe-hero.webp')
        ->assertSee('ds-v7-map-svg')
        ->assertSee('ds25-kpis');

    Http::assertNothingSent();
});

it('keeps manager-only indicators away from sellers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertSee('Minha fila de leads')
        ->assertDontSee('Ações rápidas')
        ->assertDontSee('Leads sem responsável');
});

it('uses isolated styles with bounded images and no global search overrides', function (): void {
    $blade = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $css = file_get_contents(resource_path('css/dashboard-v25.css'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($blade)
        ->toContain('DashboardLeadMetricsV23Service')
        ->toContain('LeadCrmSituationService')
        ->toContain('myHubSpotStageSummary')
        ->toContain('map-interactive-v7')
        ->toContain("'dashboardView' => 'overdue'")
        ->toContain("'hubSpotOnly' => 1")
        ->not->toContain('$dashboard[\'summary_cards\']');

    expect($css)
        ->toContain('.ds25-globe')
        ->toContain('height: 166px')
        ->toContain('.ds25-stages')
        ->toContain('grid-template-columns: repeat(7,minmax(0,1fr))')
        ->not->toContain('body.ec-app-body.ec-dashboard-mode .ds-v5-topbar-search');

    expect($appCss)->toContain("@import './dashboard-v25.css';");
});
