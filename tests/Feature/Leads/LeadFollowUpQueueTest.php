<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Livewire\Livewire;

function followUpCompany(
    string $root,
    string $name,
): Company {
    $company =
        Company::query()->create([
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

function followUpLead(
    Company $company,
    ?DateTimeInterface $dueAt,
): CompanyHubSpotLead {
    return CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,
            'hubspot_company_id' => 'company-'.$company->cnpj_root,
            'hubspot_deal_id' => 'deal-'.$company->cnpj_root,
            'work_status' => 'waiting',
            'open_task_count' => 1,
            'last_task_due_at' => $dueAt,
            'synced_at' => now(),
            'metadata' => [],
        ]);
}

it('filters overdue follow ups', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $overdue =
        followUpCompany(
            '85111111',
            'Follow Up Atrasado'
        );

    followUpLead(
        $overdue,
        now()->subDay()
    );

    $future =
        followUpCompany(
            '85222222',
            'Follow Up Futuro'
        );

    followUpLead(
        $future,
        now()->addDays(2)
    );

    Livewire::actingAs($user)
        ->test('pages::leads.index')
        ->call(
            'applyFollowUpView',
            'overdue'
        )
        ->assertSee(
            'Follow Up Atrasado'
        )
        ->assertDontSee(
            'Follow Up Futuro'
        );
});

it('filters follow ups due today', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $today =
        followUpCompany(
            '85333333',
            'Follow Up Hoje'
        );

    followUpLead(
        $today,
        now()->addHour()
    );

    $future =
        followUpCompany(
            '85444444',
            'Follow Up Outro Dia'
        );

    followUpLead(
        $future,
        now()->addDays(3)
    );

    Livewire::actingAs($user)
        ->test('pages::leads.index')
        ->call(
            'applyFollowUpView',
            'today'
        )
        ->assertSee(
            'Follow Up Hoje'
        )
        ->assertDontSee(
            'Follow Up Outro Dia'
        );
});

it('shows overdue task visually', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        followUpCompany(
            '85555555',
            'Follow Up Visual Atrasado'
        );

    followUpLead(
        $company,
        now()->subHours(2)
    );

    $this
        ->actingAs($user)
        ->get(
            route('leads.index')
        )
        ->assertSuccessful()
        ->assertSee(
            'Follow Up Visual Atrasado'
        )
        ->assertSee(
            'Atrasado'
        );
});
