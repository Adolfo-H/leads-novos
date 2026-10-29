<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the L04 layout in the Figma order with real values and no duplicated large seller panels', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $seller = User::factory()->create([
        'name' => 'Vendedor L04 Teste',
        'email_verified_at' => now(),
    ]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Company::query()->create([
        'cnpj_root' => '94620061',
        'corporate_name' => 'Empresa L04 Teste',
    ]);

    Livewire::actingAs($manager)->test('pages::leads.management')
        ->assertSee('data-ecgm-figma="L04-L05-V62"', false)
        ->assertSee('Gestão Comercial')
        ->assertSee('Empresas na base local')
        ->assertSee('Sem responsável')
        ->assertSee('Retornos atrasados')
        ->assertSee('Vendedores ativos')
        ->assertSee('Carga por vendedor')
        ->assertSee('Fila de atenção')
        ->assertSee('Papéis comerciais')
        ->assertSee('Vendedor L04 Teste')
        ->assertDontSee('Marina Costa');

    $view = file_get_contents(resource_path('views/pages/leads/⚡management.blade.php'));
    expect($view)->not->toBeFalse();
    expect(substr_count($view, 'ecgm62-kpi"'))->toBe(4);
    expect(strpos($view, 'ecgm62-kpis'))->toBeLessThan(strpos($view, 'ecgm62-load-card'));
    expect(strpos($view, 'ecgm62-load-card'))->toBeLessThan(strpos($view, 'ecgm62-attention-details'));
    expect(strpos($view, 'ecgm62-attention-details'))->toBeLessThan(strpos($view, 'ecgm62-role-card'));
    expect($view)->not->toContain('ecgm-seller-card-head');
});

it('keeps L05 preview read only until the authorized distribution is confirmed', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $company = Company::query()->create([
        'cnpj_root' => '94620062',
        'corporate_name' => 'Lead L05 para Distribuir',
    ]);
    $company->sdrScore()->create([
        'score' => 88,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v62',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    Livewire::actingAs($manager)->test('pages::leads.management')
        ->set('distributionSellerIds', [(string) $seller->id])
        ->set('distributionLimit', 1)
        ->assertSee('PRÉVIA DA RODADA')
        ->assertSee('Carteira atual')
        ->assertSee('A receber')
        ->assertSee('Após distribuição')
        ->assertSee('Confirmar distribuição');

    expect($company->leadWorkState()->exists())->toBeFalse();
});

it('retains distribution and role permissions while using only scoped visual CSS', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    Livewire::actingAs($seller)->test('pages::leads.management')->assertStatus(403);

    $view = file_get_contents(resource_path('views/pages/leads/⚡management.blade.php'));
    expect($view)->not->toBeFalse();
    expect($view)->toContain('LeadAutoDistributionService::class')
        ->toContain('CommercialRoleService $service')
        ->toContain('wire:click="autoDistribute"')
        ->toContain('wire:click="updateCommercialRole')
        ->toContain('partials.commercial-distribution-preview-v61');

    $styles = file_get_contents(resource_path('css/commercial-management-consolidated.css'));
    expect($styles)->not->toBeFalse();
    expect($styles)->toContain('.ecgm-page.ecgm-v2.ecgm61.ecgm62')
        ->toContain('grid-template-columns: repeat(4')
        ->toContain('@media (max-width: 650px)')
        ->toContain('prefers-reduced-motion: reduce');
});
