<?php

use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it(
    'queues a full HubSpot CRM refresh from the leads screen',
    function (): void {
        Queue::fake();

        $manager =
            User::factory()
                ->create([
                    'commercial_role' => User::ROLE_MANAGER,
                ]);

        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '88990011',

                    'corporate_name' => 'Empresa Refresh Geral HubSpot',
                ]);

        $company
            ->sdrScore()
            ->create([
                'score' => 75,

                'priority' => 'high',

                'label' => 'Prioridade alta',

                'is_eligible' => true,

                'is_provisional' => false,

                'factors' => [],

                'version' => 'test',

                'metadata' => [],

                'calculated_at' => now(),
            ]);

        Livewire::actingAs(
            $manager
        )
            ->test(
                'pages::leads.index'
            )
            ->assertSee(
                'Atualizar CRM / HubSpot'
            )
            ->call(
                'refreshAllHubSpotData'
            )
            ->assertSet(
                'commercialActionError',
                ''
            )
            ->assertSet(
                'commercialActionMessage',
                'Atualização CRM/HubSpot iniciada para 1 empresa. '
                .'Os dados serão atualizados em segundo plano.'
            );

        Queue::assertPushed(
            RefreshCompanyFromHubSpot::class,
            fn (
                RefreshCompanyFromHubSpot $job
            ): bool => $job->companyId
                === $company->id
        );
    }
);

it(
    'blocks a seller from running the global HubSpot refresh',
    function (): void {
        Queue::fake();

        $seller =
            User::factory()
                ->create([
                    'commercial_role' => User::ROLE_SELLER,
                ]);

        Livewire::actingAs(
            $seller
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'refreshAllHubSpotData'
            )
            ->assertStatus(
                403
            );

        Queue::assertNothingPushed();
    }
);
