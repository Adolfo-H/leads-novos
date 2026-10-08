<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\DashboardStageDrilldownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('opens assigned companies from a real HubSpot stage without leaking another seller', function () {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $another = User::factory()->create(['email_verified_at' => now()]);

    $mine = Company::query()->create([
        'cnpj_root' => '99310001',
        'corporate_name' => 'Empresa Recusou Minha',
    ]);
    $mine->leadWorkState()->create(['assigned_user_id' => $manager->id]);
    // Um Deal Recusou pode ficar fora do score SDR; o drilldown precisa encontrá-lo.
    CompanyHubSpotLead::query()->create([
        'company_id' => $mine->id,
        'hubspot_deal_id' => 'v10-deal-refused',
        'work_status' => 'refused',
    ]);
    $linked = HubSpotCompany::query()->create([
        'hubspot_id' => 'v10-company-refused',
        'company_id' => $mine->id,
        'match_source' => 'manual_verified',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v10-deal-refused',
        'stage_label' => 'Recusou',
    ]);
    $deal->companies()->attach($linked->id, ['is_primary' => true]);

    $other = Company::query()->create([
        'cnpj_root' => '99310002',
        'corporate_name' => 'Empresa Recusou Outro',
    ]);
    $other->leadWorkState()->create(['assigned_user_id' => $another->id]);
    $otherLinked = HubSpotCompany::query()->create([
        'hubspot_id' => 'v10-company-other',
        'company_id' => $other->id,
        'match_source' => 'manual_verified',
    ]);
    $otherDeal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v10-deal-other',
        'stage_label' => 'Recusou',
    ]);
    $otherDeal->companies()->attach($otherLinked->id, ['is_primary' => true]);

    $ids = app(DashboardStageDrilldownService::class)
        ->companyIdsForStage((int) $manager->id, 'Recusou');
    expect($ids)->toBe([$mine->id]);

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->set('owner', 'mine')
        ->set('dashboardStage', 'Recusou')
        ->assertSee('Empresa Recusou Minha')
        ->assertDontSee('Empresa Recusou Outro')
        ->assertSee('Filtro aplicado pelo dashboard', false);
});

it('restricts unassigned and HubSpot deal KPIs to the active operational scope', function () {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    foreach ([
        ['root' => '99320001', 'name' => 'Lead Ativo Sem Dono', 'status' => 'new', 'deal' => null],
        ['root' => '99320002', 'name' => 'Lead Ativo Com Negocio', 'status' => 'contacting', 'deal' => 'v10-deal-active'],
        ['root' => '99320003', 'name' => 'Lead Recusado Com Negocio', 'status' => 'refused', 'deal' => 'v10-deal-refused'],
    ] as $item) {
        $company = Company::query()->create([
            'cnpj_root' => $item['root'],
            'corporate_name' => $item['name'],
        ]);
        $company->sdrScore()->create([
            'score' => 70,
            'priority' => 'high',
            'label' => 'Prioridade alta',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
        ]);
        CompanyHubSpotLead::query()->create([
            'company_id' => $company->id,
            'hubspot_deal_id' => $item['deal'],
            'work_status' => $item['status'],
        ]);
        if ($item['name'] !== 'Lead Ativo Sem Dono') {
            $company->leadWorkState()->create(['assigned_user_id' => $manager->id]);
        }
    }

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->set('dashboardView', 'active')
        ->set('owner', 'unassigned')
        ->assertSee('Lead Ativo Sem Dono')
        ->assertDontSee('Lead Ativo Com Negocio');

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->set('dashboardView', 'with_deal')
        ->assertSee('Lead Ativo Com Negocio')
        ->assertDontSee('Lead Recusado Com Negocio')
        ->assertDontSee('Lead Ativo Sem Dono');
});

it('uses explicit drilldown links instead of decorative KPI trend lines', function () {
    $source = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $leads = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));

    expect($source)
        ->toContain('dashboardStage')
        ->toContain('dashboardView')
        ->toContain('ds-v10-chart-link')
        ->not->toContain('ds-v5-kpi-ornament')
        ->and($leads)
        ->toContain('public string $dashboardStage')
        ->toContain('public string $dashboardView')
        ->toContain('DashboardStageDrilldownService');
});
