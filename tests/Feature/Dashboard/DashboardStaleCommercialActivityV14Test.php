<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Services\DashboardLeadMetricsV23Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function v14CompanyWithCommercialPriority(string $root): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => 'Atividade Comercial V14 '.$root,
    ]);

    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'metadata' => [],
        'version' => 'v14',
        'calculated_at' => now(),
    ]);

    return $company;
}

it('does not treat a remote cadastral edit as recent commercial contact', function (): void {
    $company = v14CompanyWithCommercialPriority('90140001');

    $company->hubSpotLead()->create([
        'hubspot_company_id' => 'v14-company-old',
        'work_status' => 'contacting',
        'last_activity_at' => now()->subDays(120),
    ]);

    HubSpotCompany::query()->create([
        'hubspot_id' => 'v14-company-old',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
        'last_activity_at' => now()->subDays(120),
        'hubspot_updated_at' => now()->subDay(),
    ]);

    $stale = app(DashboardLeadMetricsV23Service::class)
        ->staleCompanyIds();

    expect($stale)->toContain($company->id);
});

it('recognizes recent HubSpot activity even with an old local snapshot', function (): void {
    $company = v14CompanyWithCommercialPriority('90140002');

    $company->hubSpotLead()->create([
        'hubspot_company_id' => 'v14-company-fresh',
        'last_activity_at' => now()->subDays(115),
    ]);

    HubSpotCompany::query()->create([
        'hubspot_id' => 'v14-company-fresh',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
        'last_activity_at' => now()->subDays(3),
        'hubspot_updated_at' => now()->subDays(110),
    ]);

    expect(
        app(DashboardLeadMetricsV23Service::class)
            ->staleCompanyIds()
    )->not->toContain($company->id);
});

it('keeps a company with only a remote update as unknown', function (): void {
    $company = v14CompanyWithCommercialPriority('90140003');

    HubSpotCompany::query()->create([
        'hubspot_id' => 'v14-company-unknown',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
        'last_activity_at' => null,
        'hubspot_updated_at' => now()->subDays(130),
    ]);

    expect(
        app(DashboardLeadMetricsV23Service::class)
            ->staleCompanyIds()
    )->not->toContain($company->id);
});

it('uses a recent CRM contact to prevent a false inactivity alert', function (): void {
    $company = v14CompanyWithCommercialPriority('90140004');

    $company->hubSpotLead()->create([
        'last_activity_at' => now()->subDays(130),
    ]);

    $company->crmCheck()->create([
        'provider' => 'hubspot',
        'status' => 'known',
        'last_contacted_at' => now()->subDays(5),
        'checked_at' => now(),
    ]);

    expect(
        app(DashboardLeadMetricsV23Service::class)
            ->staleCompanyIds()
    )->not->toContain($company->id);
});
