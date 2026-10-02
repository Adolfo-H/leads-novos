<?php

use App\Jobs\SyncManualHubSpotOpportunity;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function manualOpportunityDossierCompany(
    string $root
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => $root,

            'corporate_name' => 'Empresa Dossiê HubSpot '
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

        'services.hubspot.manual_opportunity_stale_minutes' => 20,

        'services.hubspot.lead_pipeline' => 'default',

        'services.hubspot.lead_initial_stage' => 'appointmentscheduled',

        'services.hubspot.portal_id' => '123456',
    ]);
});

it('shows the manual HubSpot opportunity action for a company not found in CRM', function () {
    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        manualOpportunityDossierCompany(
            '11112222'
        );

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->assertSee(
            'Integração HubSpot'
        )
        ->assertSee(
            'Criar oportunidade no HubSpot'
        );
});

it('queues the manual opportunity from the company dossier', function () {
    Queue::fake();

    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        manualOpportunityDossierCompany(
            '22223333'
        );

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->call(
            'createHubSpotOpportunity'
        )
        ->assertHasNoErrors()
        ->assertSee(
            'Na fila'
        )
        ->assertSee(
            'Solicitação enviada para processamento.'
        );

    Queue::assertPushed(
        SyncManualHubSpotOpportunity::class,
        function (
            SyncManualHubSpotOpportunity $job
        ) use (
            $company,
            $manager
        ): bool {
            return
                $job->companyId
                    === $company->id
                && $job->actorId
                    === $manager->id;
        }
    );
});

it('shows retry after a failed manual opportunity', function () {
    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        manualOpportunityDossierCompany(
            '33334444'
        );

    $company
        ->hubSpotLead()
        ->create([
            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'sync_error' => 'Erro de teste HubSpot',

            'metadata' => [
                'manual_sync' => [
                    'status' => 'failed',

                    'last_error' => 'Erro de teste HubSpot',

                    'initiated_by_email' => $manager->email,
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
        ->assertSee(
            'Falha na criação'
        )
        ->assertSee(
            'Erro de teste HubSpot'
        )
        ->assertSee(
            'Tentar novamente'
        );
});

it('shows direct company and deal links after completion', function () {
    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        manualOpportunityDossierCompany(
            '44445555'
        );

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'company-123',

            'hubspot_deal_id' => 'deal-456',

            'hubspot_task_id' => 'task-789',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'synced_at' => now(),

            'metadata' => [
                'manual_sync' => [
                    'status' => 'completed',

                    'initiated_by_email' => $manager->email,
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
        ->assertSee(
            'Oportunidade criada'
        )
        ->assertSee(
            'Abrir empresa'
        )
        ->assertSee(
            'Abrir negócio'
        )
        ->assertSeeHtml(
            'https://app.hubspot.com/contacts/123456/record/0-2/company-123'
        )
        ->assertSeeHtml(
            'https://app.hubspot.com/contacts/123456/record/0-3/deal-456'
        );
});

it('does not show the creation action when the feature is disabled', function () {
    config([
        'services.hubspot.manual_opportunity_enabled' => false,
    ]);

    $manager =
        User::factory()->create([
            'email_verified_at' => now(),

            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        manualOpportunityDossierCompany(
            '55556666'
        );

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->assertDontSee(
            'Criar oportunidade no HubSpot'
        );
});
