<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Models\User;
use App\Services\LeadCrmSituationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v17User(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array{Company,HubSpotTask} */
function v17CompanyWithTask(User $user, string $root, string $status, bool $open): array
{
    $company = Company::query()->create([
        'cnpj_root' => $root, 'corporate_name' => 'V17 Empresa '.$root, 'source' => 'test',
    ]);
    $company->sdrScore()->create([
        'score' => 75, 'priority' => 'high', 'label' => 'Alta',
        'is_eligible' => true, 'is_provisional' => false, 'factors' => [],
        'version' => 'v17', 'metadata' => [], 'calculated_at' => now(),
    ]);
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v17-company-'.$root,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);
    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'v17-task-'.$root,
        'status' => $status,
        'is_open' => $open,
        'assigned_to' => $user->name,
        'due_at' => today()->setTime(10, 0),
        'completed_at' => null,
    ]);
    $hub->tasks()->attach($task->id);

    return [$company, $task];
}

it('separates a task due at 10 from overdue precisely at its hour', function () {
    Carbon::setTestNow('2026-10-08 09:00:00');
    try {
        $me = v17User();
        v17CompanyWithTask($me, '98003001', 'NOT_STARTED', true);
        $service = app(LeadCrmSituationService::class);

        $atNine = Livewire::actingAs($me)->test('pages::leads.index');
        expect($atNine->get('v15DueCount'))->toBe(1)
            ->and($atNine->get('v17DueBreakdown')['overdue'])->toBe(0)
            ->and($atNine->get('v17DueBreakdown')['today'])->toBe(1);

        Carbon::setTestNow('2026-10-08 11:00:00');
        $atEleven = Livewire::actingAs($me)->test('pages::leads.index');
        expect($atEleven->get('v15DueCount'))->toBe(1)
            ->and($atEleven->get('v17DueBreakdown')['overdue'])->toBe(1)
            ->and($atEleven->get('v17DueBreakdown')['today'])->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('never counts a completed textual status or another owners tasks', function () {
    Carbon::setTestNow('2026-10-08 11:00:00');
    try {
        $me = v17User();
        [$closedCompany] = v17CompanyWithTask($me, '98003002', 'COMPLETED', true);
        [$openCompany, $openTask] = v17CompanyWithTask($me, '98003003', 'NOT_STARTED', true);
        $other = v17User();
        [$otherCompany, $otherTask] = v17CompanyWithTask($other, '98003004', 'NOT_STARTED', true);

        $page = Livewire::actingAs($me)->test('pages::leads.index');
        expect($page->get('v15DueCount'))->toBe(1);
        $page->call('applyV15TopCard', 'due')
            ->assertSee($openCompany->corporate_name)
            ->assertDontSee($closedCompany->corporate_name)
            ->assertDontSee($otherCompany->corporate_name);
        $openTask->update(['is_open' => false, 'completed_at' => now()]);
        $refreshed = Livewire::actingAs($me)->test('pages::leads.index');
        expect($refreshed->get('v15DueCount'))->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps the actual stages selectable with a constant clear slot and small action buttons', function () {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $pipeline = file_get_contents(resource_path('views/partials/leads-crm-stage-strip.blade.php'));
    $css = file_get_contents(resource_path('css/leads-v16.css'));

    expect($view)->toContain('lv23-actions')
        ->toContain('Dossiê ↗')
        ->toContain('HubSpot ↗')
        ->toContain('v17DueBreakdown')
        ->toContain('wire:click="exportExcel"')
        ->toContain('assignOwner(')
        ->and($pipeline)->toContain('lv17-stage-clear')
        ->toContain('lv17-stage-clear-slot')
        ->toContain('applyCrmStageView')
        ->and($css)->toContain('LEADS_V17_CORRECTIONS')
        ->toContain('min-width:77px!important');
});
