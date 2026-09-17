<?php

use App\Models\Company;
use App\Models\CompanyLeadActivity;
use App\Models\User;
use App\Services\LeadOwnershipService;
use Livewire\Livewire;

function ownershipQueueCompany(
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

it('filters my leads, unassigned leads and another salesperson portfolio', function () {
    $me =
        User::factory()
            ->create([
                'name' => 'Adolfo Teste',

                'email_verified_at' => now(),
            ]);

    $otherUser =
        User::factory()
            ->create([
                'name' => 'Vendedor Outro',

                'email_verified_at' => now(),
            ]);

    $mine =
        ownershipQueueCompany(
            '98111111',
            'Lead Minha Carteira'
        );

    $other =
        ownershipQueueCompany(
            '98222222',
            'Lead Carteira Outro'
        );

    $free =
        ownershipQueueCompany(
            '98333333',
            'Lead Sem Responsavel'
        );

    $mine
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $me->id,

            'status' => 'new',
        ]);

    $other
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $otherUser->id,

            'status' => 'new',
        ]);

    Livewire::actingAs(
        $me
    )
        ->test(
            'pages::leads.index'
        )
        ->set(
            'owner',
            'mine'
        )
        ->assertSee(
            'Lead Minha Carteira'
        )
        ->assertDontSee(
            'Lead Carteira Outro'
        )
        ->assertDontSee(
            'Lead Sem Responsavel'
        )
        ->set(
            'owner',
            'unassigned'
        )
        ->assertSee(
            'Lead Sem Responsavel'
        )
        ->assertDontSee(
            'Lead Minha Carteira'
        )
        ->assertDontSee(
            'Lead Carteira Outro'
        )
        ->set(
            'owner',
            (string) $otherUser->id
        )
        ->assertSee(
            'Lead Carteira Outro'
        )
        ->assertDontSee(
            'Lead Minha Carteira'
        )
        ->assertDontSee(
            'Lead Sem Responsavel'
        );

    expect(
        $free
            ->leadWorkState()
            ->exists()
    )->toBeFalse();
});

it('assigns a salesperson directly from the lead queue', function () {
    $actor =
        User::factory()
            ->create([
                'name' => 'Gestor Teste',

                'email_verified_at' => now(),
            ]);

    $owner =
        User::factory()
            ->create([
                'name' => 'Vendedor Destino',

                'email_verified_at' => now(),
            ]);

    $company =
        ownershipQueueCompany(
            '98444444',
            'Lead Para Distribuir'
        );

    Livewire::actingAs(
        $actor
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'assignOwner',
            $company->id,
            (string) $owner->id
        )
        ->assertSet(
            'commercialActionMessage',
            'Responsável atualizado: Vendedor Destino.'
        );

    expect(
        $company
            ->leadWorkState()
            ->value(
                'assigned_user_id'
            )
    )->toBe(
        $owner->id
    );

    $activity =
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $company->id
            )
            ->where(
                'type',
                'owner_changed'
            )
            ->first();

    expect(
        $activity?->user_id
    )->toBe(
        $actor->id
    );
});

it('allows the authenticated salesperson to claim an unassigned lead', function () {
    $user =
        User::factory()
            ->create([
                'name' => 'Adolfo Carteira',

                'email_verified_at' => now(),
            ]);

    $company =
        ownershipQueueCompany(
            '98555555',
            'Lead Para Assumir'
        );

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::leads.index'
        )
        ->assertSee(
            'Assumir lead'
        )
        ->call(
            'claimLead',
            $company->id
        )
        ->assertSet(
            'commercialActionMessage',
            'Responsável atualizado: Adolfo Carteira.'
        );

    expect(
        $company
            ->leadWorkState()
            ->value(
                'assigned_user_id'
            )
    )->toBe(
        $user->id
    );
});

it('does not allow assignment to an unverified user', function () {
    $actor =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $unverified =
        User::factory()
            ->create([
                'email_verified_at' => null,
            ]);

    $company =
        ownershipQueueCompany(
            '98666666',
            'Lead Usuario Invalido'
        );

    Livewire::actingAs(
        $actor
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'assignOwner',
            $company->id,
            (string) $unverified->id
        )
        ->assertSet(
            'commercialActionError',
            'Usuário não encontrado.'
        );

    expect(
        $company
            ->leadWorkState()
            ->exists()
    )->toBeFalse();
});

it('shows ownership changes in the company commercial timeline', function () {
    $actor =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $owner =
        User::factory()
            ->create([
                'name' => 'Vendedor Timeline',

                'email_verified_at' => now(),
            ]);

    $company =
        ownershipQueueCompany(
            '98777777',
            'Lead Timeline Responsavel'
        );

    $this->actingAs(
        $actor
    );

    app(
        LeadOwnershipService::class
    )->assign(
        company: $company,

        owner: $owner,
    );

    $this
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Responsável alterado'
        )
        ->assertSee(
            'Sem responsável → Vendedor Timeline'
        )
        ->assertSee(
            'Responsável'
        );
});
