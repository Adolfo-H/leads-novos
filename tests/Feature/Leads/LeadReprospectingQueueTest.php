<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function reprospectingCompany(
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

function reprospectingLead(
    Company $company,
    string $status,
    ?DateTimeInterface $statusChangedAt = null,
    ?DateTimeInterface $dueAt = null,
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

            'work_status_changed_at' => $statusChangedAt,

            'last_task_due_at' => $dueAt,

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);
}

beforeEach(function () {
    Carbon::setTestNow(
        '2026-09-17 15:00:00'
    );

    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows only leads ready for reprospecting', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $futureReady =
        reprospectingCompany(
            '97666661',
            'Futuro Pronto'
        );

    reprospectingLead(
        $futureReady,
        'future',
        now()->subDays(20),
        now()->subHour()
    );

    $refusedReady =
        reprospectingCompany(
            '97666662',
            'Recusado Liberado'
        );

    reprospectingLead(
        $refusedReady,
        'refused',
        now()->subDays(181)
    );

    $refusedBlocked =
        reprospectingCompany(
            '97666663',
            'Recusado Bloqueado'
        );

    reprospectingLead(
        $refusedBlocked,
        'refused',
        now()->subDays(30)
    );

    $discarded =
        reprospectingCompany(
            '97666664',
            'Descartado Manual'
        );

    reprospectingLead(
        $discarded,
        'discarded',
        now()->subDays(500)
    );

    $component =
        Livewire::actingAs($user)
            ->test(
                'pages::leads.index'
            );

    expect(
        $component
            ->instance()
            ->reprospectingReadyCount
    )->toBe(2);

    $component
        ->call(
            'applyReprospectingReadyView'
        )
        ->assertSee(
            'Futuro Pronto'
        )
        ->assertSee(
            'Recusado Liberado'
        )
        ->assertDontSee(
            'Recusado Bloqueado'
        )
        ->assertDontSee(
            'Descartado Manual'
        );
});

it('shows reprospecting context on the lead row', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        reprospectingCompany(
            '97666665',
            'Lead Retomada Contexto'
        );

    reprospectingLead(
        $company,
        'refused',
        now()->subDays(181)
    );

    $this
        ->actingAs($user)
        ->get(
            route(
                'leads.index'
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Retomada Contexto'
        )
        ->assertSee(
            'Lead recusado liberado para nova abordagem.'
        );
});

it('includes a due future opportunity in my daily queue', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $due =
        reprospectingCompany(
            '97666666',
            'Futuro Venceu Hoje'
        );

    reprospectingLead(
        $due,
        'future',
        now()->subDays(15),
        now()->subHour()
    );

    $later =
        reprospectingCompany(
            '97666667',
            'Futuro Ainda Distante'
        );

    reprospectingLead(
        $later,
        'future',
        now()->subDays(15),
        now()->addDays(5)
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyDailyView'
        )
        ->assertSee(
            'Futuro Venceu Hoje'
        )
        ->assertDontSee(
            'Futuro Ainda Distante'
        );
});
