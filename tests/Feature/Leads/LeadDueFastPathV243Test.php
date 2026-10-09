<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v243LeadWithTask(User $owner, string $root, DateTimeInterface $due): void
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => 'Empresa V243 '.$root,
    ]);

    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v243',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v243-company-'.$root,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'v243-task-'.$root,
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'assigned_to' => $owner->name,
        'due_at' => $due,
    ]);

    $hub->tasks()->attach($task->id);
}

it('skips expensive company-task association queries if all open tasks are future', function (): void {
    Carbon::setTestNow('2026-10-09 09:00:00');

    try {
        $manager = User::factory()->create([
            'name' => 'Gestor Fastpath V243',
            'email_verified_at' => now(),
        ]);
        $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

        v243LeadWithTask($manager, '99424301', now()->addDays(4));

        $expensiveDueQueries = 0;
        DB::listen(static function (QueryExecuted $event) use (&$expensiveDueQueries): void {
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'from "companies"')
                && str_contains($sql, 'hubspot_tasks')
                && str_contains($sql, 'assigned_to')
                && str_contains($sql, 'due_at')) {
                $expensiveDueQueries++;
            }
        });

        $page = Livewire::actingAs($manager)->test('pages::leads.index');

        expect($page->get('v242DueCounts'))->toBe([
            'total' => 0,
            'overdue' => 0,
            'today' => 0,
        ])->and($expensiveDueQueries)->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('selects only company IDs when an actual task is due', function (): void {
    Carbon::setTestNow('2026-10-09 11:00:00');

    try {
        $manager = User::factory()->create([
            'name' => 'Gestor com Tarefa V243',
            'email_verified_at' => now(),
        ]);
        $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

        v243LeadWithTask($manager, '99424302', now()->subHours(2));

        $taskCompanyQueries = [];
        DB::listen(static function (QueryExecuted $event) use (&$taskCompanyQueries): void {
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'from "companies"')
                && str_contains($sql, 'hubspot_tasks')
                && str_contains($sql, 'assigned_to')
                && str_contains($sql, 'due_at')) {
                $taskCompanyQueries[] = $sql;
            }
        });

        $page = Livewire::actingAs($manager)->test('pages::leads.index');

        expect($page->get('v242DueCounts'))->toBe([
            'total' => 1,
            'overdue' => 1,
            'today' => 0,
        ]);

        expect($taskCompanyQueries)->not->toBeEmpty();
        foreach ($taskCompanyQueries as $sql) {
            expect($sql)->toContain('"companies"."id"')
                ->not->toContain('select "companies".*');
        }
    } finally {
        Carbon::setTestNow();
    }
});
