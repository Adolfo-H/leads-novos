<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the Figma L04 manager overview using live operation data instead of prototype numbers', function (): void {
    $manager = User::factory()->create([
        'name' => 'Gestora Visual V61',
        'email_verified_at' => now(),
    ]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $seller = User::factory()->create([
        'name' => 'Vendedor Visual V61',
        'email_verified_at' => now(),
    ]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Company::query()->create([
        'cnpj_root' => '86146161',
        'corporate_name' => 'Empresa Visual Gestão V61',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::leads.management')
        ->assertSee('data-ecgm-figma="L04-L05-V62"', false)
        ->assertSee('Gestão Comercial')
        ->assertSee('Empresas na base local')
        ->assertSee('Sem responsável')
        ->assertSee('Retornos atrasados')
        ->assertSee('Vendedores ativos')
        ->assertSee('Distribuição da carteira')
        ->assertSee('Empresas que precisam de atenção')
        ->assertSee('Papéis comerciais')
        ->assertSee('Vendedor Visual V61')
        ->assertDontSee('Marina Costa');
});

it('shows the L05 distribution preview without changing ownership until confirmation', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $seller = User::factory()->create([
        'name' => 'Vendedor Previa V61',
        'email_verified_at' => now(),
    ]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $company = Company::query()->create([
        'cnpj_root' => '86146162',
        'corporate_name' => 'Empresa Sem Distribuicao V61',
    ]);
    $company->sdrScore()->create([
        'score' => 88,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v61',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    Livewire::actingAs($manager)
        ->test('pages::leads.management')
        ->set('distributionSellerIds', [(string) $seller->id])
        ->set('distributionLimit', 1)
        ->assertSee('PRÉVIA DA RODADA')
        ->assertSee('Como a carteira ficará')
        ->assertSee('Carteira atual')
        ->assertSee('A receber')
        ->assertSee('Após distribuição')
        ->assertSee('Vendedor Previa V61')
        ->assertSee('Confirmar distribuição');

    expect($company->leadWorkState()->exists())->toBeFalse();
});

it('retains the manager access restriction and preserves the original distribution service', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)->test('pages::leads.management')->assertStatus(403);

    $view = file_get_contents(resource_path('views/pages/leads/⚡management.blade.php'));
    expect($view)->toContain('LeadAutoDistributionService::class')
        ->toContain('CommercialRoleService $service')
        ->toContain('wire:click="autoDistribute"')
        ->toContain('x-on:click="openRoleConfirm(')
        ->toContain('await this.$wire.updateCommercialRole(id, role)')
        ->toContain('role="dialog"')
        ->toContain('ecgm62-attention-table')
        ->toContain('partials.commercial-distribution-preview-v61');

    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->toContain("@import './commercial-management-consolidated.css';");
});
