<?php

use App\Livewire\DashboardOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the V26 layout with real data sources and the interactive Brazil map', function (): void {
    Http::preventStrayRequests();

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($user)
        ->test(DashboardOverview::class)
        ->assertSee('Dashboard estratégico')
        ->assertSee('MAIS MERCADOS')
        ->assertSee('Leads cadastrados')
        ->assertSee('Leads sem responsável')
        ->assertSee('Meus negócios por etapa')
        ->assertSee('Ações rápidas')
        ->assertSee('Concentração por estado')
        ->assertSee('Prioridades comerciais')
        ->assertSee('globe-hero.webp')
        ->assertSee('ds-v7-map-svg');

    Http::assertNothingSent();
});

it('keeps management counts hidden from sellers', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($user)
        ->test(DashboardOverview::class)
        ->assertSee('Minha fila de leads')
        ->assertDontSee('Ações rápidas')
        ->assertDontSee('Leads sem responsável');
});

it('retains existing source services and dashboard-only styles without invented trends', function (): void {
    $blade = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $css = file_get_contents(resource_path('css/dashboard-v26.css'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($blade)
        ->toContain('DashboardLeadMetricsV23Service')
        ->toContain('LeadCrmSituationService')
        ->toContain('myHubSpotStageSummary')
        ->toContain('map-interactive-v7')
        ->toContain('ds26-hero-motto')
        ->toContain("'hubSpotOnly' => 1")
        ->toContain("'dashboardView' => 'overdue'")
        ->not->toContain('+12%');

    expect($css)
        ->toContain('.ds26 .ds25-stages')
        ->toContain('.ds26 .ds25-map-content')
        ->toContain('grid-template-columns:repeat(7,minmax(0,1fr))')
        ->not->toContain('body.ec-app-body');

    expect($appCss)->toContain("@import './dashboard-v26.css';");
});
