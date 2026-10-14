<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function companyListRedesignV261Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows the Companies search in the same topbar style as Dashboard', function (): void {
    $response = $this->actingAs(companyListRedesignV261Manager())
        ->get(route('companies.index'));

    $response->assertOk()
        ->assertSee('data-eccl-topbar-search="v261"', false)
        ->assertSee('eccl-v26', false)
        ->assertSee('Pesquisar empresa')
        ->assertSee('CNPJ de referência')
        ->assertSee('Localização')
        ->assertSee('CNAE principal')
        ->assertSee('Origem')
        ->assertSee('Nova empresa');

    expect(substr_count($response->getContent(), 'data-eccl-topbar-search="v261"'))
        ->toBe(1);
});

it('searches real companies from topbar without modifying filters or pagination', function (): void {
    $matching = Company::query()->create([
        'cnpj_root' => '26110001',
        'corporate_name' => 'Empresa Azul V261',
    ]);
    Company::query()->create([
        'cnpj_root' => '26110002',
        'corporate_name' => 'Empresa Vermelha V261',
    ]);

    Livewire::actingAs(companyListRedesignV261Manager())
        ->test('pages::companies.index')
        ->call('companiesTopbarSearch', ' Azul V261 ')
        ->assertSet('search', 'Azul V261')
        ->assertSee($matching->corporate_name)
        ->assertDontSee('Empresa Vermelha V261')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSee('Empresa Vermelha V261');
});

it('keeps existing list behavior and links without added modules', function (): void {
    $view = file_get_contents(resource_path('views/pages/companies/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/company-list-redesign-v26.css'));
    $globalSearch = file_get_contents(resource_path('views/components/global-company-search.blade.php'));

    expect($view)
        ->toContain('eccl-v26-results-panel')
        ->toContain('wire:model.live.debounce.400ms="search"')
        ->toContain('wire:model.live="state"')
        ->toContain('wire:model.live="type"')
        ->toContain('wire:model.live="status"')
        ->toContain('->paginate(')
        ->toContain('route(\'companies.show\', $company)')
        ->and($css)->toContain('REDESIGN_EMPRESAS_V26_1')
        ->and($globalSearch)->toContain('companies-topbar-search');
});
