<?php

use App\Models\Company;
use App\Models\User;
use App\Services\CommercialManagementMetricsService;

it('counts an active HubSpot deal even when the SDR score is absent or ineligible', function (): void {
    $seller = User::factory()->create([
        'name' => 'Vendedor Gestao V13',
        'email_verified_at' => now(),
    ]);

    // Negocio ativo no CRM sem score SDR.
    $withoutScore = Company::query()->create([
        'cnpj_root' => '80123451',
        'corporate_name' => 'Empresa HubSpot sem score V13',
    ]);

    $withoutScore->hubSpotLead()->create([
        'hubspot_company_id' => 'v13-company-1',
        'hubspot_deal_id' => 'v13-deal-1',
        'work_status' => 'contacting',
    ]);

    $withoutScore->leadWorkState()->create([
        'assigned_user_id' => $seller->id,
        'status' => 'contacting',
    ]);

    // Negocio existente com score inelegivel.
    $withIneligibleScore = Company::query()->create([
        'cnpj_root' => '80123452',
        'corporate_name' => 'Empresa com negocio e score inelegivel V13',
    ]);

    $withIneligibleScore->sdrScore()->create([
        'score' => 20,
        'priority' => 'low',
        'label' => 'Baixa prioridade',
        'is_eligible' => false,
        'factors' => [],
        'version' => 'v13',
        'metadata' => [],
    ]);

    $withIneligibleScore->hubSpotLead()->create([
        'hubspot_company_id' => 'v13-company-2',
        'hubspot_deal_id' => 'v13-deal-2',
        'work_status' => 'new',
    ]);

    // Empresa sem score e sem negocio:
    // nao deve entrar nos indicadores.
    Company::query()->create([
        'cnpj_root' => '80123453',
        'corporate_name' => 'Empresa sem CRM e score V13',
    ]);

    $service = app(
        CommercialManagementMetricsService::class
    );

    $summary = $service->summary();

    $sellers = collect(
        $service->sellers()
    )->keyBy('user_id');

    expect($summary['active_total'])->toBe(2)
        ->and($summary['opportunities_total'])->toBe(2)
        ->and($summary['assigned_total'])->toBe(1)
        ->and($summary['unassigned_total'])->toBe(1)
        ->and($summary['contacting_total'])->toBe(1)
        ->and($summary['new_total'])->toBe(1)
        ->and($sellers->has($seller->id))->toBeTrue()
        ->and($sellers->get($seller->id)['active_total'])->toBe(1)
        ->and($sellers->get($seller->id)['opportunities_total'])->toBe(1);
});
