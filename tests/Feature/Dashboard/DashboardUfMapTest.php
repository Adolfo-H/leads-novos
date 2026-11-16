<?php

use App\Livewire\DashboardOverview;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Livewire\Livewire;

function v48StateCompany(string $root, string $name, ?string $uf): void
{
    $company = Company::query()->create(['cnpj_root' => $root, 'corporate_name' => $name]);
    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => $root.'000100',
        'order_number' => '0001',
        'check_digits' => '00',
        'type' => 'matrix',
        'registration_status' => 'ATIVA',
        'state' => $uf,
        'source' => 'test',
    ]);
}

it('syncs a map click in MT with the UF dropdown, allows RJ without combining both states and clears it', function (): void {
    v48StateCompany('48482001', 'V48 MAPA MT', 'MT');
    v48StateCompany('48482002', 'V48 MAPA RJ', 'RJ');
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $page = Livewire::actingAs($manager)->test(DashboardOverview::class)
        ->call('openExplorer', 'state', 'MT')
        ->assertSet('dataset', 'state')
        ->assertSet('filterValue', 'MT')
        ->assertSet('baseUf', 'MT')
        ->assertSee('V48 MAPA MT')
        ->assertDontSee('V48 MAPA RJ');
    expect($page->get('records')->total())->toBe(1);

    $page->set('baseUf', 'RJ')
        ->assertSet('filterValue', 'RJ')
        ->assertSee('V48 MAPA RJ')
        ->assertDontSee('V48 MAPA MT');
    expect($page->get('records')->total())->toBe(1);

    $page->call('clearBaseFilters')
        ->assertSet('baseUf', '')
        ->assertSet('dataset', 'establishments')
        ->assertSee('V48 MAPA MT')
        ->assertSee('V48 MAPA RJ');
    expect($page->get('records')->total())->toBe(2);
});

it('still supports records without a UF and switching from that recorte to a valid state', function (): void {
    v48StateCompany('48482003', 'V48 SEM UF', null);
    v48StateCompany('48482004', 'V48 EMPRESA RJ', 'RJ');
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $page = Livewire::actingAs($manager)->test(DashboardOverview::class)
        ->call('openExplorer', 'state', '__unknown__')
        ->assertSet('baseUf', '')
        ->assertSee('V48 SEM UF')
        ->assertDontSee('V48 EMPRESA RJ')
        ->set('baseUf', 'RJ')
        ->assertSet('filterValue', 'RJ')
        ->assertSee('V48 EMPRESA RJ')
        ->assertDontSee('V48 SEM UF');
});

it('keeps the UF exploratory records restricted to managers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    Livewire::actingAs($seller)->test(DashboardOverview::class)
        ->call('openExplorer', 'state', 'MT')
        ->assertForbidden();
});
