<?php

use App\Models\Company;
use App\Models\User;

it('shows the future reprospecting date for a recent prospect', function () {
    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);

    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '12121212',

            'corporate_name' => 'Empresa Reprospecção Recente',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'prospected',

            'contacted_count' => 3,

            'associated_deals_count' => 0,

            'last_contacted_at' => now()
                ->subDays(30),

            'metadata' => [
                'deals' => [],
            ],

            'checked_at' => now(),
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Aguardando reprospecção'
        )
        ->assertSee(
            'Reprospecção a partir de'
        )
        ->assertSee(
            'Última atividade considerada'
        );
});

it('shows old prospects as released for reprospecting', function () {
    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);

    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '34343434',

            'corporate_name' => 'Empresa Reprospecção Antiga',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'prospected',

            'contacted_count' => 3,

            'associated_deals_count' => 0,

            'last_contacted_at' => now()
                ->subDays(220),

            'metadata' => [
                'deals' => [],
            ],

            'checked_at' => now(),
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Reprospecção liberada'
        )
        ->assertSee(
            'Liberada agora'
        );
});
