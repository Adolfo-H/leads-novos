<?php

use App\Livewire\DashboardOverview;
use App\Models\Cnae;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function ef32User(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $role])->save();

    return $user;
}

function ef32Company(string $root, string $name, string $uf): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
    ]);

    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => $root.'000100',
        'order_number' => '0001',
        'check_digits' => '00',
        'type' => 'matrix',
        'registration_status' => 'ATIVA',
        'state' => $uf,
        'municipality_name' => $uf === 'PR' ? 'Curitiba' : 'Campinas',
    ]);

    return $company;
}

it('abre D02 com as quatro abas e empresas reais sem consultas externas', function (): void {
    Http::preventStrayRequests();
    ef32Company('91927364', 'Empresa local D02', 'PR');

    Livewire::actingAs(ef32User(User::ROLE_MANAGER))
        ->test(DashboardOverview::class)
        ->assertSee('Explore sua base empresarial')
        ->assertSee('Busca na base local')
        ->call('openBase')
        ->assertSet('dataset', 'companies')
        ->assertSee('Empresa local D02')
        ->assertSee('Abrir dossiê')
        ->call('selectBaseDataset', 'establishments')
        ->assertSet('dataset', 'establishments')
        ->assertSee('Curitiba')
        ->call('selectBaseDataset', 'active')
        ->assertSet('dataset', 'active')
        ->assertSee('Empresa local D02');

    Http::assertNothingSent();
});

it('filtra realmente por UF em empresas e estabelecimentos e permite limpar', function (): void {
    ef32Company('81927364', 'Industria Parana V32', 'PR');
    ef32Company('71927364', 'Industria Sao Paulo V32', 'SP');

    Livewire::actingAs(ef32User(User::ROLE_MANAGER))
        ->test(DashboardOverview::class)
        ->call('openBase')
        ->set('baseUf', 'PR')
        ->assertSee('Industria Parana V32')
        ->assertDontSee('Industria Sao Paulo V32')
        ->call('selectBaseDataset', 'establishments')
        ->assertSee('Industria Parana V32')
        ->assertDontSee('Industria Sao Paulo V32')
        ->call('clearBaseFilters')
        ->assertSet('baseUf', '')
        ->assertSee('Industria Sao Paulo V32');
});

it('filtra os CNAEs com contagens reais do estado selecionado', function (): void {
    $companyPR = ef32Company('61927364', 'Firma CNAE PR', 'PR');
    $companySP = ef32Company('51927364', 'Firma CNAE SP', 'SP');
    $cnae = Cnae::query()->create(['code' => '4623108', 'description' => 'Atividade compartilhada UF']);
    $companyPR->establishments()->firstOrFail()->cnaes()->attach($cnae->id, ['is_primary' => true]);
    $companySP->establishments()->firstOrFail()->cnaes()->attach($cnae->id, ['is_primary' => true]);

    $test = Livewire::actingAs(ef32User(User::ROLE_MANAGER))
        ->test(DashboardOverview::class)
        ->call('selectBaseDataset', 'cnaes')
        ->set('baseUf', 'PR')
        ->assertSee('Atividade compartilhada UF');

    $rows = $test->get('records');
    expect((int) $rows->first()->establishments_count)->toBe(1);
});

it('reserva a base completa aos gestores e mantém os outros datasets', function (): void {
    Livewire::actingAs(ef32User(User::ROLE_SELLER))
        ->test(DashboardOverview::class)
        ->assertDontSee('Empresa local confidencial')
        ->call('openBase')
        ->assertForbidden();

    $test = Livewire::actingAs(ef32User(User::ROLE_MANAGER))
        ->test(DashboardOverview::class);
    $test->call('selectBaseDataset', 'nao_existe')->assertStatus(422);
});

it('preserva as métricas anteriores e centraliza a nova identidade visual', function (): void {
    $blade = file_get_contents(resource_path('views/livewire/dashboard-overview.blade.php'));
    $partial = file_get_contents(resource_path('views/livewire/dashboard-overview/base-figma-v32.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($blade)->toContain('base-figma-v32')
        ->and($partial)->toContain('Cobertura cadastral')
        ->toContain('Base por UF')
        ->toContain('Estrutura empresarial')
        ->toContain('ef32-tabs')
        ->toContain('wire:model.live.debounce.350ms="search"')
        ->toContain('wire:model.live="baseUf"')
        ->toContain('previousPage')
        ->toContain('nextPage')
        ->toContain('companies.show')
        ->and($appCss)->toContain("@import './dashboard-base-v32.css';");
});
