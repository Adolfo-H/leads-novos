<?php

use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v185CrmManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user->refresh();
}

function v185CrmOnlyCompany(string $suffix, string $state, string $stage): HubSpotCompany
{
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'v185-company-'.$suffix,
        'name' => 'Empresa Unificada V185 '.$suffix,
        'state' => $state,
    ]);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v185-deal-'.$suffix,
        'name' => 'Negócio '.$suffix,
        'stage_label' => $stage,
        'is_closed' => false,
        'is_closed_won' => false,
    ]);
    $company->deals()->attach($deal->id);

    return $company;
}

it('shares the same CRM-only query between the displayed list and Excel', function (): void {
    $source = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));

    expect($source)
        ->toContain('EXCEL_CRM_QUERY_PARITY_V18_5')
        ->toContain('private function filteredCrmOnlyQuery()')
        ->toContain('return $this->filteredCrmOnlyQuery()')
        ->toContain('$crmOnly = $this->filteredCrmOnlyQuery()')
        ->not->toContain('$crmQuery = HubSpotCompany::query()->whereNull');
});

it('applies search, state and deal-stage filters consistently to the CRM-only list', function (): void {
    v185CrmOnlyCompany('A', 'PR', 'Contato');
    v185CrmOnlyCompany('B', 'SP', 'Contato');
    v185CrmOnlyCompany('C', 'PR', 'Prospecção');

    Livewire::actingAs(v185CrmManager())
        ->test('pages::leads.index')
        ->set('hubSpotOnly', true)
        ->set('search', 'Empresa Unificada V185')
        ->set('state', 'PR')
        ->set('crm', 'stage:Contato')
        ->assertSee('Empresa Unificada V185 A')
        ->assertDontSee('Empresa Unificada V185 B')
        ->assertDontSee('Empresa Unificada V185 C');
});

it('does not expose CRM-only leads to sellers without fiscal portfolio links', function (): void {
    v185CrmOnlyCompany('SELLER', 'PR', 'Contato');
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller->refresh())
        ->test('pages::leads.index')
        ->set('search', 'Empresa Unificada V185')
        ->set('hubSpotOnly', true)
        ->assertDontSee('Empresa Unificada V185 SELLER');
});
