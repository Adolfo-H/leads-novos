<?php

use App\Models\Company;
use App\Services\HubSpotLeadEligibilityService;

function createHubSpotEligibleCompany(
    string $root
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => $root,
            'corporate_name' => 'Empresa HubSpot Teste '.$root,
        ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 60,
            'priority' => 'medium',
            'label' => 'Prioridade média',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
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

    return $company;
}

it('allows retry after a failed HubSpot synchronization', function () {
    config([
        'services.hubspot.lead_min_score' => 60,
    ]);

    $company =
        createHubSpotEligibleCompany(
            '77112233'
        );

    $company
        ->hubSpotLead()
        ->create([
            'pipeline_id' => 'default',
            'deal_stage_id' => 'appointmentscheduled',
            'sync_error' => 'HTTP 403',
            'metadata' => [],
        ]);

    $company->unsetRelation(
        'hubSpotLead'
    );

    $result =
        app(
            HubSpotLeadEligibilityService::class
        )->evaluate(
            $company
        );

    expect(
        $result['eligible']
    )->toBeTrue();
});

it('blocks a lead after synchronization was completed', function () {
    config([
        'services.hubspot.lead_min_score' => 60,
    ]);

    $company =
        createHubSpotEligibleCompany(
            '88445566'
        );

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => '1001',
            'hubspot_deal_id' => '2001',
            'pipeline_id' => 'default',
            'deal_stage_id' => 'appointmentscheduled',
            'synced_at' => now(),
            'metadata' => [],
        ]);

    $company->unsetRelation(
        'hubSpotLead'
    );

    $result =
        app(
            HubSpotLeadEligibilityService::class
        )->evaluate(
            $company
        );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'Empresa já sincronizada com o HubSpot.'
    );
});
