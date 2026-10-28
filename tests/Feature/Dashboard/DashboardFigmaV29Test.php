<?php

use App\Livewire\DashboardOverview;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('renders the Figma D01 manager structure with actual links and 27 UF controls', function (): void {
    Http::preventStrayRequests();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->assertSee('Sua operação, em perspectiva')
        ->assertSee('Explorar base')
        ->assertSee('Atualizar visão')
        ->assertSee('Meus negócios por etapa')
        ->assertSee('Comece por aqui')
        ->assertSee('Presença da carteira por estado')
        ->assertSee('Estados em destaque')
        ->assertSee('Outras ações rápidas')
        ->assertSee('ef30-map-canvas')
        ->assertSee('ds-v7-map-svg')
        ->assertDontSee('MAIS MERCADOS');

    Http::assertNothingSent();
});

it('shows Figma D01-V only with the seller portfolio, never team counts', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertSee('Sua próxima boa conversa')
        ->assertSee('Minha carteira')
        ->assertSee('Prioridades de hoje')
        ->assertSee('Meus negócios por etapa')
        ->assertSee('Abrir minha fila')
        ->assertDontSee('Estados em destaque')
        ->assertDontSee('Leads sem responsável')
        ->assertDontSee('CRM sem CNPJ')
        ->assertDontSee('ds-v7-map-svg');
});

it('keeps only one visual import, uses local data and does not touch business services', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));
    $view = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $parent = file_get_contents(resource_path('views/livewire/dashboard-overview.blade.php'));

    expect($css)
        ->toContain("@import './dashboard-figma-v29.css';")
        ->not->toContain("@import './dashboard-figma-v28.css';");

    expect($parent)->toContain('ef29-activated');
    expect($view)
        ->toContain('DashboardLeadMetricsV23Service')
        ->toContain('myHubSpotStageSummary')
        ->toContain('LeadCrmSituationService')
        ->toContain("'dashboardStage'")
        ->toContain("'queueDuePeriod' => 'overdue'")
        ->toContain('map-panel-v30')
        ->toContain('map-panel-v30')
        ->not->toContain('<header class="ds25-hero ds26-hero">');
});
