<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('separates the HubSpot logo button from the company registration status', function (): void {
    config(['services.hubspot.portal_id' => '21358298']);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::query()->create([
        'cnpj_root' => '99595202',
        'corporate_name' => 'Empresa Dossiê V59.2',
    ]);
    $company->crmCheck()->create([
        'provider' => 'hubspot',
        'status' => 'prospected',
        'external_id' => '7821513385',
        'contacted_count' => 0,
        'associated_deals_count' => 0,
        'metadata' => [],
        'checked_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('companies.show', $company))
        ->assertSuccessful()
        ->assertSee('e592-hubspot-icon', false)
        ->assertSee('e592-status-card', false)
        ->assertSee('Abrir no HubSpot')
        ->assertSee('https://app.hubspot.com/contacts/21358298/record/0-2/7821513385', false);

    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));
    expect($figma)->toContain('DOSSIE_UI_HUBSPOT_V592')
        ->toContain('e592-hubspot-mark')
        ->toContain('target="_blank" rel="noopener noreferrer"')
        ->and((bool) preg_match('/e592-hubspot-icon[\s\S]*?<\/a>\s*@endif\s*<div class="e53-hero-side e592-status-card"/', $figma))
        ->toBeTrue();
});

it('uses the same independently styled light user menu on desktop and mobile', function (): void {
    $sidebar = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($sidebar)->toContain('DOSSIE_UI_HUBSPOT_V592: menu desktop')
        ->toContain('DOSSIE_UI_HUBSPOT_V592: o mesmo menu')
        ->toContain('x-on:click.outside="open = false"')
        ->toContain('x-bind:aria-expanded')
        ->toContain('e592-account-panel ec-topbar-account-menu')
        ->toContain("route('profile.edit')")
        ->toContain("route('logout')")
        ->toContain('Configurações')
        ->toContain('Sair')
        ->and(substr_count($sidebar, 'x-data="{ open: false }"'))->toBe(2)
        ->and($css)->toContain('DOSSIE_UI_HUBSPOT_V592 APP CSS BEGIN')
        ->toContain('.e592-account-trigger:hover')
        ->toContain('background: transparent;')
        ->toContain('background: #ffffff !important;');
});
