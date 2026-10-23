<?php

use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use App\Services\DashboardOwnedDealsV23Service;
use Livewire\Livewire;

it('allows a manager to click a global stage and find deals without a CNPJ link', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '122191633',
        'stage_label' => 'Reunião Agendada',
        'display_order' => 3,
    ]);

    // Um negocio real no espelho, inclusive com rotulo CSV antigo e sem Company.
    HubSpotDeal::query()->create([
        'hubspot_id' => 'v41-orphan-deal',
        'name' => 'Negocio V41 sem vinculo CNPJ',
        'pipeline_id' => 'default',
        'stage_id' => '122191633',
        'stage_label' => 'Etapa anterior importada',
        'is_closed' => false,
        'is_closed_won' => false,
    ]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $index = collect($page->get('globalHubSpotStageOptions'))->pluck('label')->search('Reunião Agendada');
    expect($index)->not->toBeFalse();
    $page->assertSee('Filtrar negócios do HubSpot na etapa Reunião Agendada')
        ->call('applyGlobalHubSpotStageView', (int) $index)
        ->assertSet('globalHubSpotStage', 'Reunião Agendada')
        ->assertSet('crm', 'stage:Reunião Agendada')
        ->assertSee('Negocio V41 sem vinculo CNPJ')
        ->assertSee('Negócios do HubSpot · Reunião Agendada')
        ->call('clearGlobalHubSpotStageView')
        ->assertSet('globalHubSpotStage', '')
        ->assertSet('crm', '');
});

it('keeps the global stage action restricted to managers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)->test('pages::leads.index')
        ->assertDontSee('Visão geral')
        ->call('applyGlobalHubSpotStageView', 0)
        ->assertForbidden();
});

it('hides the retired test stage without deleting actual HubSpot deals', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill([
        'commercial_role' => User::ROLE_MANAGER,
        'hubspot_owner_id' => 'v41-remote-owner',
    ])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '14249607',
        'stage_label' => 'Inicio do teste',
        'display_order' => 9,
    ]);

    HubSpotDeal::query()->create([
        'hubspot_id' => 'v41-trial-deal',
        'name' => 'Negocio historico de teste',
        'pipeline_id' => 'default',
        'stage_id' => '14249607',
        'stage_label' => 'Inicio do teste',
        'raw_properties' => ['hubspot_owner_id' => 'v41-remote-owner'],
        'is_closed' => false,
        'is_closed_won' => false,
    ]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    expect(collect($page->get('globalHubSpotStageOptions'))->pluck('label')->all())
        ->not->toContain('Inicio do teste');
    expect(collect($page->get('crmStageOptions'))->pluck('label')->all())
        ->not->toContain('Inicio do teste');

    $summary = app(DashboardOwnedDealsV23Service::class)->forUser($manager->id);
    expect(collect($summary['stages'])->pluck('label')->all())
        ->not->toContain('Inicio do teste');
    expect(HubSpotDeal::query()->where('hubspot_id', 'v41-trial-deal')->exists())
        ->toBeTrue();
});

it('restores a five stage dashboard with an expanding list and no scroll container', function (): void {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));
    $global = file_get_contents(resource_path('views/partials/leads-figma-stages-v36.blade.php'));
    expect($dashboard)
        ->toContain('array_slice($ds25Stages, 0, 5)')
        ->toContain('array_slice($ds25Stages, 5)')
        ->toContain('array_slice($ef29MyDeals[\'stages\'], 0, 5)')
        ->toContain('ef29MyExtraStages')
        ->toContain('ef29-extra-stages');
    expect($css)
        ->toContain('EC_PROSPECTOR_V41_START')
        ->toContain('overflow-y: visible !important;');
    expect($global)
        ->toContain('applyGlobalHubSpotStageView')
        ->toContain('EC_PROSPECTOR_V45_GLOBAL_STAGE_MOVED');
    // V47_1_LEGACY_TEST_UPDATED: V47 manteve a listagem global dentro da fila
    // e moveu o botão Limpar etapa para o cabeçalho usual de Leads.
    // A verificação do Livewire acima já confirma que limpar restaura o filtro.
    $integrated = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    $leadsView = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    expect($integrated)
        ->toContain('globalHubSpotStageDeals')
        ->toContain('lf45-stage-row')
        ->toContain('lv13-list-head lf47-unified-head')
        ->not->toContain('lf41-global-results');
    expect($leadsView)
        ->toContain('lf47-active-stage-chip')
        ->toContain('wire:click="clearGlobalHubSpotStageView"');
});
