<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;

it(
    'renders the commercial HubSpot status label',
    function (
        string $status,
        string $label,
    ) {
        $user =
            User::factory()->create([
                'email_verified_at' => now(),
            ]);

        $company =
            Company::query()->create([
                'cnpj_root' => '91234567',

                'corporate_name' => 'Lead Status Visual Teste',
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

        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_company_id' => 'company-visual',

                'hubspot_deal_id' => 'deal-visual',

                'pipeline_id' => 'default',

                'deal_stage_id' => 'appointmentscheduled',

                'work_status' => $status,

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

        $this
            ->actingAs(
                $user
            )
            ->get(
                route(
                    'leads.index'
                )
            )
            ->assertSuccessful()
            ->assertSee(
                'Lead Status Visual Teste'
            )
            ->assertSee(
                $label
            );
    }
)->with([
    'novo' => [
        'new',
        'Novo',
    ],

    'em contato' => [
        'contacting',
        'Em contato',
    ],

    'aguardando retorno' => [
        'waiting',
        'Aguardando retorno',
    ],

    'oportunidade futura' => [
        'future',
        'Oportunidade futura',
    ],

    'recusou' => [
        'refused',
        'Recusou',
    ],

    'convertido' => [
        'converted',
        'Convertido',
    ],

    'descartado' => [
        'discarded',
        'Descartado',
    ],
]);
