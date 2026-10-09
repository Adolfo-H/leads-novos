<?php

use App\Models\Company;
use App\Services\DashboardLeadMetricsV23Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses the same lead populations without changing the public snapshot contract', function (): void {
    $scored = Company::query()->create([
        'cnpj_root' => '99244001',
        'corporate_name' => 'Lead pontuado do dashboard V244',
    ]);

    $scored->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'metadata' => [],
        'version' => 'v244',
        'calculated_at' => now(),
    ]);

    $withDeal = Company::query()->create([
        'cnpj_root' => '99244002',
        'corporate_name' => 'Lead apenas com negocio V244',
    ]);

    $withDeal->hubSpotLead()->create([
        'hubspot_deal_id' => 'v244-deal',
        'work_status' => 'contacting',
    ]);

    Company::query()->create([
        'cnpj_root' => '99244003',
        'corporate_name' => 'Nao elegivel V244',
    ]);

    $eligibleSelections = 0;

    DB::listen(static function (QueryExecuted $event) use (&$eligibleSelections): void {
        $sql = strtolower($event->sql);

        if (
            str_contains($sql, 'from "companies"')
            && str_contains($sql, 'company_sdr_scores')
            && str_contains($sql, 'company_hubspot_leads')
        ) {
            $eligibleSelections++;
        }
    });

    $service = app(DashboardLeadMetricsV23Service::class);
    $bundle = $service->snapshotWithCompanyIds();

    expect($eligibleSelections)->toBe(1)
        ->and($bundle['lead_ids'])->toEqualCanonicalizing([$scored->id, $withDeal->id])
        ->and($bundle['with_deal_ids'])->toBe([$withDeal->id])
        ->and($bundle['summary']['leads'])->toBe(2)
        ->and($bundle['summary']['with_deal'])->toBe(1)
        ->and($bundle['summary']['unassigned'])->toBe(2)
        ->and($bundle['summary']['overdue'])->toBe(0)
        ->and($bundle['summary']['stale90'])->toBe(0);

    expect($service->snapshot())->toEqual($bundle['summary']);
});

it('keeps dashboard filters live after company data changes', function (): void {
    $service = app(DashboardLeadMetricsV23Service::class);

    expect($service->snapshotWithCompanyIds()['summary']['leads'])->toBe(0);

    $company = Company::query()->create([
        'cnpj_root' => '99244004',
        'corporate_name' => 'Negocio adicionado durante a requisicao V244',
    ]);

    $company->hubSpotLead()->create([
        'hubspot_deal_id' => 'v244-new-deal',
        'work_status' => 'new',
    ]);

    $fresh = $service->snapshotWithCompanyIds();

    expect($fresh['summary']['leads'])->toBe(1)
        ->and($fresh['lead_ids'])->toBe([$company->id])
        ->and($fresh['with_deal_ids'])->toBe([$company->id]);
});

it('uses only the shared snapshot sets in the strategic dashboard template', function (): void {
    $blade = file_get_contents(
        resource_path('views/livewire/dashboard-overview/strategic.blade.php')
    );

    expect($blade)->toContain('snapshotWithCompanyIds()')
        ->toContain("\$ds25Bundle['lead_ids']")
        ->toContain("\$ds25Bundle['with_deal_ids']")
        ->toContain("\$ds25Bundle['stale_ids']")
        ->not->toContain('$ds25MetricsService->leadCompanyIds()')
        ->not->toContain('$ds25MetricsService->withDealCompanyIds()')
        ->not->toContain('$ds25MetricsService->staleCompanyIds()');
});
