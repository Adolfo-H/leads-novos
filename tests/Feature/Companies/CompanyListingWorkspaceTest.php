<?php

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use Livewire\Livewire;

function companyListingWorkspaceManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('renders the compact company list and its empty state', function () {
    $this->actingAs(companyListingWorkspaceManager())
        ->get(route('companies.index'))
        ->assertSuccessful()
        ->assertSee('eccl-page', false)
        ->assertSee('Nenhuma empresa encontrada')
        ->assertSee('Nova empresa')
        ->assertDontSee('ec-companies-hero', false);
});

it('renders a company without establishment data', function () {
    Company::query()->create([
        'cnpj_root' => '87654321',
        'corporate_name' => 'Empresa Sem Estabelecimento UI',
        'source' => 'manual',
    ]);

    $this->actingAs(companyListingWorkspaceManager())
        ->get(route('companies.index'))
        ->assertSuccessful()
        ->assertSee('Empresa Sem Estabelecimento UI')
        ->assertSee('Sem referência');
});

it('renders reference data and keeps the existing filters working', function () {
    $company = app(CompanyService::class)->createOrUpdateFromEstablishment(
        ['corporate_name' => 'Cooperativa Lista UI Teste', 'source' => 'manual'],
        [
            'cnpj' => '11.222.333/0001-81', 'type' => 'matrix',
            'registration_status' => 'ATIVA', 'state' => 'PR',
            'municipality_name' => 'Toledo',
        ],
        [[
            'code' => '4622200',
            'description' => 'Comércio atacadista de soja',
            'is_primary' => true,
        ]],
    );

    Livewire::actingAs(companyListingWorkspaceManager())
        ->test('pages::companies.index')
        ->set('search', 'Cooperativa Lista UI Teste')
        ->set('state', 'PR')
        ->set('type', 'matrix')
        ->set('status', 'ATIVA')
        ->assertSee('Cooperativa Lista UI Teste')
        ->assertSee('11.222.333/0001-81')
        ->assertSee('4622200')
        ->assertSee(route('companies.show', $company), false)
        ->set('status', 'BAIXADA')
        ->assertSee('Nenhuma empresa encontrada')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('state', '')
        ->assertSet('type', '')
        ->assertSet('status', '')
        ->assertSee('Cooperativa Lista UI Teste');
});

it('keeps pagination available in the company list', function () {
    for ($i = 1; $i <= 21; $i++) {
        Company::query()->create([
            'cnpj_root' => str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'corporate_name' => sprintf('Empresa Paginação UI %02d', $i),
        ]);
    }

    Livewire::actingAs(companyListingWorkspaceManager())
        ->test('pages::companies.index')
        ->assertSee('Empresa Paginação UI 01')
        ->assertDontSee('Empresa Paginação UI 21')
        ->call('gotoPage', 2)
        ->assertSee('Empresa Paginação UI 21')
        ->set('search', 'Empresa Paginação UI 01')
        ->assertSee('Empresa Paginação UI 01');
});
