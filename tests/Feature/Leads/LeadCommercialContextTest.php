<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;

beforeEach(function () {
    /*
     * O link direto do Deal depende do Portal ID.
     *
     * O teste não pode depender do .env local,
     * porque o GitHub Actions não possui esse valor.
     */
    config([
        'services.hubspot.portal_id' => '12345678',
    ]);
});

function commercialContextCompany(
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

it('shows the HubSpot task subject as commercial context', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        commercialContextCompany(
            '87111111',
            'Lead Contexto Follow Up'
        );

    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-context',

            'hubspot_deal_id' => 'deal-context',

            'work_status' => 'waiting',

            'open_task_count' => 1,

            'last_task_due_at' => now()->addDay(),

            'synced_at' => now(),

            'metadata' => [
                'hubspot_status' => [
                    'open_tasks' => [
                        [
                            'id' => 'task-1',
                            'subject' => 'Retornar para financeiro',
                            'status' => 'NOT_STARTED',
                            'due_at' => now()
                                ->addDay()
                                ->toIso8601String(),
                        ],
                    ],
                ],
            ],
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Contexto Follow Up'
        )
        ->assertSee(
            'Retornar para financeiro'
        )
        ->assertSee(
            'Abrir follow-up'
        );
});

it('shows that an unsynced lead still needs first contact', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    commercialContextCompany(
        '87222222',
        'Lead Primeiro Contato'
    );

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Primeiro Contato'
        )
        ->assertSee(
            'Ainda não enviado ao HubSpot'
        );
});
