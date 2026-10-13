<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the same topbar search design on commercial management', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $response = $this->actingAs($manager)->get(route('leads.management'));

    $response->assertOk()
        ->assertSee('data-ecmg-topbar-search="v255"', false)
        ->assertSee('Buscar empresas, CNPJ...');

    expect(substr_count($response->getContent(), 'data-ecmg-topbar-search="v255"'))
        ->toBe(1);
});

it('applies the search parameter when coming from the management topbar', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    foreach ([
        ['25550001', 'Empresa Encontrada BuscaV255'],
        ['25550002', 'Empresa Fora do Resultado'],
    ] as [$root, $name]) {
        $company = Company::query()->create([
            'cnpj_root' => $root,
            'corporate_name' => $name,
        ]);
        $company->sdrScore()->create([
            'score' => 80,
            'priority' => 'high',
            'label' => 'Alta',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'metadata' => [],
            'version' => 'v255-test',
            'calculated_at' => now(),
        ]);
    }

    $this->actingAs($manager)
        ->get(route('leads.index', ['search' => 'BuscaV255']))
        ->assertOk()
        ->assertSee('Empresa Encontrada BuscaV255')
        ->assertDontSee('Empresa Fora do Resultado');
});
