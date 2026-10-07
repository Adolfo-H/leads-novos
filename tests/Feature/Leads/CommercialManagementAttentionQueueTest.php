<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use App\Services\CommercialManagementMetricsService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function commercialManagementAttentionQueueCompany(
    string $root,
    string $name,
    int $score = 80,
): Company {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => $root,

                'corporate_name' => $name,
            ]);

    $company
        ->sdrScore()
        ->create([
            'score' => $score,

            'priority' => 'high',

            'label' => 'Teste atenção',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    return $company;
}

function commercialManagementAttentionQueueAssign(
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

function commercialManagementAttentionQueueStatus(
    Company $company,
    string $status,
    ?DateTimeInterface $dueAt = null,
    ?DateTimeInterface $activityAt = null,
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

            'last_activity_at' => $activityAt,

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);
}

beforeEach(function () {
    Carbon::setTestNow(
        '2026-10-07 14:00:00'
    );

    config([
        'prospector.sdr.stale_after_days' => 7,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('builds the management attention queue in urgency order', function () {
    $seller =
        User::factory()
            ->create([
                'name' => 'Vendedor Atenção',

                'email_verified_at' => now(),
            ]);

    $overdue =
        commercialManagementAttentionQueueCompany(
            '88111111',
            'Fila Gerencial Atrasado'
        );

    commercialManagementAttentionQueueAssign(
        $overdue,
        $seller
    );

    commercialManagementAttentionQueueStatus(
        $overdue,
        'waiting',
        now()->subHours(2)
    );

    $today =
        commercialManagementAttentionQueueCompany(
            '88222222',
            'Fila Gerencial Hoje'
        );

    commercialManagementAttentionQueueAssign(
        $today,
        $seller
    );

    commercialManagementAttentionQueueStatus(
        $today,
        'waiting',
        now()->addHours(2)
    );

    $unscheduled =
        commercialManagementAttentionQueueCompany(
            '88333333',
            'Fila Gerencial Sem Prazo',
            95
        );

    commercialManagementAttentionQueueAssign(
        $unscheduled,
        $seller
    );

    commercialManagementAttentionQueueStatus(
        $unscheduled,
        'waiting'
    );

    $stale =
        commercialManagementAttentionQueueCompany(
            '88444444',
            'Fila Gerencial Parado'
        );

    commercialManagementAttentionQueueAssign(
        $stale,
        $seller
    );

    commercialManagementAttentionQueueStatus(
        $stale,
        'contacting',
        null,
        now()->subDays(10)
    );

    $healthy =
        commercialManagementAttentionQueueCompany(
            '88555555',
            'Fila Gerencial Saudavel'
        );

    commercialManagementAttentionQueueAssign(
        $healthy,
        $seller
    );

    commercialManagementAttentionQueueStatus(
        $healthy,
        'contacting',
        null,
        now()->subDay()
    );

    $queue =
        app(
            CommercialManagementMetricsService::class
        )->attentionQueue();

    expect(
        $queue
    )->toHaveCount(
        4
    );

    expect(
        array_column(
            $queue,
            'reason_key'
        )
    )->toBe([
        'overdue',
        'today',
        'unscheduled',
        'stale',
    ]);

    expect(
        $queue[0][
            'company_name'
        ]
    )->toBe(
        'Fila Gerencial Atrasado'
    );

    expect(
        $queue[0][
            'owner_name'
        ]
    )->toBe(
        'Vendedor Atenção'
    );

    expect(
        $queue[0][
            'filters'
        ][
            'followUp'
        ]
    )->toBe(
        'overdue'
    );
});

it('respects the management attention queue limit', function () {
    for (
        $index = 1;
        $index <= 4;
        $index++
    ) {
        $company =
            commercialManagementAttentionQueueCompany(
                '8866666'.$index,
                'Fila Limite '.$index
            );

        commercialManagementAttentionQueueStatus(
            $company,
            'waiting',
            now()->subHour()
        );
    }

    $queue =
        app(
            CommercialManagementMetricsService::class
        )->attentionQueue(
            limit: 2
        );

    expect(
        $queue
    )->toHaveCount(
        2
    );
});

it('renders concrete companies in the management attention queue', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,

                'email_verified_at' => now(),
            ]);

    $seller =
        User::factory()
            ->create([
                'name' => 'Responsável Fila Gerencial',

                'email_verified_at' => now(),
            ]);

    $company =
        commercialManagementAttentionQueueCompany(
            '88777777',
            'Empresa Atenção Gestão'
        );

    commercialManagementAttentionQueueAssign(
        $company,
        $seller
    );

    commercialManagementAttentionQueueStatus(
        $company,
        'waiting',
        now()->subHour()
    );

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::leads.management'
        )
        ->assertSee(
            'Empresas que precisam de atenção'
        )
        ->assertSee(
            'Empresa Atenção Gestão'
        )
        ->assertSee(
            'Responsável Fila Gerencial'
        )
        ->assertSee(
            'Follow-up atrasado'
        )
        ->assertSee(
            'Ver fila'
        )
        ->assertSee(
            'Abrir dossiê'
        );
});
