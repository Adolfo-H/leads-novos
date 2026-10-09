<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v242CompanyWithTasks(string $root, array $tasks): void
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => 'Empresa V242 '.$root,
    ]);

    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v242',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v242-company-'.$root,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    foreach ($tasks as $index => $taskData) {
        $task = HubSpotTask::query()->create([
            'hubspot_id' => 'v242-task-'.$root.'-'.$index,
            'status' => $taskData['status'] ?? 'NOT_STARTED',
            'is_open' => $taskData['open'] ?? true,
            'assigned_to' => $taskData['owner'],
            'due_at' => $taskData['due'],
            'completed_at' => $taskData['completed_at'] ?? null,
        ]);

        $hub->tasks()->attach($task->id);
    }
}

it('counts a company with overdue and due-today tasks only once in the total', function (): void {
    Carbon::setTestNow('2026-10-08 11:00:00');

    try {
        $manager = User::factory()->create([
            'name' => 'Gestor Contadores 242',
            'email_verified_at' => now(),
        ]);
        $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

        // Empresa 1: duas tarefas minhas, uma atrasada e outra para hoje.
        v242CompanyWithTasks('99424201', [
            ['owner' => $manager->name, 'due' => now()->subHours(2)],
            ['owner' => $manager->name, 'due' => now()->addHours(2)],
        ]);

        // Empresa 2: somente tarefa minha para hoje.
        v242CompanyWithTasks('99424202', [
            ['owner' => $manager->name, 'due' => now()->addHours(3)],
        ]);

        // Não contam: outro responsável, concluída e amanhã.
        v242CompanyWithTasks('99424203', [
            ['owner' => 'Outro vendedor 242', 'due' => now()->subHour()],
        ]);
        v242CompanyWithTasks('99424204', [
            ['owner' => $manager->name, 'due' => now()->subDay(), 'status' => 'COMPLETED'],
        ]);
        v242CompanyWithTasks('99424205', [
            ['owner' => $manager->name, 'due' => now()->addDay()],
        ]);

        $page = Livewire::actingAs($manager)->test('pages::leads.index');

        expect($page->get('v15DueCount'))->toBe(2)
            ->and($page->get('v17DueBreakdown'))->toBe([
                'overdue' => 1,
                'today' => 2,
            ])
            ->and($page->get('v242DueCounts')['total'])->toBe(2);
    } finally {
        Carbon::setTestNow();
    }
});

it('still reports zero for companies without matching open tasks', function (): void {
    $manager = User::factory()->create([
        'name' => 'Gestor Contadores 242 Sem tarefas',
        'email_verified_at' => now(),
    ]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    v242CompanyWithTasks('99424206', [
        ['owner' => $manager->name, 'due' => now()->addDay()],
        ['owner' => $manager->name, 'due' => now()->subDay(), 'status' => 'DONE'],
    ]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');

    expect($page->get('v15DueCount'))->toBe(0)
        ->and($page->get('v17DueBreakdown'))->toBe([
            'overdue' => 0,
            'today' => 0,
        ]);
});
