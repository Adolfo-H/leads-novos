<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Livewire\Livewire;

it('renders the lead list with semantic commercial columns', function () {
    $view =
        file_get_contents(
            resource_path(
                'views/pages/leads/⚡index.blade.php'
            )
        );

    expect(
        $view
    )
        ->toContain(
            'rf-col-company'
        )
        ->toContain(
            'rf-col-score'
        )
        ->toContain(
            'rf-col-commercial'
        )
        ->toContain(
            'rf-col-crm'
        )
        ->toContain(
            'rf-col-followup'
        )
        ->toContain(
            'rf-col-owner'
        )
        ->toContain(
            'rf-col-action'
        )
        ->toContain(
            'rf-lead-attention'
        )
        ->toContain(
            'rf-next-action-main'
        );
});

it('highlights an overdue lead with its next commercial action', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '95959595',

                'corporate_name' => 'Lead Urgente UX Teste',
            ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 88,

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

            'hubspot_company_id' => 'ux-company',

            'hubspot_deal_id' => 'ux-deal',

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
            'Lead Urgente UX Teste'
        )
        ->assertSee(
            'Atrasado'
        )
        ->assertSee(
            'Retomar contato'
        )
        ->assertSeeHtml(
            'rf-row-overdue'
        );
});

it('marks a lead due today without classifying it as overdue', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '96969696',

                'corporate_name' => 'Lead Hoje UX Teste',
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

    $company
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $user->id,

            'status' => 'new',
        ]);

    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'ux-company-today',

            'hubspot_deal_id' => 'ux-deal-today',

            'work_status' => 'waiting',

            'open_task_count' => 1,

            'last_task_due_at' => now()
                ->addHour(),

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
            'Lead Hoje UX Teste'
        )
        ->assertSee(
            'Ação hoje'
        )
        ->assertSeeHtml(
            'rf-row-due-today'
        );
});
