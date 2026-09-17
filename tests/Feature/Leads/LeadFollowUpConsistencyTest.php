<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function consistencyCompany(
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

function consistencyWaitingLead(
    Company $company,
    ?DateTimeInterface $dueAt,
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

            'work_status' => 'waiting',

            'open_task_count' => 1,

            'last_task_due_at' => $dueAt,

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);
}

it('keeps overdue and today follow ups in exclusive buckets', function () {
    Carbon::setTestNow(
        '2026-09-17 15:00:00'
    );

    try {
        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        $overdue =
            consistencyCompany(
                '95666661',
                'Follow Up Hoje Ja Atrasado'
            );

        consistencyWaitingLead(
            $overdue,
            now()->subHours(2)
        );

        $laterToday =
            consistencyCompany(
                '95666662',
                'Follow Up Mais Tarde Hoje'
            );

        consistencyWaitingLead(
            $laterToday,
            now()->addHours(2)
        );

        $component =
            Livewire::actingAs($user)
                ->test(
                    'pages::leads.index'
                );

        expect(
            $component
                ->instance()
                ->overdueCount
        )->toBe(1);

        expect(
            $component
                ->instance()
                ->dueTodayCount
        )->toBe(1);

        $component
            ->call(
                'applyFollowUpView',
                'today'
            )
            ->assertSee(
                'Follow Up Mais Tarde Hoje'
            )
            ->assertDontSee(
                'Follow Up Hoje Ja Atrasado'
            );
    } finally {
        Carbon::setTestNow();
    }
});

it('clears deadline subtype when opening the waiting quick view', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        consistencyCompany(
            '95666663',
            'Lead Quick View'
        );

    consistencyWaitingLead(
        $company,
        now()->subDay()
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyFollowUpView',
            'overdue'
        )
        ->assertSet(
            'followUp',
            'overdue'
        )
        ->assertSet(
            'workStatus',
            'waiting'
        )
        ->call(
            'applyQuickView',
            'waiting'
        )
        ->assertSet(
            'workStatus',
            'waiting'
        )
        ->assertSet(
            'followUp',
            ''
        );
});

it('keeps manual status and deadline filters consistent', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        consistencyCompany(
            '95666664',
            'Lead Filtros Manuais'
        );

    consistencyWaitingLead(
        $company,
        now()->subDay()
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->set(
            'followUp',
            'overdue'
        )
        ->assertSet(
            'workStatus',
            'waiting'
        )
        ->set(
            'workStatus',
            'contacting'
        )
        ->assertSet(
            'workStatus',
            'contacting'
        )
        ->assertSet(
            'followUp',
            ''
        )
        ->assertSet(
            'dailyView',
            ''
        );
});
