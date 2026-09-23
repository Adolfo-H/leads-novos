<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Illuminate\Support\Carbon;

function attentionQueueCompany(
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

function attentionQueueHubSpotLead(
    Company $company,
    string $status,
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

it('orders leads by daily commercial urgency', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $overdue =
        attentionQueueCompany(
            '86111111',
            '01 Follow Up Atrasado'
        );

    attentionQueueHubSpotLead(
        $overdue,
        'waiting',
        now()->subHours(2)
    );

    $today =
        attentionQueueCompany(
            '86222222',
            '02 Follow Up Hoje'
        );

    attentionQueueHubSpotLead(
        $today,
        'waiting',
        now()->addHours(3)
    );

    $upcoming =
        attentionQueueCompany(
            '86333333',
            '03 Follow Up Futuro'
        );

    attentionQueueHubSpotLead(
        $upcoming,
        'waiting',
        now()->addDays(2)
    );

    $unscheduled =
        attentionQueueCompany(
            '86444444',
            '04 Follow Up Sem Prazo'
        );

    attentionQueueHubSpotLead(
        $unscheduled,
        'waiting'
    );

    $contacting =
        attentionQueueCompany(
            '86555555',
            '05 Em Contato'
        );

    attentionQueueHubSpotLead(
        $contacting,
        'contacting'
    );

    attentionQueueCompany(
        '86666666',
        '06 Lead Novo'
    );

    $discarded =
        attentionQueueCompany(
            '86777777',
            '07 Lead Descartado'
        );

    attentionQueueHubSpotLead(
        $discarded,
        'discarded'
    );

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSeeInOrder([
            '01 Follow Up Atrasado',
            '02 Follow Up Hoje',
            '03 Follow Up Futuro',
            '04 Follow Up Sem Prazo',
            '05 Em Contato',
            '06 Lead Novo',
            '07 Lead Descartado',
        ]);
});

it('shows a direct contextual action to the HubSpot deal', function () {
    config([
        'services.hubspot.portal_id' => '21358298',
    ]);

    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        attentionQueueCompany(
            '86888888',
            'Lead Acao Rapida'
        );

    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-quick',

            'hubspot_deal_id' => '987654',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'work_status' => 'waiting',

            'open_task_count' => 1,

            'last_task_due_at' => now()->subHour(),

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Acao Rapida'
        )
        ->assertSee(
            'Retomar contato'
        )
        ->assertSee(
            'https://app.hubspot.com/contacts/21358298/record/0-3/987654',
            false
        );
});
