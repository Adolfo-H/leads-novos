<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use App\Services\CommercialManagementMetricsService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function executiveCompany(
    string $root,
    string $name,
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

function executiveAssign(
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

function executiveStatus(
    Company $company,
    string $status,
    ?DateTimeInterface $dueAt = null,
    ?DateTimeInterface $activityAt = null,
): CompanyHubSpotLead {
    return CompanyHubSpotLead::query()
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

it('adds executive attention metrics to commercial management', function () {
    $seller =
        User::factory()
            ->create([
                'name' => 'Vendedor Executivo',

                'email_verified_at' => now(),
            ]);

    $overdue =
        executiveCompany(
            '87111111',
            'Executivo Atrasado'
        );

    executiveAssign(
        $overdue,
        $seller
    );

    executiveStatus(
        $overdue,
        'waiting',
        now()->subHour()
    );

    $today =
        executiveCompany(
            '87222222',
            'Executivo Hoje'
        );

    executiveAssign(
        $today,
        $seller
    );

    executiveStatus(
        $today,
        'waiting',
        now()->addHours(2)
    );

    $unscheduled =
        executiveCompany(
            '87333333',
            'Executivo Sem Prazo'
        );

    executiveAssign(
        $unscheduled,
        $seller
    );

    executiveStatus(
        $unscheduled,
        'waiting'
    );

    $stale =
        executiveCompany(
            '87444444',
            'Executivo Parado'
        );

    executiveAssign(
        $stale,
        $seller
    );

    executiveStatus(
        $stale,
        'contacting',
        null,
        now()->subDays(10)
    );

    $dashboard =
        app(
            CommercialManagementMetricsService::class
        )->dashboard();

    $summary =
        $dashboard[
            'summary'
        ];

    expect(
        $summary[
            'opportunities_total'
        ]
    )->toBe(
        4
    );

    expect(
        $summary[
            'overdue_total'
        ]
    )->toBe(
        1
    );

    expect(
        $summary[
            'due_today_total'
        ]
    )->toBe(
        1
    );

    expect(
        $summary[
            'unscheduled_total'
        ]
    )->toBe(
        1
    );

    expect(
        $summary[
            'stale_total'
        ]
    )->toBe(
        1
    );

    expect(
        $summary[
            'attention_total'
        ]
    )->toBe(
        4
    );

    $sellerMetrics =
        collect(
            $dashboard[
                'sellers'
            ]
        )
            ->firstWhere(
                'user_id',
                $seller->id
            );

    expect(
        $sellerMetrics
    )->not->toBeNull();

    expect(
        $sellerMetrics[
            'attention_total'
        ]
    )->toBe(
        4
    );
});

it('renders workload and attention by salesperson', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,

                'email_verified_at' => now(),
            ]);

    $seller =
        User::factory()
            ->create([
                'name' => 'Vendedor Carga Gerencial',

                'email_verified_at' => now(),
            ]);

    $company =
        executiveCompany(
            '87555555',
            'Empresa Carga Gerencial'
        );

    executiveAssign(
        $company,
        $seller
    );

    executiveStatus(
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
            'Oportunidades ativas'
        )
        ->assertSee(
            'Exigem atenção'
        )
        ->assertSee(
            'Carga e pendências por vendedor'
        )
        ->assertSee(
            'Vendedor Carga Gerencial'
        )
        ->assertSee(
            'Sem prazo'
        )
        ->assertSee(
            'Parados'
        )
        ->assertSee(
            'Abrir carteira'
        );
});

it('keeps the management dashboard restricted to managers', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,

                'email_verified_at' => now(),
            ]);

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::leads.management'
        )
        ->assertStatus(
            403
        );
});
