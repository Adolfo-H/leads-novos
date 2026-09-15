<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;

it('does not allow guests to access leads', function () {
    $this
        ->get(
            route('leads.index')
        )
        ->assertRedirect(
            route('login')
        );
});

it('shows only commercially eligible companies as leads', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $eligible =
        Company::query()->create([
            'cnpj_root' => '11112222',

            'corporate_name' => 'Lead Elegível Teste',
        ]);

    $eligible
        ->sdrScore()
        ->create([
            'score' => 88,

            'priority' => 'very_high',

            'label' => 'Prioridade muito alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $blocked =
        Company::query()->create([
            'cnpj_root' => '33334444',

            'corporate_name' => 'Cliente Bloqueado Teste',
        ]);

    $blocked
        ->sdrScore()
        ->create([
            'score' => 0,

            'priority' => 'blocked',

            'label' => 'Não priorizar',

            'is_eligible' => false,

            'is_provisional' => false,

            'blocked_reason' => 'Empresa já é cliente',

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Elegível Teste'
        )
        ->assertSee(
            '88/100'
        )
        ->assertSee(
            'Muito alta'
        )
        ->assertDontSee(
            'Cliente Bloqueado Teste'
        );
});

it('shows commercial context and direct actions in the lead queue', function () {
    config([
        'services.hubspot.portal_id' => '21358298',
    ]);

    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '55556666',

            'corporate_name' => 'Lead Comercial Completo',
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => 90,

            'grade' => 'A',

            'label' => 'Alta aderência',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'not_found',

            'external_id' => '31043441589',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $company
        ->exportIntelligence()
        ->create([
            'direct_status' => 'yes',

            'direct_confidence' => 90,

            'direct_confirmed' => false,

            'indirect_status' => 'uncertain',

            'indirect_confidence' => 0,

            'indirect_confirmed' => false,

            'trading_status' => 'uncertain',

            'trading_confidence' => 0,

            'trading_confirmed' => false,

            'research_status' => 'completed',

            'metadata' => [],
        ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 88,

            'priority' => 'very_high',

            'label' => 'Prioridade muito alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [
                [
                    'key' => 'icp',

                    'label' => 'Perfil ICP',

                    'points' => 30,

                    'max_points' => 30,

                    'detail' => 'ICP A',
                ],

                [
                    'key' => 'direct',

                    'label' => 'Exportação direta',

                    'points' => 23,

                    'max_points' => 25,

                    'detail' => 'Sim',
                ],
            ],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Comercial Completo'
        )
        ->assertSee(
            'Perfil ICP'
        )
        ->assertSee(
            '+30'
        )
        ->assertSee(
            'Exportação direta'
        )
        ->assertSee(
            'Atuação identificada'
        )
        ->assertSee(
            'Abrir dossiê'
        )
        ->assertSee(
            'https://app.hubspot.com/contacts/21358298/record/0-2/31043441589',
            false
        );
});

it('shows the commercial status received from HubSpot', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '91919191',

            'corporate_name' => 'Lead HubSpot Status Teste',
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

    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => '100001',

            'hubspot_deal_id' => '200001',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'work_status' => 'waiting',

            'open_task_count' => 1,

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
            'Lead HubSpot Status Teste'
        )
        ->assertSee(
            'Aguardando retorno'
        );
});
