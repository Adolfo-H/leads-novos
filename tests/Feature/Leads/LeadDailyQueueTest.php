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

function dailyQueueAssign(
    Company $company,
    User $user,
): void {
    $company
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $user->id,

            'status' => 'new',
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

it('shows only actionable leads assigned to me in my daily queue', function () {
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

    dailyQueueAssign(
        $overdue,
        $user
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

    dailyQueueAssign(
        $today,
        $user
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

    dailyQueueAssign(
        $contacting,
        $user
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

    dailyQueueAssign(
        $future,
        $user
    );

    $new =
        dailyQueueCompany(
            '88555555',
            'Fila Hoje Lead Novo'
        );

    dailyQueueAssign(
        $new,
        $user
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

    dailyQueueAssign(
        $discarded,
        $user
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

it('does not show actionable leads owned by another salesperson', function () {
    $me =
        User::factory()->create([
            'name' => 'Vendedor Logado',

            'email_verified_at' => now(),
        ]);

    $other =
        User::factory()->create([
            'name' => 'Outro Vendedor',

            'email_verified_at' => now(),
        ]);

    $mine =
        dailyQueueCompany(
            '88777111',
            'Minha Tarefa'
        );

    dailyQueueStatus(
        $mine,
        'waiting',
        now()->subHour()
    );

    dailyQueueAssign(
        $mine,
        $me
    );

    $otherLead =
        dailyQueueCompany(
            '88777222',
            'Tarefa Outro Vendedor'
        );

    dailyQueueStatus(
        $otherLead,
        'waiting',
        now()->subHour()
    );

    dailyQueueAssign(
        $otherLead,
        $other
    );

    $unassigned =
        dailyQueueCompany(
            '88777333',
            'Tarefa Sem Responsavel'
        );

    dailyQueueStatus(
        $unassigned,
        'waiting',
        now()->subHour()
    );

    $component =
        Livewire::actingAs($me)
            ->test(
                'pages::leads.index'
            )
            ->call(
                'applyDailyView'
            )
            ->assertSee(
                'Minha Tarefa'
            )
            ->assertDontSee(
                'Tarefa Outro Vendedor'
            )
            ->assertDontSee(
                'Tarefa Sem Responsavel'
            );

    expect(
        $component
            ->instance()
            ->dailyQueueCount
    )->toBe(1);
});

it('counts only actionable leads from my portfolio', function () {
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

    dailyQueueAssign(
        $overdue,
        $user
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

    dailyQueueAssign(
        $today,
        $user
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

    dailyQueueAssign(
        $contacting,
        $user
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

    dailyQueueAssign(
        $future,
        $user
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

it('clears another owner filter when opening my daily queue', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->set(
            'owner',
            'unassigned'
        )
        ->call(
            'applyDailyView'
        )
        ->assertSet(
            'owner',
            ''
        )
        ->assertSet(
            'dailyView',
            'today'
        );
});

it('changing the portfolio closes my daily queue', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

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
        ->set(
            'owner',
            'mine'
        )
        ->assertSet(
            'dailyView',
            ''
        );
});

it('can toggle my daily queue off again', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        dailyQueueCompany(
            '88777775',
            'Lead Novo Toggle'
        );

    dailyQueueAssign(
        $company,
        $user
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
