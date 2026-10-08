<?php

use App\Livewire\DashboardOverview;
use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use App\Services\DashboardMyDealsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('counts actual HubSpot deal stages of the signed-in owner without leaking team deals', function () {
    $mine = User::factory()->create(['email_verified_at' => now()]);
    $mine->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $other = User::factory()->create(['email_verified_at' => now()]);

    $company = Company::query()->create([
        'cnpj_root' => '99550001',
        'corporate_name' => 'Minha Empresa V6',
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $mine->id]);

    $hubCompany = HubSpotCompany::query()->create([
        'hubspot_id' => 'v6-hub-company',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    foreach ([
        ['id' => 'v6-deal-1', 'stage' => 'Lead qualificado'],
        ['id' => 'v6-deal-2', 'stage' => 'Oportunidade'],
    ] as $item) {
        $deal = HubSpotDeal::query()->create([
            'hubspot_id' => $item['id'],
            'stage_label' => $item['stage'],
        ]);
        $deal->companies()->attach($hubCompany->id, ['is_primary' => true]);
    }

    // Snapshot do mesmo Deal não deve duplicá-lo.
    CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_deal_id' => 'v6-deal-1',
        'deal_stage_id' => 'qualified',
        'work_status' => 'contacting',
    ]);

    $otherCompany = Company::query()->create([
        'cnpj_root' => '99550002',
        'corporate_name' => 'Outra Carteira V6',
    ]);
    $otherCompany->leadWorkState()->create(['assigned_user_id' => $other->id]);
    CompanyHubSpotLead::query()->create([
        'company_id' => $otherCompany->id,
        'hubspot_deal_id' => 'v6-deal-other',
        'deal_stage_id' => 'cold',
        'work_status' => 'future',
    ]);

    $metrics = app(DashboardMyDealsService::class)->forUser((int) $mine->id);

    expect($metrics['total'])->toBe(2)
        ->and(collect($metrics['stages'])->pluck('label')->all())->toContain('Lead qualificado', 'Oportunidade')
        ->and(collect($metrics['stages'])->pluck('label')->all())->not->toContain('Lead frio');

    Livewire::actingAs($mine)
        ->test(DashboardOverview::class)
        ->assertSee('Meus negócios por etapa')
        ->assertSee('Lead qualificado')
        ->assertSee('Oportunidade')
        ->assertDontSee('Lead frio');
});

it('uses actual pipeline stage labels when a deal exists only in the operational snapshot', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'v6-pipeline',
        'pipeline_label' => 'Comercial V6',
        'stage_id' => 'v6-discarded',
        'stage_label' => 'Descartado',
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '99550003',
        'corporate_name' => 'Lead com Etapa V6',
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $user->id]);
    CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_deal_id' => 'v6-deal-snapshot',
        'pipeline_id' => 'v6-pipeline',
        'deal_stage_id' => 'v6-discarded',
        'work_status' => 'discarded',
    ]);

    $metrics = app(DashboardMyDealsService::class)->forUser((int) $user->id);

    expect($metrics['total'])->toBe(1)
        ->and($metrics['stages'][0]['label'])->toBe('Descartado')
        ->and($metrics['stages'][0]['value'])->toBe(1);
});

it('preserves seller restrictions and displays the small brand-world panel in the sidebar', function () {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertDontSee('Meus negócios por etapa')
        ->assertDontSee('Top oportunidades por CNAE')
        ->assertSee('Minha fila de leads');

    $this->actingAs($seller)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('ec-sidebar-world', false);
});
