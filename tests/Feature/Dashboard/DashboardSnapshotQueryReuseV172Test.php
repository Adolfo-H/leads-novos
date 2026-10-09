<?php

use App\Models\Company;
use App\Services\DashboardLeadMetricsV23Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('selects eligible companies only once per dashboard snapshot', function (): void {
    $eligible = Company::query()->create([
        'cnpj_root' => '97172001',
        'corporate_name' => 'Elegivel pelo score V172',
    ]);

    $eligible->sdrScore()->create([
        'score' => 85,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'metadata' => [],
        'version' => 'v172',
        'calculated_at' => now(),
    ]);

    $dealOnly = Company::query()->create([
        'cnpj_root' => '97172002',
        'corporate_name' => 'Elegivel pelo negocio V172',
    ]);

    $dealOnly->hubSpotLead()->create([
        'hubspot_deal_id' => 'v172-deal',
        'work_status' => 'contacting',
    ]);

    Company::query()->create([
        'cnpj_root' => '97172003',
        'corporate_name' => 'Fora do filtro V172',
    ]);

    $eligibleQueryCount = 0;

    DB::listen(static function (QueryExecuted $query) use (&$eligibleQueryCount): void {
        $sql = strtolower($query->sql);
        if (
            str_contains($sql, 'company_sdr_scores')
            && str_contains($sql, 'company_hubspot_leads')
            && str_contains($sql, 'companies')
        ) {
            $eligibleQueryCount++;
        }
    });

    $result = app(DashboardLeadMetricsV23Service::class)->snapshot();

    expect($result['leads'])->toBe(2)
        ->and($result['unassigned'])->toBe(2)
        ->and($result['with_deal'])->toBe(1)
        ->and($result['overdue'])->toBe(0)
        ->and($result['stale90'])->toBe(0)
        ->and($eligibleQueryCount)->toBe(1);
});

it('retains live results for independently called dashboard drilldowns', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '97172004',
        'corporate_name' => 'Novo negocio V172',
    ]);

    $service = app(DashboardLeadMetricsV23Service::class);
    expect($service->leadCompanyIds())->not->toContain($company->id);

    $company->hubSpotLead()->create([
        'hubspot_deal_id' => 'v172-new-deal',
        'work_status' => 'new',
    ]);

    expect($service->leadCompanyIds())->toContain($company->id)
        ->and($service->withDealCompanyIds())->toContain($company->id);
});
