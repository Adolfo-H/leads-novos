<?php

use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Livewire\Livewire;

function quickFilterCompany(
    string $root,
    string $name,
    int $score,
    string $priority,
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
            'priority' => $priority,
            'label' => 'Teste',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
        ]);

    return $company;
}

it(
    'filters high priority leads by clicking the KPI',
    function (): void {
        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        quickFilterCompany(
            '81000001',
            'Empresa Prioridade Alta',
            82,
            'high',
        );

        quickFilterCompany(
            '81000002',
            'Empresa Prioridade Media',
            63,
            'medium',
        );

        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'applyKpiView',
                'high'
            )
            ->assertSet(
                'priority',
                'high'
            )
            ->assertSee(
                'Empresa Prioridade Alta'
            )
            ->assertDontSee(
                'Empresa Prioridade Media'
            );
    }
);

it(
    'filters leads by operational status from the KPI',
    function (): void {
        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        $waiting =
            quickFilterCompany(
                '82000001',
                'Empresa Aguardando Retorno',
                78,
                'high',
            );

        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $waiting->id,

                'hubspot_company_id' => 'company-waiting',

                'hubspot_deal_id' => 'deal-waiting',

                'work_status' => 'waiting',

                'open_task_count' => 1,

                'last_task_due_at' => now()->addDay(),

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

        $contacting =
            quickFilterCompany(
                '82000002',
                'Empresa Em Contato',
                78,
                'high',
            );

        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $contacting->id,

                'hubspot_company_id' => 'company-contacting',

                'hubspot_deal_id' => 'deal-contacting',

                'work_status' => 'contacting',

                'open_task_count' => 0,

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'applyKpiView',
                'waiting'
            )
            ->assertSet(
                'workStatus',
                'waiting'
            )
            ->assertSee(
                'Empresa Aguardando Retorno'
            )
            ->assertDontSee(
                'Empresa Em Contato'
            );
    }
);

it(
    'filters leads by a real HubSpot deal stage',
    function (): void {
        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        $qualified =
            quickFilterCompany(
                '83000001',
                'Empresa Etapa Qualificada',
                80,
                'high',
            );

        CompanyCrmCheck::query()
            ->create([
                'company_id' => $qualified->id,

                'provider' => 'hubspot',

                'status' => 'opportunity',

                'external_id' => 'company-qualified',

                'contacted_count' => 1,

                'associated_deals_count' => 1,

                'metadata' => [
                    'deals' => [
                        [
                            'id' => 'deal-qualified',

                            'name' => 'Negocio Qualificado',

                            'stage_id' => 'qualifiedtobuy',

                            'stage_label' => 'Leds qualificado',

                            'pipeline_id' => 'default',

                            'is_closed' => false,

                            'is_closed_won' => false,

                            'closed_at' => null,
                        ],
                    ],

                    'deal_summary' => [
                        'total' => 1,
                        'active' => 1,
                        'won' => 0,
                        'closed_lost' => 0,
                        'stages' => [
                            'Leds qualificado',
                        ],
                    ],
                ],

                'checked_at' => now(),
            ]);

        $other =
            quickFilterCompany(
                '83000002',
                'Empresa Outra Etapa',
                80,
                'high',
            );

        CompanyCrmCheck::query()
            ->create([
                'company_id' => $other->id,

                'provider' => 'hubspot',

                'status' => 'opportunity',

                'external_id' => 'company-other',

                'contacted_count' => 1,

                'associated_deals_count' => 1,

                'metadata' => [
                    'deals' => [
                        [
                            'id' => 'deal-other',

                            'name' => 'Outro Negocio',

                            'stage_id' => 'appointmentscheduled',

                            'stage_label' => 'Reunião realizada',

                            'pipeline_id' => 'default',

                            'is_closed' => false,

                            'is_closed_won' => false,

                            'closed_at' => null,
                        ],
                    ],

                    'deal_summary' => [
                        'total' => 1,
                        'active' => 1,
                        'won' => 0,
                        'closed_lost' => 0,
                        'stages' => [
                            'Reunião realizada',
                        ],
                    ],
                ],

                'checked_at' => now(),
            ]);

        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'applyCrmStageView',
                0
            )
            ->assertSee(
                'Empresa Etapa Qualificada'
            );
    }
);
