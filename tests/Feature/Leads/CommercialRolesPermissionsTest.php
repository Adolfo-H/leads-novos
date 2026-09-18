<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use App\Services\CommercialRoleService;
use Livewire\Livewire;

function commercialRoleCompany(
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

            'label' => 'Perfil comercial',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    return $company;
}

function commercialRoleAssign(
    Company $company,
    User $user,
): void {
    $company
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $user->id,

            'status' => 'new',
        ]);
}

it('uses manager as the default factory profile', function () {
    $user =
        User::factory()
            ->create();

    expect(
        $user->commercial_role
    )->toBe(
        User::ROLE_MANAGER
    );

    expect(
        $user->isCommercialManager()
    )->toBeTrue();
});

it('blocks management modules for a seller', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'leads.management'
            )
        )
        ->assertForbidden();

    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'prospecting.index'
            )
        )
        ->assertForbidden();

    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'imports.index'
            )
        )
        ->assertForbidden();

    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'companies.index'
            )
        )
        ->assertForbidden();
});

it('shows only the salesperson own portfolio', function () {
    $seller =
        User::factory()
            ->create([
                'name' => 'Vendedor Restrito',

                'commercial_role' => User::ROLE_SELLER,
            ]);

    $otherSeller =
        User::factory()
            ->create([
                'name' => 'Outro Vendedor',

                'commercial_role' => User::ROLE_SELLER,
            ]);

    $mine =
        commercialRoleCompany(
            '71111111',
            'Empresa Minha Carteira'
        );

    commercialRoleAssign(
        $mine,
        $seller
    );

    $other =
        commercialRoleCompany(
            '72222222',
            'Empresa Outra Carteira'
        );

    commercialRoleAssign(
        $other,
        $otherSeller
    );

    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'leads.index'
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Empresa Minha Carteira'
        )
        ->assertDontSee(
            'Empresa Outra Carteira'
        );

    /*
     * Mesmo alterando owner na URL,
     * a restrição base do vendedor
     * continua valendo.
     */
    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'leads.index',
                [
                    'owner' => (string) $otherSeller->id,
                ]
            )
        )
        ->assertSuccessful()
        ->assertDontSee(
            'Empresa Outra Carteira'
        );
});

it('allows a seller to open only an assigned company dossier', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $mine =
        commercialRoleCompany(
            '73333331',
            'Dossie Permitido'
        );

    commercialRoleAssign(
        $mine,
        $seller
    );

    $other =
        commercialRoleCompany(
            '73333332',
            'Dossie Bloqueado'
        );

    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'companies.show',
                $mine
            )
        )
        ->assertSuccessful();

    $this
        ->actingAs(
            $seller
        )
        ->get(
            route(
                'companies.show',
                $other
            )
        )
        ->assertForbidden();
});

it('does not allow a seller to transfer ownership', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,
            ]);

    $company =
        commercialRoleCompany(
            '74444444',
            'Lead Nao Transferivel'
        );

    commercialRoleAssign(
        $company,
        $seller
    );

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'assignOwner',
            $company->id,
            (string) $manager->id
        )
        ->assertStatus(
            403
        );

    expect(
        $company
            ->leadWorkState()
            ->value(
                'assigned_user_id'
            )
    )->toBe(
        $seller->id
    );
});

it('allows a manager to change another user commercial role', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,
            ]);

    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $updated =
        app(
            CommercialRoleService::class
        )->changeRole(
            actor: $manager,

            target: $seller,

            role: User::ROLE_MANAGER,
        );

    expect(
        $updated->commercial_role
    )->toBe(
        User::ROLE_MANAGER
    );
});

it('prevents a manager from removing their own manager access', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,
            ]);

    expect(
        fn () => app(
            CommercialRoleService::class
        )->changeRole(
            actor: $manager,

            target: $manager,

            role: User::ROLE_SELLER,
        )
    )->toThrow(
        DomainException::class,
        'Você não pode remover seu próprio perfil de gestor.'
    );
});

it('blocks direct livewire access to management for sellers', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::leads.management'
        )
        ->assertStatus(
            403
        );
});

it('does not allow a seller to claim an unassigned lead', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        commercialRoleCompany(
            '75555551',
            'Lead Sem Dono Protegido'
        );

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'claimLead',
            $company->id
        )
        ->assertStatus(
            403
        );

    expect(
        $company
            ->leadWorkState()
            ->exists()
    )->toBeFalse();
});

it('does not allow a seller to refresh another salesperson hubspot lead', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $otherSeller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        commercialRoleCompany(
            '75555552',
            'HubSpot Outra Carteira'
        );

    commercialRoleAssign(
        $company,
        $otherSeller
    );

    $lead =
        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_company_id' => 'company-protected',

                'hubspot_deal_id' => 'deal-protected',

                'pipeline_id' => 'default',

                'deal_stage_id' => 'appointmentscheduled',

                'work_status' => 'new',

                'open_task_count' => 0,

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'refreshHubSpotStatus',
            $lead->id
        )
        ->assertStatus(
            403
        );
});

it('allows direct management component access for a manager', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,
            ]);

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::leads.management'
        )
        ->assertSee(
            'Gestão Comercial'
        );
});
