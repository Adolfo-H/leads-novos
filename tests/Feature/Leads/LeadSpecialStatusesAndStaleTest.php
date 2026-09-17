<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function specialStatusCompany(
    string $root,
    string $name,
): Company {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => $root,

                'corporate_name' => $name,
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

    return $company;
}

function specialStatusLead(
    Company $company,
    string $status,
    ?DateTimeInterface $lastActivityAt = null,
): CompanyHubSpotLead {
    return CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-'
                .$company->cnpj_root,

            'hubspot_deal_id' => 'deal-'
                .$company->cnpj_root,

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'work_status' => $status,

            'last_activity_at' => $lastActivityAt,

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);
}

it('filters future opportunities separately', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $future =
        specialStatusCompany(
            '96666661',
            'Empresa Oportunidade Futura'
        );

    specialStatusLead(
        $future,
        'future'
    );

    $refused =
        specialStatusCompany(
            '96666662',
            'Empresa Recusou'
        );

    specialStatusLead(
        $refused,
        'refused'
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyQuickView',
            'future'
        )
        ->assertSee(
            'Empresa Oportunidade Futura'
        )
        ->assertDontSee(
            'Empresa Recusou'
        );
});

it('filters refused opportunities separately', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $future =
        specialStatusCompany(
            '96666663',
            'Empresa Futuro Dois'
        );

    specialStatusLead(
        $future,
        'future'
    );

    $refused =
        specialStatusCompany(
            '96666664',
            'Empresa Recusada Dois'
        );

    specialStatusLead(
        $refused,
        'refused'
    );

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyQuickView',
            'refused'
        )
        ->assertSee(
            'Empresa Recusada Dois'
        )
        ->assertDontSee(
            'Empresa Futuro Dois'
        );
});

it('identifies contacting leads that have been stale for seven days', function () {
    Carbon::setTestNow(
        '2026-09-17 15:00:00'
    );

    try {
        config([
            'prospector.sdr.stale_after_days' => 7,
        ]);

        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        $stale =
            specialStatusCompany(
                '96666665',
                'Empresa Parada'
            );

        specialStatusLead(
            $stale,
            'contacting',
            now()->subDays(8)
        );

        $fresh =
            specialStatusCompany(
                '96666666',
                'Empresa Recente'
            );

        specialStatusLead(
            $fresh,
            'contacting',
            now()->subDays(2)
        );

        $component =
            Livewire::actingAs($user)
                ->test(
                    'pages::leads.index'
                );

        expect(
            $component
                ->instance()
                ->staleCount
        )->toBe(1);

        $component
            ->call(
                'applyStaleView'
            )
            ->assertSet(
                'staleOnly',
                true
            )
            ->assertSee(
                'Empresa Parada'
            )
            ->assertDontSee(
                'Empresa Recente'
            );
    } finally {
        Carbon::setTestNow();
    }
});
