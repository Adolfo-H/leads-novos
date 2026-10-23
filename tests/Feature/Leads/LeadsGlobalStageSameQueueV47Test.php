<?php

use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use Livewire\Livewire;

it('uses the same seven columns and row structure in global HubSpot stages as the normal Leads queue', function (): void {
    $source = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($source)
        ->toContain('EC_PROSPECTOR_V47')
        ->toContain('lv13-list-head lf47-unified-head')
        ->toContain('rf-lead-row lf45-stage-row lf47-unified-row')
        ->toContain('lv13-lead-line')
        ->toContain('lv13-company')
        ->toContain('lv13-score')
        ->toContain('lv13-status')
        ->toContain('lv13-stage')
        ->toContain('lv13-next')
        ->toContain('lv13-owner')
        ->toContain('lv23-actions')
        ->toContain('rf-pagination lf45-stage-pagination lf46-stage-pagination')
        ->not->toContain('lf45-stage-table-head')
        ->not->toContain('lf47-filter-active')
        ->and($view)->toContain("@include('partials.leads-global-stage-list-v45')")
        ->toContain('lf47-active-stage-chip')
        ->toContain('wire:click="clearGlobalHubSpotStageView"')
        ->and($css)->toContain('EC_PROSPECTOR_V47_START');
});

it('filters the original queue instead of adding a second separate HubSpot results panel', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '947000001',
        'stage_label' => 'Etapa Global V47',
        'display_order' => 4,
    ]);
    HubSpotDeal::query()->create([
        'hubspot_id' => '947000011',
        'name' => 'EMPRESA SEM CNPJ V47',
        'pipeline_id' => 'default',
        'stage_id' => '947000001',
        'stage_label' => 'Etapa Global V47',
        'is_closed' => false,
    ]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $index = collect($page->get('globalHubSpotStageOptions'))
        ->pluck('label')->search('Etapa Global V47');

    expect($index)->not->toBeFalse();
    $page->call('applyGlobalHubSpotStageView', (int) $index)
        ->assertSee('Empresas para trabalhar')
        ->assertSee('EMPRESA SEM CNPJ V47')
        ->assertSee('lv13-list-head lf47-unified-head', false)
        ->assertSee('Negócios do HubSpot · Etapa Global V47')
        ->assertDontSee('lf41-global-results', false)
        ->assertSee('Limpar etapa');
});

it('keeps the standard selection state and the one-row-per-deal export path', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $partial = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    $stage = file_get_contents(resource_path('views/partials/leads-crm-stage-strip.blade.php'));

    expect($view)->toContain("entangle('selectedGlobalDealIds').live")
        ->toContain('selectedExportIdsV45')
        ->toContain('LeadGlobalStageExcelV45Service')
        ->and($partial)->toContain('x-model="stageSelectedIds"')
        ->toContain('stageSelectedIds = $event.target.checked')
        ->toContain('[...new Set([...stageSelectedIds, ...ids])]')
        ->toContain('stageSelectedIds.filter(id => !ids.includes(id))')
        ->and($stage)->toContain('Pipeline HubSpot');
});
