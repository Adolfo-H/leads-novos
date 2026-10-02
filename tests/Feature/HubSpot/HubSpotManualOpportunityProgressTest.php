<?php

use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

function hubSpotManualOpportunityProgressCompany(
    string $root
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => $root,

            'corporate_name' => 'Empresa Progress '
                .$root,
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'not_found',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    return $company;
}

beforeEach(function () {
    config([
        'services.hubspot.manual_opportunity_enabled' => true,

        'services.hubspot.portal_id' => '123456',
    ]);
});

it('derives progress from the HubSpot ids already persisted', function () {
    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        hubSpotManualOpportunityProgressCompany(
            '67676767'
        );

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'company-progress',

            'hubspot_contact_id' => 'contact-progress',

            'hubspot_deal_id' => 'deal-progress',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'metadata' => [
                'manual_sync' => [
                    'status' => 'processing',

                    'progress' => 10,
                ],
            ],
        ]);

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'showHubSpotOpportunityProgress',
            true
        )
        ->assertSee(
            '70%'
        )
        ->assertSee(
            'Negócio criado'
        );
});

it('does not show completed while local reconciliation is still running', function () {
    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        hubSpotManualOpportunityProgressCompany(
            '68686868'
        );

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'company-remote',

            'hubspot_contact_id' => 'contact-remote',

            'hubspot_deal_id' => 'deal-remote',

            'hubspot_task_id' => 'task-remote',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'synced_at' => now(),

            'metadata' => [
                'manual_sync' => [
                    'status' => 'processing',

                    'progress' => 90,

                    'step' => 'refreshing_crm',

                    'progress_message' => 'Objetos criados. Atualizando CRM local.',
                ],
            ],
        ]);

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'showHubSpotOpportunityProgress',
            true
        )
        ->assertSee(
            '90%'
        )
        ->assertSee(
            'Objetos criados. Atualizando CRM local.'
        )
        ->assertDontSee(
            'Oportunidade criada e sincronizada com sucesso.'
        );
});

it('shows one hundred percent only after complete synchronization', function () {
    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        hubSpotManualOpportunityProgressCompany(
            '69696969'
        );

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'company-completed',

            'hubspot_deal_id' => 'deal-completed',

            'hubspot_task_id' => 'task-completed',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'synced_at' => now(),

            'metadata' => [
                'manual_sync' => [
                    'status' => 'completed',

                    'progress' => 100,

                    'step' => 'completed',

                    'progress_message' => 'Oportunidade criada e sincronizada com sucesso.',
                ],
            ],
        ]);

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'showHubSpotOpportunityProgress',
            true
        )
        ->assertSee(
            '100%'
        )
        ->assertSee(
            'Oportunidade criada e sincronizada com sucesso.'
        )
        ->assertSee(
            'Concluir'
        );
});
