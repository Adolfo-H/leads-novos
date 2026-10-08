<?php

use App\Livewire\DashboardOverview;
use App\Models\Cnae;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use App\Services\DashboardStrategicMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows strategic dashboard to managers without network requests', function () {
    Http::preventStrayRequests();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->assertSee('Dashboard estratégico')
        ->assertSee('Meus negócios por etapa')
        ->assertSee('Top oportunidades por CNAE')
        ->assertSee('Concentração por estado')
        ->assertSee('Riscos e oportunidades')
        ->assertSee('Cobertura cadastral');

    Http::assertNothingSent();
});

it('shows no management metrics to sellers', function () {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertSee('Minha fila de leads')
        ->assertDontSee('Top oportunidades por CNAE')
        ->assertDontSee('Negócios no HubSpot');
});

it('counts unique confirmed export companies and actual eligible CNAEs', function () {
    $company = Company::query()->create([
        'cnpj_root' => '94000001',
        'corporate_name' => 'Exportadora de teste',
        'source' => 'test',
    ]);

    $company->exportIntelligence()->create([
        'direct_confirmed' => true,
        'indirect_confirmed' => true,
        'trading_confirmed' => false,
        'researched_at' => now(),
    ]);

    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Prioridade alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'test',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    $establishment = Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '94000001000100',
        'order_number' => '0001',
        'check_digits' => '00',
        'type' => 'matrix',
        'registration_status' => 'ATIVA',
        'state' => 'PR',
        'municipality_name' => 'Toledo',
        'source' => 'test',
    ]);

    $cnae = Cnae::query()->create([
        'code' => '4622200',
        'description' => 'Comércio atacadista de insumos',
    ]);
    $establishment->cnaes()->attach($cnae->id, ['is_primary' => true]);

    $metrics = app(DashboardStrategicMetricsService::class)->snapshot();

    expect($metrics['export']['confirmed_companies'])->toBe(1)
        ->and($metrics['export']['direct'])->toBe(1)
        ->and($metrics['export']['indirect'])->toBe(1)
        ->and($metrics['export']['trading'])->toBe(0)
        ->and($metrics['export']['researched'])->toBe(1)
        ->and($metrics['cnaes'][0]['code'])->toBe('4622200')
        ->and($metrics['cnaes'][0]['companies'])->toBe(1);
});
