<?php

use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\HubSpotTask;
use App\Models\User;
use App\Services\DashboardHubSpotDueTasksV40Service;
use App\Services\DashboardOwnedDealsV23Service;
use Livewire\Livewire;

it('shows real stage IDs even if the deal mirror still has an old textual label', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'hubspot_owner_id' => 'hubspot-owner-v40',
    ]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '122191633',
        'stage_label' => 'Reunião Agendada',
        'display_order' => 3,
    ]);
    HubSpotDeal::query()->create([
        'hubspot_id' => 'v40-deal-one',
        'name' => 'Negócio de teste',
        'pipeline_id' => 'default',
        'stage_id' => '122191633',
        'stage_label' => 'Rótulo antigo',
        'raw_properties' => ['hubspot_owner_id' => 'hubspot-owner-v40'],
        'is_closed' => false,
    ]);

    $result = app(DashboardOwnedDealsV23Service::class)->forUser((int) $user->id);
    $byStage = collect($result['stages'])->pluck('value', 'label');
    expect($result['total'])->toBe(1)
        ->and((int) $byStage->get('Reunião Agendada'))->toBe(1)
        ->and((int) $byStage->get('Aceite da proposta'))->toBe(0)
        ->and((int) $byStage->get('Contrato Assinado'))->toBe(0)
        ->and($byStage->has('Rótulo antigo'))->toBeFalse();

    $page = Livewire::actingAs($user)->test('pages::leads.index');
    $global = collect($page->get('globalHubSpotStageOptions'))->pluck('count', 'label');
    expect((int) $global->get('Reunião Agendada'))->toBe(1);
});

it('counts only the signed-in owners real open overdue and today tasks', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'hubspot_owner_id' => 'user-v40-owner',
    ]);
    $create = static function (string $id, string $status, bool $open, mixed $due, string $remoteOwner, string $assignedTo): void {
        HubSpotTask::query()->create([
            'hubspot_id' => $id,
            'status' => $status,
            'title' => 'Tarefa de teste',
            'is_open' => $open,
            'due_at' => $due,
            'assigned_to' => $assignedTo,
            'raw_properties' => ['hubspot_owner_id' => $remoteOwner],
        ]);
    };
    $create('v40-overdue', 'NOT_STARTED', true, now()->subDay(), 'user-v40-owner', $user->name);
    $create('v40-today', 'WAITING', true, today()->addDay()->subMinute(), 'user-v40-owner', $user->name);
    $create('v40-completed', 'COMPLETED', false, now()->subDay(), 'user-v40-owner', $user->name);
    $create('v40-future', 'NOT_STARTED', true, today()->addDay()->addHour(), 'user-v40-owner', $user->name);
    $create('v40-other-user', 'NOT_STARTED', true, now()->subDay(), 'another-owner', $user->name);

    $counts = app(DashboardHubSpotDueTasksV40Service::class)->forUser((int) $user->id);
    expect($counts)->toBe(['total' => 2, 'overdue' => 1, 'today' => 1]);
    // Leads utiliza o MESMO escopo de atribuição: não mistura nomes com IDs conflitantes.
    expect(HubSpotTask::query()->dueInPeriod(today()->addDay(), null, [
        'user-v40-owner', $user->name,
    ])->count())->toBe(2);
});

it('keeps all HubSpot stages accessible through expansion and counts only actual due tasks', function (): void {
    $view = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $leads = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    // V41: cinco etapas no resumo, restantes acessíveis ao expandir.
    // Não exigir todas abertas por padrão: isso era a regra da V40.
    expect($view)
        ->toContain('array_slice($ds25Stages, 0, 5)')
        ->toContain('array_slice($ds25Stages, 5)')
        ->toContain("array_slice(\$ef29MyDeals['stages'], 0, 5)")
        ->toContain("array_slice(\$ef29MyDeals['stages'], 5)")
        ->toContain('@if ($ef29ExtraStages !== [])')
        ->toContain('@if ($ef29MyExtraStages !== [])')
        ->toContain('ef29-extra-stages')
        ->toContain('DashboardHubSpotDueTasksV40Service')
        ->toContain("['dueActionOnly' => 1]")
        ->not->toContain('$commercial[\'waiting_total\']');

    expect($leads)->toContain('EC_PROSPECTOR_V40_LEADS');
    expect($css)->toContain('EC_PROSPECTOR_V41_START');
});
