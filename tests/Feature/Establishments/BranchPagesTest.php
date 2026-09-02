<?php

use App\Models\User;
use App\Services\CompanyService;
use App\Support\Cnpj;
use Livewire\Livewire;

function companyForBranchPageTests()
{
    return app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Empresa Filiais Teste',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'state' => 'PR',

            'municipality_name' => 'Toledo',
        ]
    );
}

it('renders the branch creation page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForBranchPageTests();

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.branches.create',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Adicionar filial'
        )
        ->assertSee(
            $company->corporate_name
        );
});

it('creates a branch from the livewire page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForBranchPageTests();

    $base =
        $company->cnpj_root.'0002';

    $branchCnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    Livewire::actingAs($user)
        ->test(
            'pages::companies.branches.create',
            [
                'company' => $company,
            ]
        )
        ->set(
            'cnpj',
            $branchCnpj
        )
        ->set(
            'fantasyName',
            'Filial Cascavel'
        )
        ->set(
            'state',
            'pr'
        )
        ->set(
            'municipalityName',
            'Cascavel'
        )
        ->set(
            'email',
            'FILIAL@TESTE.COM.BR'
        )
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(
            route(
                'companies.show',
                $company
            )
        );

    $branch = $company
        ->establishments()
        ->where(
            'type',
            'branch'
        )
        ->firstOrFail();

    expect(
        $branch->cnpj
    )->toBe($branchCnpj);

    expect(
        $branch->fantasy_name
    )->toBe('Filial Cascavel');

    expect(
        $branch->state
    )->toBe('PR');

    expect(
        $branch->email
    )->toBe(
        'filial@teste.com.br'
    );
});

it('rejects a branch from another cnpj root in the page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForBranchPageTests();

    $base = '998887770002';

    $otherCnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    Livewire::actingAs($user)
        ->test(
            'pages::companies.branches.create',
            [
                'company' => $company,
            ]
        )
        ->set(
            'cnpj',
            $otherCnpj
        )
        ->call('save')
        ->assertHasErrors([
            'cnpj',
        ]);

    expect(
        $company
            ->establishments()
            ->where(
                'type',
                'branch'
            )
            ->count()
    )->toBe(0);
});

it('does not allow guests to open the branch creation page', function () {
    $company =
        companyForBranchPageTests();

    $this
        ->get(
            route(
                'companies.branches.create',
                $company
            )
        )
        ->assertRedirect(
            route('login')
        );
});
