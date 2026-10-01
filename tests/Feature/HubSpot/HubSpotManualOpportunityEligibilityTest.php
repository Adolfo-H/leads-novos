<?php

use App\Models\Company;
use App\Services\HubSpotLeadEligibilityService;

it('allows manual HubSpot creation even when SDR would not allow automation', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '81818181',

            'corporate_name' => 'Empresa Manual Sem SDR',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'not_found',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $result =
        app(
            HubSpotLeadEligibilityService::class
        )->evaluateManual(
            $company
        );

    expect(
        $result['eligible']
    )->toBeTrue();
});

it('blocks manual creation when the company already exists in HubSpot', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '82828282',

            'corporate_name' => 'Empresa Já Existente CRM',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'opportunity',

            'contacted_count' => 1,

            'associated_deals_count' => 1,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $result =
        app(
            HubSpotLeadEligibilityService::class
        )->evaluateManual(
            $company
        );

    expect(
        $result['eligible']
    )->toBeFalse();
});

it('allows a partial manual synchronization to be resumed', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '83838383',

            'corporate_name' => 'Empresa Sincronização Parcial',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'opportunity',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => '123456',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'synced_at' => null,

            'metadata' => [],
        ]);

    $result =
        app(
            HubSpotLeadEligibilityService::class
        )->evaluateManual(
            $company
        );

    expect(
        $result['eligible']
    )->toBeTrue();
});

it('blocks a manual synchronization already completed', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '84848484',

            'corporate_name' => 'Empresa Já Sincronizada',
        ]);

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => '123456',

            'hubspot_deal_id' => '654321',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'synced_at' => now(),

            'metadata' => [],
        ]);

    $result =
        app(
            HubSpotLeadEligibilityService::class
        )->evaluateManual(
            $company
        );

    expect(
        $result['eligible']
    )->toBeFalse();
});
