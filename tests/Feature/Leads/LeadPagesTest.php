<?php

use App\Models\Company;
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
