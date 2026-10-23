<?php

use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use Livewire\Livewire;

it('replaces the dark English Laravel pagination with compact Portuguese controls only in global HubSpot stages', function (): void {
    $partial = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));
    $pipeline = file_get_contents(resource_path('views/partials/leads-crm-stage-strip.blade.php'));

    expect($partial)
        ->toContain('EC_PROSPECTOR_V46')
        ->toContain('Exibindo')
        ->toContain('de <strong>')
        ->toContain('negócios')
        ->toContain('Página anterior')
        ->toContain('Próxima página')
        ->toContain("gotoPage({{ \$lf46Page }}, 'globalStageDealsPage')")
        ->toContain("nextPage('globalStageDealsPage')")
        ->not->toContain('$lf45Deals->links()');

    expect($css)->toContain('EC_PROSPECTOR_V46_START')
        ->toContain('.lf45-stage-inline .lf46-stage-pagination');

    expect($pipeline)->toContain('Pipeline HubSpot');
});

it('selecting all on one global-stage page preserves other pages and does not affect personal pipeline', function (): void {
    $partial = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    $leads = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));

    expect($partial)
        ->toContain('stageSelectedIds = $event.target.checked')
        ->toContain('[...new Set([...stageSelectedIds, ...ids])]')
        ->toContain('stageSelectedIds.filter(id => !ids.includes(id))')
        ->toContain('stageSelectedIds.includes(id)')
        ->toContain('x-model="stageSelectedIds"');

    expect($leads)->toContain("entangle('selectedGlobalDealIds').live")
        ->toContain('selectedExportIdsV45');
});

it('paginates stage businesses without opening another HubSpot panel or changing portfolios', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '94600001',
        'stage_label' => 'Etapa UI V46',
        'display_order' => 2,
    ]);
    for ($i = 1; $i <= 17; $i++) {
        HubSpotDeal::query()->create([
            'hubspot_id' => '94600'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'name' => 'NEGOCIO GLOBAL V46 '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'pipeline_id' => 'default',
            'stage_id' => '94600001',
            'stage_label' => 'Etapa UI V46',
            'is_closed' => false,
        ]);
    }

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $index = collect($page->get('globalHubSpotStageOptions'))
        ->pluck('label')->search('Etapa UI V46');
    expect($index)->not->toBeFalse();

    $page->call('applyGlobalHubSpotStageView', (int) $index)
        ->assertSee('NEGOCIO GLOBAL V46 17')
        ->assertDontSee('NEGOCIO GLOBAL V46 01')
        ->assertSee('Paginação dos negócios desta etapa')
        ->assertSee('Empresas para trabalhar')
        ->assertDontSee('lf41-global-results', false)
        ->call('gotoPage', 2, 'globalStageDealsPage')
        ->assertSee('NEGOCIO GLOBAL V46 01')
        ->assertDontSee('NEGOCIO GLOBAL V46 17');
});
