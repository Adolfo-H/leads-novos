<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use App\Services\CommercialActivityRecorder;
use App\Services\CompanyService;

it('records HubSpot commercial transitions', function () {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '91111111',

                'corporate_name' => 'Empresa Timeline',
            ]);

    $dueAt =
        now()->addDay();

    $lead =
        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_company_id' => 'company-timeline',

                'hubspot_deal_id' => 'deal-timeline',

                'pipeline_id' => 'default',

                'deal_stage_id' => 'appointmentscheduled',

                'work_status' => 'waiting',

                'open_task_count' => 1,

                'last_task_due_at' => $dueAt,

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

    $recorder =
        app(
            CommercialActivityRecorder::class
        );

    $recorder->recordLeadSynced(
        $lead
    );

    $recorder->recordCurrentSnapshot(
        $lead
    );

    $previousDueAt =
        $dueAt->toIso8601String();

    $lead->forceFill([
        'work_status' => 'discarded',

        'deal_stage_id' => '13185627',

        'open_task_count' => 0,

        'last_task_due_at' => null,
    ])->save();

    $lead =
        $lead->refresh();

    $recorder->recordHubSpotChanges(
        lead: $lead,
        previousStatus: 'waiting',
        previousStage: 'appointmentscheduled',
        previousOpenTasks: 1,
        previousDueAt: $previousDueAt,
    );

    expect(
        $company
            ->leadActivities()
            ->where(
                'type',
                'hubspot_synced'
            )
            ->count()
    )->toBe(1);

    expect(
        $company
            ->leadActivities()
            ->where(
                'type',
                'hubspot_snapshot'
            )
            ->count()
    )->toBe(1);

    expect(
        $company
            ->leadActivities()
            ->where(
                'type',
                'hubspot_status_changed'
            )
            ->count()
    )->toBe(1);

    expect(
        $company
            ->leadActivities()
            ->where(
                'type',
                'hubspot_stage_changed'
            )
            ->count()
    )->toBe(1);

    expect(
        $company
            ->leadActivities()
            ->where(
                'type',
                'hubspot_task_changed'
            )
            ->count()
    )->toBe(1);
});

it('does not duplicate baseline HubSpot events', function () {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '92222222',

                'corporate_name' => 'Empresa Sem Duplicidade',
            ]);

    $lead =
        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_company_id' => 'company-dedup',

                'hubspot_deal_id' => 'deal-dedup',

                'pipeline_id' => 'default',

                'deal_stage_id' => 'appointmentscheduled',

                'work_status' => 'contacting',

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

    $recorder =
        app(
            CommercialActivityRecorder::class
        );

    $recorder->recordLeadSynced(
        $lead
    );

    $recorder->recordLeadSynced(
        $lead
    );

    $recorder->recordCurrentSnapshot(
        $lead
    );

    $recorder->recordCurrentSnapshot(
        $lead
    );

    expect(
        $company
            ->leadActivities()
            ->where(
                'type',
                'hubspot_synced'
            )
            ->count()
    )->toBe(1);

    expect(
        $company
            ->leadActivities()
            ->where(
                'type',
                'hubspot_snapshot'
            )
            ->count()
    )->toBe(1);
});

it('renders commercial timeline in the company dossier', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        app(
            CompanyService::class
        )
            ->createOrUpdateFromEstablishment(
                [
                    'corporate_name' => 'Empresa Histórico Comercial',
                ],
                [
                    'cnpj' => '11.222.333/0001-81',

                    'type' => 'matrix',

                    'registration_status' => 'ATIVA',

                    'state' => 'PR',

                    'municipality_name' => 'Toledo',
                ]
            );

    $dueAt =
        now()->addDay();

    $lead =
        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_company_id' => 'company-dossier',

                'hubspot_deal_id' => 'deal-dossier',

                'pipeline_id' => 'default',

                'deal_stage_id' => 'appointmentscheduled',

                'work_status' => 'waiting',

                'open_task_count' => 1,

                'last_task_due_at' => $dueAt,

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [
                    'hubspot_status' => [
                        'open_tasks' => [
                            [
                                'id' => 'task-1',

                                'subject' => 'Retornar contato',

                                'status' => 'NOT_STARTED',

                                'due_at' => $dueAt
                                    ->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ]);

    app(
        CommercialActivityRecorder::class
    )->recordLeadSynced(
        $lead
    );

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Histórico comercial'
        )
        ->assertSee(
            'Aguardando retorno'
        )
        ->assertSee(
            'Prospects'
        )
        ->assertSee(
            'Retornar contato'
        )
        ->assertSee(
            'Lead enviado ao HubSpot'
        );
});
