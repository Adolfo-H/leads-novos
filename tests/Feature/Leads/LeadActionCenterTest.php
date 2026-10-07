<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Livewire\Livewire;

it('shows the commercial action center on the leads workspace', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '94949494',

                'corporate_name' => 'Lead Central Acao Teste',
            ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 82,

            'priority' => 'high',

            'label' => 'Prioridade alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $company
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $user->id,

            'status' => 'new',
        ]);

    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'action-company',

            'hubspot_deal_id' => 'action-deal',

            'work_status' => 'waiting',

            'open_task_count' => 1,

            'last_task_due_at' => now()
                ->subHour(),

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
        ->assertSee(
            'Foco do dia'
        )
        ->assertSee(
            'O que precisa da sua atenção agora'
        )
        ->assertSee(
            'Minha fila hoje'
        )
        ->assertSee(
            'Follow-ups atrasados'
        )
        ->assertSee(
            'Vencem hoje'
        )
        ->assertSee(
            'Reprospecção pronta'
        )
        ->assertSee(
            'Novos para abordar'
        )
        ->assertSee(
            'Aguardando sem prazo'
        )
        ->assertSee(
            'Contatos parados'
        );
});

it('uses the existing overdue filter from the action center', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyFollowUpView',
            'overdue'
        )
        ->assertSet(
            'workStatus',
            'waiting'
        )
        ->assertSet(
            'followUp',
            'overdue'
        );
});
