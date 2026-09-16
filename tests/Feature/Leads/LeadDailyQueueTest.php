<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function dailyQueueCompany(
    string $root,
    string $name,
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => $root,
            'corporate_name' => $name,
        ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 80,
            'priority' => 'high',
            'label' => 'Prioridade alta',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
        ]);

    return $company;
}

function dailyQueueStatus(
    Company $company,
    string $status,
    ?DateTimeInterface $dueAt = null,
): void {
    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-'
                .$company->cnpj_root,

            'hubspot_deal_id' => 'deal-'
                .$company->cnpj_root,

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'work_status' => $status,

            'open_task_count' => $status === 'waiting'
                    ? 1
                    : 0,

            'last_task_due_at' => $dueAt,

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);
}

beforeEach(function () {
    Carbon::setTestNow(
        '2026-09-16 12:00:00'
    );
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows only actionable leads in my daily queue', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $overdue =
        dailyQueueCompany(
            '88111111',
            'Fila Hoje Atrasado'
        );

    dailyQueueStatus(
        $overdue,
        'waiting',
        now()->subHour()
    );

    $today =
        dailyQueueCompany(
            '88222222',
            'Fila Hoje Tarefa Hoje'
        );

    dailyQueueStatus(
        $today,
        'waiting',
        now()->addHours(2)
    );

    $contacting =
        dailyQueueCompany(
            '88333333',
            'Fila Hoje Em Contato'
        );

    dailyQueueStatus(
        $contacting,
        'contacting'
    );

    $future =
        dailyQueueCompany(
            '88444444',
            'Fila Hoje Futuro'
        );

    dailyQueueStatus(
        $future,
        'waiting',
        now()->addDays(2)
    );

    dailyQueueCompany(
        '88555555',
        'Fila Hoje Lead Novo'
    );

    $discarded =
        dailyQueueCompany(
            '88666666',
            'Fila Hoje Descartado'
        );

    dailyQueueStatus(
        $discarded,
        'discarded'
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyDailyView'
        )
        ->assertSee(
            'Fila Hoje Atrasado'
        )
        ->assertSee(
            'Fila Hoje Tarefa Hoje'
        )
        ->assertSee(
            'Fila Hoje Em Contato'
        )
        ->assertDontSee(
            'Fila Hoje Futuro'
        )
        ->assertDontSee(
            'Fila Hoje Lead Novo'
        )
        ->assertDontSee(
            'Fila Hoje Descartado'
        );
});

it('counts the actionable leads in my daily queue', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $overdue =
        dailyQueueCompany(
            '88777771',
            'Contador Atrasado'
        );

    dailyQueueStatus(
        $overdue,
        'waiting',
        now()->subDay()
    );

    $today =
        dailyQueueCompany(
            '88777772',
            'Contador Hoje'
        );

    dailyQueueStatus(
        $today,
        'waiting',
        now()->addHour()
    );

    $contacting =
        dailyQueueCompany(
            '88777773',
            'Contador Contato'
        );

    dailyQueueStatus(
        $contacting,
        'contacting'
    );

    $future =
        dailyQueueCompany(
            '88777774',
            'Contador Futuro'
        );

    dailyQueueStatus(
        $future,
        'waiting',
        now()->addDays(3)
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->assertSee(
            'Minha fila hoje'
        )
        ->assertSet(
            'dailyView',
            ''
        );

    $component =
        Livewire::actingAs($user)
            ->test(
                'pages::leads.index'
            );

    expect(
        $component
            ->instance()
            ->dailyQueueCount
    )->toBe(3);
});

it('can toggle my daily queue off again', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    dailyQueueCompany(
        '88777775',
        'Lead Novo Toggle'
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyDailyView'
        )
        ->assertSet(
            'dailyView',
            'today'
        )
        ->call(
            'applyDailyView'
        )
        ->assertSet(
            'dailyView',
            ''
        )
        ->assertSee(
            'Lead Novo Toggle'
        );
});
