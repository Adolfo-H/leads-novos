<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('redirects guests away from company pages', function () {
    $this
        ->get(route('companies.index'))
        ->assertRedirect(route('login'));

    $this
        ->get(route('companies.create'))
        ->assertRedirect(route('login'));
});

it('renders the company index for an authenticated user', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->get(route('companies.index'))
        ->assertSuccessful()
        ->assertSee('Empresas')
        ->assertSee('Nenhuma empresa encontrada');
});

it('renders the company creation page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->get(route('companies.create'))
        ->assertSuccessful()
        ->assertSee('Nova empresa')
        ->assertSee('Razão social');
});

it('creates a company using the livewire page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::companies.create')
        ->set('cnpj', '11.222.333/0001-81')
        ->set(
            'corporateName',
            'Cooperativa Agropecuária Teste Ltda'
        )
        ->set(
            'fantasyName',
            'Cooperativa Teste'
        )
        ->set('type', 'matrix')
        ->set('registrationStatus', 'ATIVA')
        ->set('state', 'PR')
        ->set('municipalityName', 'Toledo')
        ->set('email', 'contato@teste.com.br')
        ->set('shareCapital', '2.500.000,00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('companies.index'));

    expect(Company::count())->toBe(1);

    $company = Company::firstOrFail();

    expect($company->corporate_name)
        ->toBe(
            'Cooperativa Agropecuária Teste Ltda'
        );

    expect($company->share_capital)
        ->toBe('2500000.00');

    expect(
        $company
            ->establishments()
            ->firstOrFail()
            ->state
    )->toBe('PR');
});

it('rejects an invalid cnpj in the creation page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::companies.create')
        ->set('cnpj', '00.000.000/0000-00')
        ->set(
            'corporateName',
            'Empresa Inválida'
        )
        ->call('save')
        ->assertHasErrors(['cnpj']);

    expect(Company::count())->toBe(0);
});
