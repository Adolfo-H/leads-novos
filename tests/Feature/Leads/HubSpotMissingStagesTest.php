<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use Livewire\Livewire;

it('shows the three required HubSpot stages even without associated deals', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $page = Livewire::actingAs($manager)->test('pages::leads.index');

    $global = collect($page->get('globalHubSpotStageOptions'))->pluck('count', 'label');
    $mine = collect($page->get('crmStageOptions'))->pluck('count', 'label');

    foreach (['Reunião Agendada', 'Aceite da proposta', 'Contrato Assinado'] as $label) {
        expect($global->has($label))->toBeTrue();
        expect($mine->has($label))->toBeTrue();
        expect((int) $global->get($label))->toBe(0);
        expect((int) $mine->get($label))->toBe(0);
    }

    $page->assertSee('Reunião Agendada')
        ->assertSee('Aceite da proposta')
        ->assertSee('Contrato Assinado');
});

it('includes configured HubSpot pipeline stages without inventing deals', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline comercial v38',
        'stage_id' => 'v38-zero-stage',
        'stage_label' => 'Reunião Cancelada',
        'display_order' => 50,
    ]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $global = collect($page->get('globalHubSpotStageOptions'))->pluck('count', 'label');
    $mine = collect($page->get('crmStageOptions'))->pluck('count', 'label');
    expect((int) $global->get('Reunião Cancelada'))->toBe(0);
    expect((int) $mine->get('Reunião Cancelada'))->toBe(0);
});

it('does not expose global stages to sellers and keeps personal stage labels', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $page = Livewire::actingAs($seller)->test('pages::leads.index');

    expect($page->get('globalHubSpotStageOptions'))->toBe([]);
    $mine = collect($page->get('crmStageOptions'))->pluck('count', 'label');
    expect($mine->has('Reunião Agendada'))->toBeTrue();
    expect($mine->has('Aceite da proposta'))->toBeTrue();
    expect($mine->has('Contrato Assinado'))->toBeTrue();
    $page->assertDontSee('Visão geral');
});

it('removes the redundant show all button while preserving stage selection', function (): void {
    $partial = file_get_contents(resource_path('views/partials/leads-crm-stage-strip.blade.php'));
    expect($partial)->not->toContain('Ver todas as etapas HubSpot')
        ->not->toContain('allStages')
        ->toContain('applyCrmStageView')
        ->toContain('rf-stage-strip-chip')
        ->toContain('lv18-clear-stage');
});

it('counts real deals in a previously missing stage without duplicating businesses', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $company = Company::query()->create([
        'cnpj_root' => '87210038',
        'corporate_name' => 'Empresa de Teste V38',
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $manager->id]);
    $hubSpotCompany = HubSpotCompany::query()->create([
        'company_id' => $company->id,
        'hubspot_id' => 'v38-hubspot-company',
        'name' => 'Empresa de Teste V38',
        'match_source' => 'manual_verified',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v38-meeting-deal',
        'name' => 'Negócio de Reunião Agendada',
        'stage_id' => '122191633',
        'stage_label' => 'Reunião Agendada',
        'is_closed' => false,
    ]);
    $deal->companies()->attach($hubSpotCompany->id, ['is_primary' => true]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $global = collect($page->get('globalHubSpotStageOptions'))->pluck('count', 'label');
    $mine = collect($page->get('crmStageOptions'))->pluck('count', 'label');
    expect((int) $global->get('Reunião Agendada'))->toBe(1);
    expect((int) $mine->get('Reunião Agendada'))->toBe(1);
    expect((int) $global->get('Contrato Assinado'))->toBe(0);
});
