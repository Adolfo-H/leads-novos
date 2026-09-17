<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Livewire\Livewire;

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

it('keeps the original qualification visible after HubSpot creates an opportunity', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '49213747',
            'corporate_name' => 'Lead HubSpot Qualificado Teste',
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
            'status' => 'opportunity',
            'external_id' => '58449836434',
            'contacted_count' => 1,
            'associated_deals_count' => 1,
            'metadata' => [],
            'checked_at' => now(),
        ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 0,
            'priority' => 'blocked',
            'label' => 'Não priorizar',
            'is_eligible' => false,
            'is_provisional' => false,
            'blocked_reason' => 'Empresa possui oportunidade ativa',
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
        ]);

    CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_company_id' => '58449836434',
        'hubspot_deal_id' => '65038646698',
        'pipeline_id' => 'default',
        'deal_stage_id' => 'appointmentscheduled',
        'work_status' => 'contacting',
        'synced_at' => now(),

        'metadata' => [
            'qualification_snapshot' => [
                'score' => 60,
                'priority' => 'medium',
                'label' => 'Prioridade média',
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
            'Lead HubSpot Qualificado Teste'
        )
        ->assertSee(
            '60/100'
        )
        ->assertSee(
            'Oportunidade'
        )
        ->assertSee(
            'Média'
        )
        ->assertSee(
            'Em contato'
        );
});

it('counts unsynced eligible companies as new operational leads', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '78123456',
            'corporate_name' => 'Lead Novo Sem HubSpot',
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

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Novo Sem HubSpot'
        )
        ->assertSee(
            'Novo'
        );
});

it('filters unsynced eligible companies as new leads', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $newCompany =
        Company::query()->create([
            'cnpj_root' => '78123457',
            'corporate_name' => 'Lead Novo Filtrado',
        ]);

    $newCompany
        ->sdrScore()
        ->create([
            'score' => 70,
            'priority' => 'high',
            'label' => 'Prioridade alta',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
        ]);

    $contacting =
        Company::query()->create([
            'cnpj_root' => '78123458',
            'corporate_name' => 'Lead Em Contato Filtrado',
        ]);

    $contacting
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
            'company_id' => $contacting->id,

            'hubspot_company_id' => 'filter-company',

            'hubspot_deal_id' => 'filter-deal',

            'work_status' => 'contacting',

            'synced_at' => now(),

            'metadata' => [],
        ]);

    $component =
        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->set(
                'workStatus',
                'new'
            );

    $component
        ->assertSee(
            'Lead Novo Filtrado'
        )
        ->assertDontSee(
            'Lead Em Contato Filtrado'
        );
});

it('shows the next action for a lead waiting on follow up', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '78123459',
            'corporate_name' => 'Lead Follow Up Visual',
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

            'hubspot_company_id' => 'follow-company',

            'hubspot_deal_id' => 'follow-deal',

            'work_status' => 'waiting',

            'open_task_count' => 1,

            'last_task_due_at' => now()
                ->addDay()
                ->setTime(
                    10,
                    30
                ),

            'synced_at' => now(),

            'metadata' => [],
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Lead Follow Up Visual'
        )
        ->assertSee(
            'Próxima ação'
        );
});
