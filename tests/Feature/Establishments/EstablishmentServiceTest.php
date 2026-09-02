<?php

use App\Models\Company;
use App\Services\CompanyService;
use App\Services\EstablishmentService;
use App\Support\Cnpj;
use InvalidArgumentException;

function createCompanyForEstablishmentTests(): Company
{
    return app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Cooperativa Teste',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'registration_status' => 'ATIVA',
        ]
    );
}

it('creates a branch with the same cnpj root', function () {
    $company =
        createCompanyForEstablishmentTests();

    $service = app(
        EstablishmentService::class
    );

    /*
     * Utilizamos um CNPJ válido gerado
     * para a ordem 0002 da mesma raiz.
     */
    $base =
        $company->cnpj_root.'0002';

    $branchCnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $branch = $service->createBranch(
        $company,
        [
            'cnpj' => $branchCnpj,

            'fantasy_name' => 'Filial Cascavel',

            'state' => 'pr',

            'municipality_name' => 'Cascavel',

            'email' => 'FILIAL@EXEMPLO.COM.BR',
        ]
    );

    expect(
        $branch->type
    )->toBe('branch');

    expect(
        $branch->company_id
    )->toBe($company->id);

    expect(
        $branch->state
    )->toBe('PR');

    expect(
        $branch->email
    )->toBe(
        'filial@exemplo.com.br'
    );

    expect(
        $company
            ->establishments()
            ->count()
    )->toBe(2);
});

it('rejects a branch from another cnpj root', function () {
    $company =
        createCompanyForEstablishmentTests();

    $service = app(
        EstablishmentService::class
    );

    $base = '223334440002';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    expect(
        fn () => $service->createBranch(
            $company,
            [
                'cnpj' => $cnpj,
            ]
        )
    )->toThrow(
        InvalidArgumentException::class,
        'mesma raiz'
    );
});

it('does not allow a duplicated establishment cnpj', function () {
    $company =
        createCompanyForEstablishmentTests();

    $service = app(
        EstablishmentService::class
    );

    expect(
        fn () => $service->createBranch(
            $company,
            [
                'cnpj' => '11.222.333/0001-81',
            ]
        )
    )->toThrow(
        InvalidArgumentException::class,
        'já está cadastrado'
    );
});

it('adds primary and secondary cnaes to an establishment', function () {
    $company =
        createCompanyForEstablishmentTests();

    $matrix = $company
        ->establishments()
        ->where(
            'type',
            'matrix'
        )
        ->firstOrFail();

    $service = app(
        EstablishmentService::class
    );

    $service->addCnae(
        $matrix,
        [
            'code' => '4622200',

            'description' => 'Comércio atacadista de soja',

            'is_primary' => true,
        ]
    );

    $service->addCnae(
        $matrix,
        [
            'code' => '4632001',

            'description' => 'Comércio atacadista de cereais',

            'is_primary' => false,
        ]
    );

    $matrix->load('cnaes');

    expect(
        $matrix->cnaes
    )->toHaveCount(2);

    $primary = $matrix
        ->cnaes
        ->first(
            fn ($cnae) => (bool)
                $cnae->pivot->is_primary
        );

    expect(
        $primary?->code
    )->toBe('4622200');
});

it('keeps only one primary cnae per establishment', function () {
    $company =
        createCompanyForEstablishmentTests();

    $matrix = $company
        ->establishments()
        ->where(
            'type',
            'matrix'
        )
        ->firstOrFail();

    $service = app(
        EstablishmentService::class
    );

    $service->addCnae(
        $matrix,
        [
            'code' => '4622200',

            'description' => 'Soja',

            'is_primary' => true,
        ]
    );

    $service->addCnae(
        $matrix,
        [
            'code' => '0115600',

            'description' => 'Cultivo de soja',

            'is_primary' => true,
        ]
    );

    $matrix->load('cnaes');

    expect(
        $matrix
            ->cnaes
            ->filter(
                fn ($cnae) => (bool)
                    $cnae->pivot->is_primary
            )
    )->toHaveCount(1);

    $primary = $matrix
        ->cnaes
        ->first(
            fn ($cnae) => (bool)
                $cnae->pivot->is_primary
        );

    expect(
        $primary?->code
    )->toBe('0115600');
});

it('removes a cnae from an establishment', function () {
    $company =
        createCompanyForEstablishmentTests();

    $matrix = $company
        ->establishments()
        ->where(
            'type',
            'matrix'
        )
        ->firstOrFail();

    $service = app(
        EstablishmentService::class
    );

    $cnae = $service->addCnae(
        $matrix,
        [
            'code' => '4622200',

            'description' => 'Soja',

            'is_primary' => false,
        ]
    );

    $service->removeCnae(
        $matrix,
        $cnae
    );

    expect(
        $matrix
            ->cnaes()
            ->count()
    )->toBe(0);
});
