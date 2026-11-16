<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('uses a branded confirmation dialog instead of browser confirm for linking or importing a company', function (): void {
    $view = file_get_contents(resource_path('views/partials/hubspot-company-linker.blade.php'));

    expect($view)
        ->toContain('lf43-confirm-backdrop')
        ->toContain('role="alertdialog"')
        ->toContain('pendingCompanyId')
        ->toContain('$wire.linkHubSpotCompany(pendingCompanyId)')
        ->toContain('$wire.importAndLinkHubSpotCompany()')
        ->toContain('Confirmar vínculo')
        ->not->toContain('wire:confirm=');
});

it('offers fiscal identification directly on an unlinked company of a global HubSpot stage', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '122191633',
        'stage_label' => 'Reunião Agendada',
        'display_order' => 3,
    ]);

    $crmCompany = HubSpotCompany::query()->create([
        'hubspot_id' => 'v43-tropical-id',
        'company_id' => null,
        'name' => 'TROPICAL AGRICOLA LTDA',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v43-deal-tropical',
        'name' => 'Proposta Tropical V43',
        'pipeline_id' => 'default',
        'stage_id' => '122191633',
        'stage_label' => 'Reunião Agendada',
        'is_closed' => false,
    ]);
    $crmCompany->deals()->attach($deal->id, ['is_primary' => true]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $index = collect($page->get('globalHubSpotStageOptions'))
        ->pluck('label')->search('Reunião Agendada');
    expect($index)->not->toBeFalse();

    $page->call('applyGlobalHubSpotStageView', (int) $index)
        ->assertSee('Proposta Tropical V43')
        ->assertSee('TROPICAL AGRICOLA LTDA')
        ->assertSee('lf43-stage-identity-actions', false)
        ->assertSee('Identificar empresa')
        ->call('openCompanyLink', $crmCompany->id)
        ->assertSet('linkingHubSpotCompanyId', $crmCompany->id)
        ->assertSee('Registro atual do HubSpot')
        ->assertSee('Confirmar vínculo');
});

it('never exposes global stage or identification controls to a seller', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)->test('pages::leads.index')
        ->assertDontSee('lf43-stage-identity-actions', false)
        ->call('openCompanyLink', 1)
        ->assertForbidden();
});

it('classifies a task due at 17h as today at 8h and as overdue at 17h10', function (): void {
    Carbon::setTestNow('2026-10-23 08:00:00');

    try {
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

        $company = Company::query()->create([
            'cnpj_root' => '98430001',
            'corporate_name' => 'V43 EMPRESA COM TAREFA AS 17H',
        ]);
        $company->sdrScore()->create([
            'score' => 73,
            'priority' => 'high',
            'label' => 'Alta',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'v43',
            'metadata' => [],
            'calculated_at' => now(),
        ]);
        $crm = HubSpotCompany::query()->create([
            'hubspot_id' => 'v43-due-company',
            'company_id' => $company->id,
            'match_source' => 'manual_verified',
        ]);
        $task = HubSpotTask::query()->create([
            'hubspot_id' => 'v43-due-task',
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'assigned_to' => $manager->name,
            'due_at' => '2026-10-23 17:00:00',
            'completed_at' => null,
        ]);
        $crm->tasks()->attach($task->id);

        $morning = Livewire::actingAs($manager)->test('pages::leads.index');
        expect($morning->get('v242DueCounts')['overdue'])->toBe(0)
            ->and($morning->get('v242DueCounts')['today'])->toBe(1);
        $morning->call('applyFigmaFollowUp', 'today')
            ->assertSee('V43 EMPRESA COM TAREFA AS 17H');
        $morning->call('applyFigmaFollowUp', 'overdue')
            ->assertDontSee('V43 EMPRESA COM TAREFA AS 17H');

        Carbon::setTestNow('2026-10-23 17:10:00');
        $evening = Livewire::actingAs($manager)->test('pages::leads.index');
        expect($evening->get('v242DueCounts')['overdue'])->toBe(1)
            ->and($evening->get('v242DueCounts')['today'])->toBe(0);
        $evening->call('applyFigmaFollowUp', 'overdue')
            ->assertSee('V43 EMPRESA COM TAREFA AS 17H');
        $evening->call('applyFigmaFollowUp', 'today')
            ->assertDontSee('V43 EMPRESA COM TAREFA AS 17H');
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps the empty fiscal list elegant while stage results stay visible', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('rf-empty-state lf43-empty-state')
        ->toContain('lf43-empty-visual')
        ->toContain('lf43-empty-reset')
        ->toContain('Identifique o CNPJ na lista de negócios do HubSpot acima')
        ->and($css)->toContain('EC_PROSPECTOR_V43_START');
});
