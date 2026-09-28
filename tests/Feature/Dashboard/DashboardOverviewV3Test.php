<?php

// Dashboard v3: testes isolados em banco de testes.

use App\Livewire\DashboardOverview;
use App\Models\Cnae;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function overviewV3Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @param array<string, mixed> $attributes */
function overviewV3Company(array $attributes = []): Company
{
    static $sequence = 91000000;

    return Company::query()->create(array_merge([
        'cnpj_root' => (string) ++$sequence,
        'corporate_name' => 'Empresa de teste do dashboard',
        'source' => 'test',
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function overviewV3Establishment(array $attributes = [], ?Company $company = null): Establishment
{
    $company ??= overviewV3Company();
    $order = str_pad((string) ($company->establishments()->count() + 1), 4, '0', STR_PAD_LEFT);

    return Establishment::query()->create(array_merge([
        'company_id' => $company->id,
        'cnpj' => $company->cnpj_root.$order.'00',
        'order_number' => $order,
        'check_digits' => '00',
        'type' => 'matrix',
        'registration_status' => 'ATIVA',
        'state' => 'PR',
        'municipality_name' => 'Município de teste',
        'source' => 'test',
    ], $attributes));
}

it('opens the new overview without the previous hero or fake integration status', function () {
    Http::preventStrayRequests();
    $this->actingAs(overviewV3Manager())->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Visão geral')
        ->assertSee('Cobertura cadastral')
        ->assertDontSee('ec-dashboard-hero-clean', false)
        ->assertDontSee('Tavily ativo')
        ->assertDontSee('HubSpot conectado');
    Http::assertNothingSent();
});

it('handles an empty database without dividing by zero', function () {
    $page = Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class);
    $s = $page->get('summary');
    expect($s['companies'])->toBe(0)
        ->and($s['establishments'])->toBe(0)
        ->and($s['cnaes'])->toBe(0);
    $page->call('openExplorer', 'establishments')->assertSee('Nenhum estabelecimento encontrado');
});

it('keeps contact and active counts consistent with their filtered lists', function () {
    $company = overviewV3Company(['corporate_name' => 'Grupo teste cobertura']);
    overviewV3Establishment([
        'type' => 'matrix', 'registration_status' => 'ATIVA',
        'email' => 'fiscal@example.com', 'phone_1' => '44999999999', 'phone_2' => null,
    ], $company);
    overviewV3Establishment([
        'type' => 'branch', 'registration_status' => 'BAIXADA',
        'email' => '   ', 'phone_1' => '', 'phone_2' => '4433333333',
    ], $company);
    overviewV3Establishment([
        'type' => 'branch', 'registration_status' => 'ATIVA',
        'email' => null, 'phone_1' => null, 'phone_2' => null,
    ], $company);

    $page = Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class);
    $s = $page->get('summary');
    expect($s['establishments'])->toBe(3)->and($s['active'])->toBe(2)
        ->and($s['email'])->toBe(1)->and($s['phone'])->toBe(2)
        ->and($s['matrix'])->toBe(1)->and($s['branch'])->toBe(2);

    foreach (['active' => 2, 'inactive' => 1, 'email' => 1, 'missing_email' => 2,
        'phone' => 2, 'missing_phone' => 1, 'matrix' => 1, 'branch' => 2] as $filter => $count) {
        $page->call('openExplorer', $filter);
        expect($page->get('records')->total())->toBe($count);
    }
});

it('opens only the requested active establishments', function () {
    overviewV3Establishment(['registration_status' => 'ATIVA'], overviewV3Company(['corporate_name' => 'Empresa ativa exclusiva']));
    overviewV3Establishment(['registration_status' => 'BAIXADA'], overviewV3Company(['corporate_name' => 'Empresa baixada exclusiva']));

    Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class)
        ->call('openExplorer', 'active')
        ->assertSee('Empresa ativa exclusiva')
        ->assertDontSee('Empresa baixada exclusiva');
});

it('lists unique mapped CNAEs and opens their establishments without duplication', function () {
    $establishment = overviewV3Establishment();
    $first = Cnae::query()->create(['code' => '4622200', 'description' => 'Atividade vinculada teste']);
    $second = Cnae::query()->create(['code' => '4623108', 'description' => 'Outra atividade vinculada']);
    Cnae::query()->create(['code' => '0111301', 'description' => 'Atividade sem vínculo']);
    $establishment->cnaes()->attach($first->id, ['is_primary' => true]);
    $establishment->cnaes()->attach($second->id, ['is_primary' => false]);

    $page = Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class);
    expect($page->get('summary')['cnaes'])->toBe(2);
    $page->call('openExplorer', 'cnaes')->assertSee('Atividade vinculada teste')->assertDontSee('Atividade sem vínculo');
    expect($page->get('records')->total())->toBe(2);
    $page->call('openExplorer', 'cnae', '4622200');
    expect($page->get('records')->total())->toBe(1);
});

it('filters by UF and shows missing UF separately', function () {
    overviewV3Establishment(['state' => 'PR']);
    overviewV3Establishment(['state' => 'SP']);
    overviewV3Establishment(['state' => null]);
    $page = Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class)
        ->call('openExplorer', 'state', 'PR');
    expect($page->get('records')->total())->toBe(1);
    $page->call('openExplorer', 'state', '__unknown__');
    expect($page->get('records')->total())->toBe(1);
});

it('resets pagination when searching a dataset', function () {
    $company = overviewV3Company();
    for ($i = 0; $i < 20; $i++) {
        overviewV3Establishment(['registration_status' => 'ATIVA'], $company);
    }
    $page = Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class)
        ->call('openExplorer', 'active')->call('nextPage', 'overviewPage');
    expect($page->get('records')->currentPage())->toBe(2);
    $page->set('search', 'termo que não existe no cadastro 918374');
    expect($page->get('records')->currentPage())->toBe(1)
        ->and($page->get('records')->total())->toBe(0);
});

it('does not grant a seller access to the complete company base', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    Livewire::actingAs($user)->test(DashboardOverview::class)
        ->assertSee('Minha fila de leads')->assertDontSee('Nova prospecção')
        ->call('openExplorer', 'companies')->assertForbidden();
});

it('rejects unknown datasets', function () {
    Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class)
        ->call('openExplorer', 'arbitrary_table')->assertStatus(422);
});

it('refreshes cached indicators on demand', function () {
    $page = Livewire::actingAs(overviewV3Manager())->test(DashboardOverview::class);
    expect($page->get('summary')['establishments'])->toBe(0);
    overviewV3Establishment();
    $page->call('refreshSummary');
    expect($page->get('summary')['establishments'])->toBe(1);
});
