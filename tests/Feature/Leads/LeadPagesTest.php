<?php

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
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

it('tracks the commercial work status of a lead', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '90909090',

            'corporate_name' => 'Lead Acompanhamento Teste',
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

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::leads.index'
        )
        ->assertSee(
            'Lead Acompanhamento Teste'
        )
        ->call(
            'updateWorkStatus',
            $company->id,
            'contacting'
        )
        ->assertHasNoErrors();

    $state =
        $company
            ->leadWorkState()
            ->firstOrFail();

    expect(
        $state->status
    )->toBe(
        'contacting'
    );

    expect(
        $state->assigned_user_id
    )->toBe(
        $user->id
    );

    expect(
        $state->last_action_at
    )->not->toBeNull();
});

it('stores a commercial note and next follow up date', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '78787878',

            'corporate_name' => 'Lead Follow Up Teste',
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
        $user
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'saveWorkNote',
            $company->id,
            'Retornar após validação do fiscal.'
        )
        ->call(
            'saveNextAction',
            $company->id,
            '2026-09-18 09:30'
        )
        ->assertHasNoErrors();

    $state =
        $company
            ->leadWorkState()
            ->firstOrFail();

    expect(
        $state->note
    )->toBe(
        'Retornar após validação do fiscal.'
    );

    expect(
        $state
            ->next_action_at
            ?->format(
                'Y-m-d H:i'
            )
    )->toBe(
        '2026-09-18 09:30'
    );

    expect(
        $state->assigned_user_id
    )->toBe(
        $user->id
    );
});

it('prioritizes overdue follow ups in the SDR queue', function () {
    $this->travelTo(
        CarbonImmutable::parse(
            '2026-09-14 15:00:00'
        )
    );

    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $newLead =
        Company::query()->create([
            'cnpj_root' => '12121212',

            'corporate_name' => 'Lead Novo Score Alto',
        ]);

    $newLead
        ->sdrScore()
        ->create([
            'score' => 95,

            'priority' => 'very_high',

            'label' => 'Prioridade muito alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $overdue =
        Company::query()->create([
            'cnpj_root' => '34343434',

            'corporate_name' => 'Lead Retorno Vencido',
        ]);

    $overdue
        ->sdrScore()
        ->create([
            'score' => 60,

            'priority' => 'medium',

            'label' => 'Prioridade média',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    $overdue
        ->leadWorkState()
        ->create([
            'status' => 'waiting',

            'assigned_user_id' => $user->id,

            'last_action_at' => now()
                ->subDay(),

            'next_action_at' => now()
                ->subHour(),
        ]);

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Lead Retorno Vencido',
            'Lead Novo Score Alto',
        ])
        ->assertSee(
            'Retornos vencidos'
        )
        ->assertSee(
            'Ainda hoje'
        );

    $this->travelBack();
});
