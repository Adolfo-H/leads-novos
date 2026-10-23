<?php

use App\Livewire\DashboardOverview;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('mostra um unico mapa geográfico interativo com o Brasil real, sem quadradinhos', function (): void {
    Http::preventStrayRequests();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->assertSee('Presença da carteira por estado')
        ->assertSee('ef30-map-canvas')
        ->assertSee('Ver mapa geográfico interativo')
        ->assertSee('ds-v7-map-svg')
        ->assertSee('data-uf="SP"', false)
        ->assertDontSee('ef29-map-grid')
        ->assertDontSee('Mapa esquemático');

    Http::assertNothingSent();
});

it('mantém a exploracao de estados conectada aos registros reais', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $company = Company::query()->create([
        'cnpj_root' => '92939392',
        'corporate_name' => 'MAPA BRASIL V30 TESTE',
        'source' => 'test',
    ]);

    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '92939392000100',
        'order_number' => '0001',
        'check_digits' => '00',
        'type' => 'matrix',
        'registration_status' => 'ATIVA',
        'state' => 'PR',
        'source' => 'test',
    ]);

    $page = Livewire::actingAs($manager)->test(DashboardOverview::class);

    $page
        ->assertSee('Paraná: 1 estabelecimento')
        ->call('openExplorer', 'state', 'PR')
        ->assertSee('MAPA BRASIL V30 TESTE');

    expect($page->get('records')->total())->toBe(1);
});

it('não expõe o mapa gerencial para vendedores e mantém o CSS exclusivo do dashboard', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertDontSee('ef30-map-canvas')
        ->assertDontSee('ds-v7-map-svg');

    $css = file_get_contents(resource_path('css/app.css'));
    $view = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $map = file_get_contents(resource_path('views/livewire/dashboard-overview/map-panel-v30.blade.php'));

    expect($css)->toContain("@import './dashboard-map-v30.css';");
    expect($view)->toContain('map-panel-v30')->not->toContain('ef29-map-grid');
    expect($map)->toContain('map-interactive-v7')->not->toContain('ef29-map-grid');
});
