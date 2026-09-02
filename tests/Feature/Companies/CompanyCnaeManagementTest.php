<?php

use App\Models\User;
use App\Services\CompanyService;
use Livewire\Livewire;

function companyForCnaeManagementTests()
{
    return app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Empresa CNAE Teste',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'state' => 'PR',

            'municipality_name' => 'Toledo',
        ]
    );
}

it('adds a primary cnae from the company dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForCnaeManagementTests();

    Livewire::actingAs($user)
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'newCnaeCode',
            '4622200'
        )
        ->set(
            'newCnaeDescription',
            'Comércio atacadista de soja'
        )
        ->set(
            'newCnaePrimary',
            true
        )
        ->call('addCnae')
        ->assertHasNoErrors();

    $matrix = $company
        ->establishments()
        ->where('type', 'matrix')
        ->firstOrFail();

    $matrix->load('cnaes');

    expect(
        $matrix->cnaes
    )->toHaveCount(1);

    expect(
        $matrix
            ->cnaes
            ->first()
            ->code
    )->toBe('4622200');

    expect(
        (bool) $matrix
            ->cnaes
            ->first()
            ->pivot
            ->is_primary
    )->toBeTrue();
});

it('adds a secondary cnae from the company dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForCnaeManagementTests();

    Livewire::actingAs($user)
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'newCnaeCode',
            '4632001'
        )
        ->set(
            'newCnaeDescription',
            'Comércio atacadista de cereais'
        )
        ->set(
            'newCnaePrimary',
            false
        )
        ->call('addCnae')
        ->assertHasNoErrors();

    $matrix = $company
        ->establishments()
        ->where('type', 'matrix')
        ->firstOrFail();

    $matrix->load('cnaes');

    $cnae = $matrix
        ->cnaes
        ->firstOrFail();

    expect(
        $cnae->code
    )->toBe('4632001');

    expect(
        (bool) $cnae
            ->pivot
            ->is_primary
    )->toBeFalse();
});

it('changes the primary cnae from the company dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForCnaeManagementTests();

    $component = Livewire::actingAs($user)
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        );

    $component
        ->set(
            'newCnaeCode',
            '4622200'
        )
        ->set(
            'newCnaeDescription',
            'Soja'
        )
        ->set(
            'newCnaePrimary',
            true
        )
        ->call('addCnae')
        ->assertHasNoErrors();

    $component
        ->set(
            'newCnaeCode',
            '0115600'
        )
        ->set(
            'newCnaeDescription',
            'Cultivo de soja'
        )
        ->set(
            'newCnaePrimary',
            false
        )
        ->call('addCnae')
        ->assertHasNoErrors();

    $matrix = $company
        ->establishments()
        ->where('type', 'matrix')
        ->firstOrFail();

    $secondary = $matrix
        ->cnaes()
        ->where(
            'code',
            '0115600'
        )
        ->firstOrFail();

    $component
        ->call(
            'makeCnaePrimary',
            $secondary->id
        )
        ->assertHasNoErrors();

    $matrix->load('cnaes');

    $primary = $matrix
        ->cnaes
        ->first(
            fn ($cnae) => (bool) $cnae
                ->pivot
                ->is_primary
        );

    expect(
        $primary?->code
    )->toBe('0115600');

    expect(
        $matrix
            ->cnaes
            ->filter(
                fn ($cnae) => (bool) $cnae
                    ->pivot
                    ->is_primary
            )
    )->toHaveCount(1);
});

it('removes a cnae from the company dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForCnaeManagementTests();

    $component = Livewire::actingAs($user)
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'newCnaeCode',
            '4622200'
        )
        ->set(
            'newCnaeDescription',
            'Soja'
        )
        ->call('addCnae')
        ->assertHasNoErrors();

    $matrix = $company
        ->establishments()
        ->where('type', 'matrix')
        ->firstOrFail();

    $cnae = $matrix
        ->cnaes()
        ->firstOrFail();

    $component
        ->call(
            'removeCnae',
            $cnae->id
        )
        ->assertHasNoErrors();

    expect(
        $matrix
            ->cnaes()
            ->count()
    )->toBe(0);
});

it('rejects an invalid cnae code from the company dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        companyForCnaeManagementTests();

    Livewire::actingAs($user)
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'newCnaeCode',
            '123'
        )
        ->call('addCnae')
        ->assertHasErrors([
            'newCnaeCode',
        ]);

    $matrix = $company
        ->establishments()
        ->where('type', 'matrix')
        ->firstOrFail();

    expect(
        $matrix
            ->cnaes()
            ->count()
    )->toBe(0);
});
