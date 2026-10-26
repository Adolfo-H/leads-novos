<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the confirmed HubSpot company shortcut next to the company registration status', function (): void {
    config(['services.hubspot.portal_id' => '21358298']);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::query()->create([
        'cnpj_root' => '99595101',
        'corporate_name' => 'Empresa visual V59.1',
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
        ->assertSee('Abrir no HubSpot')
        ->assertSee('https://app.hubspot.com/contacts/21358298/record/0-2/7821513385', false)
        ->assertSee('e591-hubspot-link', false)
        ->assertSee('e591-legal-state', false);
});

it('keeps both group contact cards and the topbar user menu aligned with V59.1', function (): void {
    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));
    $group = file_get_contents(resource_path('views/partials/company-dossier/figma-group-v53.blade.php'));
    $sidebar = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($figma)
        ->toContain('DOSSIE_UI_HUBSPOT_V591')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('e591-hubspot-link')
        ->and($group)
        ->toContain('e591-channel-card--email')
        ->toContain('e591-channel-card--phone')
        ->toContain('mailto:')
        ->toContain('Mostrar menos')
        ->and($sidebar)
        ->toContain('e591-account-menu-mobile')
        ->toContain('ec-topbar-account-menu')
        ->toContain('ec-profile-menu-item')
        ->and($css)
        ->toContain('DOSSIE_UI_HUBSPOT_V591 APP CSS BEGIN');
});
