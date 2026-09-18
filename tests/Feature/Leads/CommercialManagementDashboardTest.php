<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use App\Services\CommercialManagementMetricsService;
use Illuminate\Support\Carbon;

function managementCompany(
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

function managementStatus(
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

            'deal_stage_id' => $status === 'converted'
                    ? 'closedwon'
                    : 'appointmentscheduled',

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

function managementAssign(
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
        '2026-09-18 10:00:00'
    );
});

afterEach(function () {
    Carbon::setTestNow();
});

it('summarizes the active commercial operation by salesperson', function () {
    $sellerA =
        User::factory()
            ->create([
                'name' => 'Adolfo Comercial',

                'email_verified_at' => now(),
            ]);

    $sellerB =
        User::factory()
            ->create([
                'name' => 'Iago Comercial',

                'email_verified_at' => now(),
            ]);

    $overdue =
        managementCompany(
            '91111111',
            'Gestao Atrasado'
        );

    managementStatus(
        $overdue,
        'waiting',
        now()->subHour()
    );

    managementAssign(
        $overdue,
        $sellerA
    );

    $contacting =
        managementCompany(
            '92222222',
            'Gestao Em Contato'
        );

    managementStatus(
        $contacting,
        'contacting'
    );

    managementAssign(
        $contacting,
        $sellerA
    );

    $today =
        managementCompany(
            '93333333',
            'Gestao Hoje'
        );

    managementStatus(
        $today,
        'waiting',
        now()->addHours(2)
    );

    managementAssign(
        $today,
        $sellerB
    );

    $future =
        managementCompany(
            '94444444',
            'Gestao Futuro'
        );

    managementStatus(
        $future,
        'future',
        now()->addDays(30)
    );

    managementAssign(
        $future,
        $sellerB
    );

    managementCompany(
        '95555555',
        'Gestao Sem Responsavel'
    );

    $converted =
        managementCompany(
            '96666666',
            'Gestao Convertido'
        );

    managementStatus(
        $converted,
        'converted'
    );

    managementAssign(
        $converted,
        $sellerA
    );

    $dashboard =
        app(
            CommercialManagementMetricsService::class
        )->dashboard();

    $summary =
        $dashboard['summary'];

    expect(
        $summary['active_total']
    )->toBe(5);

    expect(
        $summary['assigned_total']
    )->toBe(4);

    expect(
        $summary['unassigned_total']
    )->toBe(1);

    expect(
        $summary['new_total']
    )->toBe(1);

    expect(
        $summary['contacting_total']
    )->toBe(1);

    expect(
        $summary['waiting_total']
    )->toBe(2);

    expect(
        $summary['overdue_total']
    )->toBe(1);

    expect(
        $summary['due_today_total']
    )->toBe(1);

    expect(
        $summary['future_total']
    )->toBe(1);

    $sellers =
        collect(
            $dashboard['sellers']
        )
            ->keyBy(
                'user_id'
            );

    expect(
        $sellers
            ->get(
                $sellerA->id
            )['active_total']
    )->toBe(2);

    expect(
        $sellers
            ->get(
                $sellerA->id
            )['overdue_total']
    )->toBe(1);

    expect(
        $sellers
            ->get(
                $sellerA->id
            )['contacting_total']
    )->toBe(1);

    expect(
        $sellers
            ->get(
                $sellerB->id
            )['active_total']
    )->toBe(2);

    expect(
        $sellers
            ->get(
                $sellerB->id
            )['due_today_total']
    )->toBe(1);

    expect(
        $sellers
            ->get(
                $sellerB->id
            )['future_total']
    )->toBe(1);
});

it('renders the management dashboard', function () {
    $manager =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $seller =
        User::factory()
            ->create([
                'name' => 'Vendedor Painel',

                'email_verified_at' => now(),
            ]);

    $company =
        managementCompany(
            '97777777',
            'Empresa Painel Gestao'
        );

    managementAssign(
        $company,
        $seller
    );

    $this
        ->actingAs(
            $manager
        )
        ->get(
            route(
                'leads.management'
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Gestão Comercial'
        )
        ->assertSee(
            'Vendedor Painel'
        )
        ->assertSee(
            'Carteira ativa'
        )
        ->assertSee(
            'Sem responsável'
        );
});

it('opens the leads page already filtered by salesperson through the url', function () {
    $manager =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $seller =
        User::factory()
            ->create([
                'name' => 'Vendedor URL',

                'email_verified_at' => now(),
            ]);

    $mine =
        managementCompany(
            '98888881',
            'Carteira Filtrada URL'
        );

    managementAssign(
        $mine,
        $seller
    );

    managementCompany(
        '98888882',
        'Empresa Fora da Carteira URL'
    );

    $this
        ->actingAs(
            $manager
        )
        ->get(
            route(
                'leads.index',
                [
                    'owner' => (string) $seller->id,
                ]
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Carteira Filtrada URL'
        )
        ->assertDontSee(
            'Empresa Fora da Carteira URL'
        );
});
