<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Services\DashboardLeadMetricsV23Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Cria tarefas do espelho sem buscar a API do HubSpot. */
function v247LinkedTask(string $root, DateTimeInterface $dueAt, string $status = 'NOT_STARTED', bool $throughDeal = false): int
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => 'Empresa V247 '.$root,
    ]);

    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v247-company-'.$root,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'v247-task-'.$root,
        'title' => 'Retorno do teste '.$root,
        'status' => $status,
        'is_open' => true,
        'due_at' => $dueAt,
        'completed_at' => null,
    ]);

    if ($throughDeal) {
        $deal = HubSpotDeal::query()->create([
            'hubspot_id' => 'v247-deal-'.$root,
        ]);

        $deal->companies()->attach($hub->id, ['is_primary' => true]);
        $deal->tasks()->attach($task->id);
    } else {
        $hub->tasks()->attach($task->id);
    }

    return (int) $company->id;
}

it('avoids searching companies and their associations when all open tasks are future', function (): void {
    $companyId = v247LinkedTask('99247001', now()->addDays(2));
    $sqlQueries = [];

    DB::listen(static function (QueryExecuted $event) use (&$sqlQueries): void {
        $sqlQueries[] = strtolower($event->sql);
    });

    expect(app(DashboardLeadMetricsV23Service::class)
        ->overdueCompanyIds([$companyId]))->toBe([]);

    $heavyQueries = array_filter(
        $sqlQueries,
        static fn (string $sql): bool => str_contains($sql, 'from "companies"')
            && str_contains($sql, 'hubspot_tasks')
    );

    expect($heavyQueries)->toBe([]);
    expect(collect($sqlQueries)->contains(
        static fn (string $sql): bool => str_contains($sql, 'hubspot_tasks')
    ))->toBeTrue();
});

it('keeps direct and deal-linked overdue tasks and rejects future or completed tasks', function (): void {
    $direct = v247LinkedTask('99247002', now()->subHours(3));
    $throughDeal = v247LinkedTask('99247003', now()->subDay(), 'NOT_STARTED', true);
    $future = v247LinkedTask('99247004', now()->addDays(2));
    $completed = v247LinkedTask('99247005', now()->subDays(2), 'COMPLETED');

    $actual = app(DashboardLeadMetricsV23Service::class)
        ->overdueCompanyIds([$direct, $throughDeal, $future, $completed]);

    expect($actual)->toEqualCanonicalizing([$direct, $throughDeal]);
});
