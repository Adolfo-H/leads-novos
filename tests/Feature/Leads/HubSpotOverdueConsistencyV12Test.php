<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Models\User;
use App\Services\DashboardLeadMetricsV23Service;
use App\Services\LeadCrmSituationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('matches Dashboard overdue metric with Leads including deal-only tasks', function (): void {
    Carbon::setTestNow('2026-10-08 11:00:00');

    try {
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

        $companies = [];
        foreach (['Direta', 'Negocio', 'Finalizada'] as $i => $kind) {
            $company = Company::query()->create([
                'cnpj_root' => '9901250'.($i + 1),
                'corporate_name' => 'V12 '.$kind,
            ]);
            $company->sdrScore()->create([
                'score' => 80, 'priority' => 'high', 'label' => 'Alta',
                'is_eligible' => true, 'is_provisional' => false,
                'factors' => [], 'version' => 'v12',
                'metadata' => [], 'calculated_at' => now(),
            ]);
            $hub = HubSpotCompany::query()->create([
                'hubspot_id' => 'v12-hub-'.$i,
                'company_id' => $company->id,
                'match_source' => 'manual_verified',
            ]);
            $task = HubSpotTask::query()->create([
                'hubspot_id' => 'v12-task-'.$i,
                'status' => $kind === 'Finalizada' ? 'DONE' : 'NOT_STARTED',
                'is_open' => true,
                'assigned_to' => $manager->name,
                'due_at' => now()->subHour(),
            ]);
            if ($kind === 'Negocio') {
                $deal = HubSpotDeal::query()->create(['hubspot_id' => 'v12-deal']);
                $hub->deals()->attach($deal->id, ['is_primary' => true]);
                $deal->tasks()->attach($task->id);
            } else {
                $hub->tasks()->attach($task->id);
            }
            $companies[$kind] = $company;
        }

        // Terceira origem: somente resumo legado, confirmado no espelho.
        $legacy = Company::query()->create([
            'cnpj_root' => '99012509',
            'corporate_name' => 'V12 Snapshot',
        ]);
        $legacy->hubSpotLead()->create([
            'hubspot_deal_id' => 'v12-deal-legacy',
            'hubspot_task_id' => 'v12-task-legacy',
            'open_task_count' => 1,
            'last_task_due_at' => now()->subDays(2),
        ]);
        HubSpotTask::query()->create([
            'hubspot_id' => 'v12-task-legacy',
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'due_at' => now()->subHour(),
        ]);

        $metric = app(DashboardLeadMetricsV23Service::class);
        expect($metric->overdueCompanyIds())->toHaveCount(3)
            ->toContain($companies['Direta']->id, $companies['Negocio']->id, $legacy->id)
            ->not->toContain($companies['Finalizada']->id);

        Livewire::actingAs($manager)->test('pages::leads.index')
            ->set('dashboardView', 'overdue')
            ->assertSee('V12 Direta')
            ->assertSee('V12 Negocio')
            ->assertSee('V12 Snapshot')
            ->assertDontSee('V12 Finalizada');

        expect(app(LeadCrmSituationService::class)
            ->dueForAuthenticatedUserPeriod(Company::query(), null, now())
            ->count())->toBe(2);
    } finally {
        Carbon::setTestNow();
    }
});
