<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use App\Services\DashboardOwnedDealsV23Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not bring related companies of another HubSpot owner into the seller snapshot', function (): void {
    $seller = User::factory()->create([
        'name' => 'Vendedor Seletivo',
        'hubspot_owner_id' => 'owner-v173-seller',
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '94173001',
        'corporate_name' => 'Cliente Seletivo V173',
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $seller->id]);

    $mineLinked = HubSpotCompany::query()->create([
        'hubspot_id' => 'v173-mine-company',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);
    $foreignLinked = HubSpotCompany::query()->create([
        'hubspot_id' => 'v173-foreign-company',
    ]);

    $mine = HubSpotDeal::query()->create([
        'hubspot_id' => 'v173-mine',
        'owner_name' => 'Outro Nome',
        'stage_label' => 'Minha etapa V173',
        'raw_properties' => ['hubspot_owner_id' => 'owner-v173-seller'],
    ]);
    $mine->companies()->attach($mineLinked->id, ['is_primary' => true]);

    $foreign = HubSpotDeal::query()->create([
        'hubspot_id' => 'v173-foreign',
        'owner_name' => 'Vendedor Seletivo',
        'stage_label' => 'Etapa de outro',
        'raw_properties' => ['hubspot_owner_id' => 'owner-v173-other'],
    ]);
    $foreign->companies()->attach($foreignLinked->id, ['is_primary' => true]);

    // O snapshot local não pode reassumir um Deal que o mirror
    // já sabe pertencer a outro owner, mesmo na carteira do vendedor.
    CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_deal_id' => 'v173-foreign',
        'work_status' => 'contacting',
    ]);

    $loadedCompanies = [];
    HubSpotCompany::retrieved(static function (HubSpotCompany $linked) use (&$loadedCompanies): void {
        $loadedCompanies[] = (string) $linked->hubspot_id;
    });

    $service = app(DashboardOwnedDealsV23Service::class);
    $result = $service->forUser((int) $seller->id);

    expect($result['total'])->toBe(1)
        ->and($result['stages'][0]['label'])->toBe('Minha etapa V173')
        ->and($loadedCompanies)->toContain('v173-mine-company')
        ->and($loadedCompanies)->not->toContain('v173-foreign-company')
        ->and($service->companyIdsForStage((int) $seller->id, 'Minha etapa V173'))
        ->toBe([$company->id]);
});

it('preserves name fallback when remote ID cannot be matched locally', function (): void {
    $seller = User::factory()->create([
        'name' => 'Vendedor V173 Nome',
        'hubspot_owner_id' => null,
    ]);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v173-name-only',
        'owner_name' => '  VENDEDOR V173 NOME  ',
        'stage_label' => 'Nome V173',
        'raw_properties' => ['hubspot_owner_id' => 'owner-without-local-mapping'],
    ]);

    expect(app(DashboardOwnedDealsV23Service::class)->forUser((int) $seller->id))
        ->toMatchArray(['total' => 1]);
});

it('preserves local assignment for ownerless mirrors and snapshots missing from the mirror', function (): void {
    $seller = User::factory()->create([
        'name' => 'Vendedor V173 Local',
        'hubspot_owner_id' => 'owner-v173-local',
    ]);

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'v173-pipeline',
        'pipeline_label' => 'Pipeline V173',
        'stage_id' => 'fallback',
        'stage_label' => 'Snapshot V173',
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '94173002',
        'corporate_name' => 'Empresa Local V173',
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $seller->id]);

    $linked = HubSpotCompany::query()->create([
        'hubspot_id' => 'v173-local-company',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    $mirror = HubSpotDeal::query()->create([
        'hubspot_id' => 'v173-local-mirror',
        'stage_label' => 'Mirror V173',
    ]);
    $mirror->companies()->attach($linked->id, ['is_primary' => true]);

    CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_deal_id' => 'v173-snapshot-missing',
        'pipeline_id' => 'v173-pipeline',
        'deal_stage_id' => 'fallback',
        'work_status' => 'contacting',
    ]);

    $service = app(DashboardOwnedDealsV23Service::class);
    $result = $service->forUser((int) $seller->id);

    expect($result['total'])->toBe(2)
        ->and(collect($result['stages'])->pluck('label')->all())
        ->toContain('Mirror V173', 'Snapshot V173');

    expect($service->companyIdsForStage((int) $seller->id, '__all__'))
        ->toBe([$company->id]);
});

it('keeps CRM-only company drilldown for an explicitly owned deal', function (): void {
    $seller = User::factory()->create([
        'hubspot_owner_id' => 'owner-v173-crm',
    ]);

    $crmCompany = HubSpotCompany::query()->create([
        'hubspot_id' => 'v173-crm-only-company',
    ]);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v173-crm-only-deal',
        'stage_label' => 'CRM V173',
        'raw_properties' => ['hubspot_owner_id' => 'owner-v173-crm'],
    ]);
    $deal->companies()->attach($crmCompany->id, ['is_primary' => true]);

    $service = app(DashboardOwnedDealsV23Service::class);

    expect($service->forUser((int) $seller->id)['total'])->toBe(1)
        ->and($service->crmOnlyIdsForStage((int) $seller->id, 'CRM V173'))
        ->toBe([$crmCompany->id]);
});
