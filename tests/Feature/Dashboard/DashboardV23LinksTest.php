<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\DashboardLeadMetricsV23Service;
use App\Services\DashboardOwnedDealsV23Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('counts every owned HubSpot stage without mixing another owner', function (): void {
    $me = User::factory()->create(['name' => 'Vendedor V23', 'hubspot_owner_id' => 'owner-v23']);
    $other = User::factory()->create(['name' => 'Outro Vendedor', 'hubspot_owner_id' => 'owner-other']);

    $linked = HubSpotCompany::query()->create(['hubspot_id' => 'hub-v23']);
    foreach (['Futura', 'Recusou', 'Descartes', 'Contrato assinado', 'Prospects', 'Lead frio'] as $idx => $stage) {
        $deal = HubSpotDeal::query()->create([
            'hubspot_id' => 'v23-deal-'.$idx,
            'stage_label' => $stage,
            'owner_name' => 'Vendedor V23',
            'raw_properties' => ['hubspot_owner_id' => 'owner-v23'],
        ]);
        $deal->companies()->attach($linked->id, ['is_primary' => true]);
    }
    $notMine = HubSpotDeal::query()->create([
        'hubspot_id' => 'v23-not-mine',
        'stage_label' => 'Contrato assinado',
        'owner_name' => 'Outro Vendedor',
        'raw_properties' => ['hubspot_owner_id' => 'owner-other'],
    ]);
    $notMine->companies()->attach($linked->id, ['is_primary' => true]);

    $result = app(DashboardOwnedDealsV23Service::class)->forUser($me->id);
    expect($result['total'])->toBe(6)
        ->and(collect($result['stages'])->pluck('label')->all())
        ->toContain('Futura', 'Recusou', 'Descartes', 'Contrato assinado', 'Prospects', 'Lead frio');
    expect(app(DashboardOwnedDealsV23Service::class)->forUser($other->id)['total'])->toBe(1);
});

it('opens unassigned leads using the same population as the dashboard metric', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $company = Company::query()->create(['cnpj_root' => '99440011', 'corporate_name' => 'Lead V23 Sem Dono']);
    $company->sdrScore()->create([
        'score' => 72, 'priority' => 'high', 'label' => 'Alta', 'is_eligible' => true,
        'is_provisional' => false, 'factors' => [], 'version' => 'v23', 'metadata' => [], 'calculated_at' => now(),
    ]);
    $metric = app(DashboardLeadMetricsV23Service::class)->snapshot();
    expect($metric['unassigned'])->toBe(1);

    Livewire::actingAs($manager)->test('pages::leads.index')
        ->set('dashboardView', 'all')
        ->set('owner', 'unassigned')
        ->assertSee('Lead V23 Sem Dono');
});

it('links dashboard charts, risk alerts and profiles to corresponding Leads filters', function (): void {
    $blade = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $leads = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));

    expect($blade)->toContain("'dashboardView' => 'all'")
        ->toContain("'dashboardView' => 'overdue'")
        ->toContain("'dashboardView' => 'stale90'")
        ->toContain('Ações rápidas')
        ->toContain('Meus negócios por etapa')
        ->not->toContain('Top oportunidades por CNAE');
    expect($leads)->toContain('public string $dashboardCompany')
        ->toContain('DashboardStageDrilldownService')
        ->toContain('LeadUnifiedExcelV23Service');
});
