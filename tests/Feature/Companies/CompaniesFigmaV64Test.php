<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function companiesFigmaV64Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('renders E01 with real counters, two-column filters and one row per company', function (): void {
    $manager = companiesFigmaV64Manager();

    $company = Company::query()->create(['cnpj_root' => '11222333', 'corporate_name' => 'EMPRESA REAL DO FIGMA V64', 'source' => 'manual']);
    $company->establishments()->create([
        'cnpj' => '11222333000181', 'order_number' => '0001', 'check_digits' => '81',
        'type' => 'matrix', 'state' => 'PR', 'registration_status' => 'ATIVA', 'municipality_name' => 'Toledo',
    ]);

    $this->actingAs($manager)->get(route('companies.index'))
        ->assertSuccessful()
        ->assertSee('data-ec-companies-figma="E01-V64"', false)
        ->assertSee('Sua base de empresas')
        ->assertSee('Empresas locais')
        ->assertSee('Estabelecimentos')
        ->assertSee('Ativos')
        ->assertSee('Pesquisar e filtrar')
        ->assertSee('Empresas cadastradas')
        ->assertSee('EMPRESA REAL DO FIGMA V64')
        ->assertSee('Situação / Origem')
        ->assertSee(route('companies.show', $company), false);
});

it('keeps E01 filters working together and opens the existing dossier', function (): void {
    $manager = companiesFigmaV64Manager();

    $pr = Company::query()->create(['cnpj_root' => '22222222', 'corporate_name' => 'FILTRO FIGMA EMPRESAS PR', 'source' => 'manual']);
    $pr->establishments()->create([
        'cnpj' => '22222222000108', 'order_number' => '0001', 'check_digits' => '08',
        'type' => 'matrix', 'state' => 'PR', 'registration_status' => 'ATIVA',
    ]);
    $sp = Company::query()->create(['cnpj_root' => '33333333', 'corporate_name' => 'FILTRO FIGMA EMPRESAS SP', 'source' => 'manual']);
    $sp->establishments()->create([
        'cnpj' => '33333333000191', 'order_number' => '0001', 'check_digits' => '91',
        'type' => 'matrix', 'state' => 'SP', 'registration_status' => 'ATIVA',
    ]);

    Livewire::actingAs($manager)->test('pages::companies.index')
        ->set('state', 'PR')
        ->set('type', 'matrix')
        ->set('status', 'ATIVA')
        ->assertSee('FILTRO FIGMA EMPRESAS PR')
        ->assertDontSee('FILTRO FIGMA EMPRESAS SP')
        ->call('clearFilters')
        ->assertSet('state', '')
        ->assertSet('type', '')
        ->assertSet('status', '');
});

it('renders E02 without changing its save action or required fields', function (): void {
    $this->actingAs(companiesFigmaV64Manager())
        ->get(route('companies.create'))
        ->assertSuccessful()
        ->assertSee('data-ec-companies-figma="E02-V64"', false)
        ->assertSee('Nova empresa')
        ->assertSee('Cadastro e localização')
        ->assertSee('Canais empresariais')
        ->assertSee('Prévia do cadastro')
        ->assertSee('wire:submit="save"', false)
        ->assertSee('Salvar empresa');
});

it('renders E03 and E04 without altering the existing company and filial operations', function (): void {
    $manager = companiesFigmaV64Manager();
    $company = Company::query()->create([
        'cnpj_root' => '44556677',
        'corporate_name' => 'GRUPO V64 MATRIZ E FILIAIS',
    ]);

    $this->actingAs($manager)->get(route('companies.edit', $company))
        ->assertSuccessful()
        ->assertSee('data-ec-companies-figma="E03-V64"', false)
        ->assertSee('Editar empresa e matriz')
        ->assertSee('Salvar alterações');

    $this->actingAs($manager)->get(route('companies.branches.create', $company))
        ->assertSuccessful()
        ->assertSee('data-ec-companies-figma="E04-V64"', false)
        ->assertSee('Vínculo')
        ->assertSee('Cadastrar filial')
        ->assertSee('GRUPO V64 MATRIZ E FILIAIS');
});

it('preserves the modern E05 through E09 dossier and existing actions', function (): void {
    // O wrapper pertence à página Volt; o partial do Figma usa e53-figma.
    // Validar ambos evita exigir a classe e53-dossier no arquivo incorreto.
    $page = file_get_contents(resource_path('views/pages/companies/⚡show.blade.php'));
    $source = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));
    $stylesheet = file_get_contents(resource_path('css/company-dossier-figma-v53.css'));

    expect($page)->not->toBeFalse()
        ->and($source)->not->toBeFalse()
        ->and($stylesheet)->not->toBeFalse()
        ->and($page)->toContain('ec-dossier-page e53-dossier')
        ->and($page)->toContain("@include('partials.company-dossier.figma-v53')")
        ->and($source)->toContain('class="e53-figma"')
        ->and($source)->toContain('data-ec-dossier-figma="E05-E09"')
        ->and($source)->toContain("@include('partials.company-dossier.figma-summary-v53')")
        ->and($source)->toContain("@include('partials.company-dossier.figma-cnaes-v53')")
        ->and($stylesheet)->toContain('.ec-dossier-page.e53-dossier .e53-figma');
});
