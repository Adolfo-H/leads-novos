<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Services\DashboardLeadMetricsV23Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('agrega contagens das associacoes uma vez em ambas as consultas do Dashboard', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '99246001',
        'corporate_name' => 'Empresa de teste V246',
    ]);

    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'metadata' => [],
        'version' => 'v246',
        'calculated_at' => now(),
    ]);

    $company->hubSpotLead()->create([
        'last_activity_at' => now()->subDays(120),
    ]);

    $hubspotCompany = HubSpotCompany::query()->create([
        'hubspot_id' => 'v246-company',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v246-deal',
        'last_activity_at' => now()->subDays(120),
    ]);

    // Negocio com uma unica Company: continua valido sem Primary.
    $deal->companies()->attach($hubspotCompany->id, ['is_primary' => false]);

    $captured = [];

    DB::listen(static function (QueryExecuted $event) use (&$captured): void {
        $sql = strtolower($event->sql);

        if (str_contains($sql, 'ec_owner_counts')) {
            $captured[] = $sql;
        }
    });

    $metrics = app(DashboardLeadMetricsV23Service::class);

    expect($metrics->withDealCompanyIds())->toContain($company->id)
        ->and($metrics->staleCompanyIds())->toContain($company->id)
        ->and($captured)->toHaveCount(2);

    foreach ($captured as $sql) {
        expect($sql)->toContain('group by')
            ->toContain('ec_owner_counts')
            ->toContain('company_count')
            ->toContain('primary_count')
            ->not->toContain('all_links')
            ->not->toContain('primary_links');
    }
});
