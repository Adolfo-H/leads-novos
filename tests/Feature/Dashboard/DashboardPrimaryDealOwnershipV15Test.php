<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Services\DashboardLeadMetricsV23Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function v15CommercialCompany(string $root): array
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => 'Empresa V15 '.$root,
    ]);

    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Alta prioridade',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'metadata' => [],
        'version' => 'v15',
        'calculated_at' => now(),
    ]);

    $company->hubSpotLead()->create([
        'last_activity_at' => now()->subDays(120),
    ]);

    $remote = HubSpotCompany::query()->create([
        'hubspot_id' => 'company-v15-'.$root,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    return [$company, $remote];
}

it('counts only the primary in multi-company deals and accepts singleton deals', function (): void {
    [$primary, $remotePrimary] = v15CommercialCompany('95150001');
    [$secondary, $remoteSecondary] = v15CommercialCompany('95150002');
    [$single, $remoteSingle] = v15CommercialCompany('95150003');
    [$ambiguousA, $remoteAmbA] = v15CommercialCompany('95150004');
    [$ambiguousB, $remoteAmbB] = v15CommercialCompany('95150005');
    [$multipleA, $remoteMultipleA] = v15CommercialCompany('95150006');
    [$multipleB, $remoteMultipleB] = v15CommercialCompany('95150007');

    $deal = HubSpotDeal::query()->create(['hubspot_id' => 'v15-multiple']);
    $deal->companies()->attach($remotePrimary->id, ['is_primary' => true]);
    $deal->companies()->attach($remoteSecondary->id, ['is_primary' => false]);

    $singleton = HubSpotDeal::query()->create(['hubspot_id' => 'v15-single']);
    $singleton->companies()->attach($remoteSingle->id, ['is_primary' => false]);

    $ambiguous = HubSpotDeal::query()->create(['hubspot_id' => 'v15-ambiguous-none']);
    $ambiguous->companies()->attach($remoteAmbA->id, ['is_primary' => false]);
    $ambiguous->companies()->attach($remoteAmbB->id, ['is_primary' => false]);

    $multiplePrimary = HubSpotDeal::query()->create(['hubspot_id' => 'v15-ambiguous-two']);
    $multiplePrimary->companies()->attach($remoteMultipleA->id, ['is_primary' => true]);
    $multiplePrimary->companies()->attach($remoteMultipleB->id, ['is_primary' => true]);

    $actual = app(DashboardLeadMetricsV23Service::class)->withDealCompanyIds();
    $expected = [$primary->id, $single->id];
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

it('does not give recent activity of a multi-company deal to its secondary company', function (): void {
    [$primary, $remotePrimary] = v15CommercialCompany('95150011');
    [$secondary, $remoteSecondary] = v15CommercialCompany('95150012');

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v15-recent-deal',
        'last_activity_at' => now()->subDay(),
    ]);
    $deal->companies()->attach($remotePrimary->id, ['is_primary' => true]);
    $deal->companies()->attach($remoteSecondary->id, ['is_primary' => false]);

    $stale = app(DashboardLeadMetricsV23Service::class)->staleCompanyIds();

    expect($stale)->toContain($secondary->id)
        ->not->toContain($primary->id);
});

it('does not trust an inherited fiscal link in deal-derived dashboard data', function (): void {
    [$company, $remote] = v15CommercialCompany('95150021');

    // Simula vinculo fiscal historico que a validacao atual bloqueia na escrita.
    DB::table('hubspot_companies')
        ->where('id', $remote->id)
        ->update(['match_source' => 'hubspot_related_old_import']);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v15-unsafe-deal',
        'last_activity_at' => now()->subDay(),
    ]);
    $deal->companies()->attach($remote->id, ['is_primary' => true]);

    $metrics = app(DashboardLeadMetricsV23Service::class);

    expect($metrics->withDealCompanyIds())->not->toContain($company->id)
        ->and($metrics->staleCompanyIds())->toContain($company->id);
});
