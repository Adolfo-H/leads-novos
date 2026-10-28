<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function globalCompanySearchManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('renders one shared desktop search and a responsive mobile search on all principal modules', function (): void {
    $this->actingAs(globalCompanySearchManager());

    foreach (['dashboard', 'leads.index', 'leads.management', 'companies.index', 'contacts.index', 'imports.index', 'prospecting.index'] as $route) {
        $response = $this->get(route($route));
        $response->assertOk()
            ->assertSee('data-ec-global-search="desktop"', false)
            ->assertSee('data-ec-global-search="mobile"', false)
            ->assertSee('ds-v5-topbar-search', false)
            ->assertSee('Buscar empresas, CNPJ...');

        expect(substr_count($response->getContent(), 'data-ec-global-search="desktop"'))
            ->toBe(1);
    }
});

it('keeps the livewire search events from dashboard, companies and leads', function (): void {
    $this->actingAs(globalCompanySearchManager());

    foreach ([
        'dashboard' => 'dashboard-global-search',
        'companies.index' => 'companies-topbar-search',
        'leads.index' => 'leads-topbar-search',
    ] as $route => $event) {
        $this->get(route($route))
            ->assertOk()
            ->assertSee($event, false);
    }
});

it('uses contacts query key and keeps manager-only company routes for fallback', function (): void {
    $this->actingAs(globalCompanySearchManager());

    $this->get(route('contacts.index'))
        ->assertOk()
        ->assertSee('name="q"', false)
        ->assertSee('action="'.route('contacts.index').'"', false);

    $this->get(route('imports.index'))
        ->assertOk()
        ->assertSee('action="'.route('companies.index').'"', false);
});

it('applies a shared header search sent to the companies directory from another module', function (): void {
    $this->actingAs(globalCompanySearchManager());

    Company::query()->create(['cnpj_root' => '26114001', 'corporate_name' => 'Empresa Busca Global VX']);
    Company::query()->create(['cnpj_root' => '26114002', 'corporate_name' => 'Empresa Nao Relacionada VX']);

    $this->get(route('companies.index', ['search' => 'Busca Global VX']))
        ->assertOk()
        ->assertSee('Empresa Busca Global VX')
        ->assertDontSee('Empresa Nao Relacionada VX');
});

it('does not direct sellers to manager-only companies and contacts searches', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $response = $this->actingAs($seller)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('data-ec-global-search="desktop"', false)
        ->assertSee('action="'.route('companies.index').'"', false);

    // O Dashboard pode registrar o listener no HTML do Livewire mesmo para vendedores.
    // Por isso, conferimos apenas o formulario de busca global do cabecalho.
    $html = $response->getContent();
    $matched = preg_match(
        '/<form\b[^>]*data-ec-global-search="desktop"[^>]*>/s',
        $html,
        $form,
    );

    expect($matched)->toBe(1);

    expect($form[0] ?? '')
        ->toContain('action="'.route('companies.index').'"')
        ->not->toContain('dashboard-global-search')
        ->not->toContain('x-on:submit.prevent');
});

it('defines the search in the shared layout instead of per-module copies', function (): void {
    $layout = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    $component = file_get_contents(resource_path('views/components/global-company-search.blade.php'));

    expect($layout)
        ->toContain('<x-global-company-search />')
        ->toContain('<x-global-company-search :mobile="true" />')
        ->not->toContain('lv13-topbar-search')
        ->and($component)
        ->toContain('dashboard-global-search')
        ->toContain('leads-topbar-search')
        ->toContain('companies-topbar-search');
});
