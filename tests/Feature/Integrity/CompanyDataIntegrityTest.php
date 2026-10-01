<?php

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use App\Support\Cnpj;
use Livewire\Livewire;

function integrityCompanyService(): CompanyService
{
    return app(
        CompanyService::class
    );
}

it('preserves existing company data when a partial enrichment omits fields', function () {
    $service =
        integrityCompanyService();

    $company =
        $service
            ->createOrUpdateFromEstablishment(
                [
                    'corporate_name' => 'Empresa Integridade Ltda',

                    'share_capital' => 2500000,

                    'size_code' => '05',

                    'legal_nature_code' => '2062',
                ],
                [
                    'cnpj' => '11.222.333/0001-81',

                    'type' => 'matrix',

                    'state' => 'PR',

                    'municipality_name' => 'Toledo',
                ],
            );

    /*
     * Simula uma segunda fonte parcial.
     *
     * Ela conhece a empresa, mas não
     * devolveu todos os campos.
     */
    $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Empresa Integridade Ltda',

                'share_capital' => null,

                'size_code' => null,

                'legal_nature_code' => null,

                'source' => 'fallback-test',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',

                'state' => null,

                'municipality_name' => null,
            ],
        );

    $company->refresh();

    expect(
        $company->share_capital
    )->toBe(
        '2500000.00'
    );

    expect(
        $company->size_code
    )->toBe(
        '05'
    );

    expect(
        $company
            ->legal_nature_code
    )->toBe(
        '2062'
    );

    $matrix =
        $company
            ->matrix()
            ->firstOrFail();

    expect(
        $matrix->state
    )->toBe(
        'PR'
    );

    expect(
        $matrix
            ->municipality_name
    )->toBe(
        'Toledo'
    );
});

it('replaces authoritative cnaes and keeps only one primary', function () {
    $service =
        integrityCompanyService();

    $company =
        $service
            ->createOrUpdateFromEstablishment(
                [
                    'corporate_name' => 'Empresa CNAE Sincronizado',
                ],
                [
                    'cnpj' => '11.222.333/0001-81',

                    'type' => 'matrix',
                ],
                [
                    [
                        'code' => '4622200',

                        'description' => 'Comércio atacadista de soja',

                        'is_primary' => true,
                    ],
                    [
                        'code' => '4632001',

                        'description' => 'Comércio atacadista de cereais',

                        'is_primary' => false,
                    ],
                ],
                replaceCnaes: true,
            );

    /*
     * Novo retrato autoritativo:
     *
     * 4622200 deixou de existir.
     * 4632001 virou principal.
     * 0115600 entrou.
     *
     * A fonte propositalmente marca
     * dois principais para garantir
     * que o serviço normalize isso.
     */
    $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Empresa CNAE Sincronizado',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',
            ],
            [
                [
                    'code' => '4632001',

                    'description' => 'Comércio atacadista de cereais',

                    'is_primary' => true,
                ],
                [
                    'code' => '0115600',

                    'description' => 'Cultivo de soja',

                    'is_primary' => true,
                ],
            ],
            replaceCnaes: true,
        );

    $matrix =
        $company
            ->refresh()
            ->matrix()
            ->firstOrFail();

    $matrix
        ->load(
            'cnaes'
        );

    expect(
        $matrix
            ->cnaes
            ->pluck(
                'code'
            )
            ->sort()
            ->values()
            ->all()
    )->toBe([
        '0115600',
        '4632001',
    ]);

    $primary =
        $matrix
            ->cnaes
            ->filter(
                fn ($cnae): bool => (bool)
                        $cnae
                            ->pivot
                            ->is_primary
            );

    expect(
        $primary
    )->toHaveCount(
        1
    );

    expect(
        $primary
            ->first()
            ?->code
    )->toBe(
        '4632001'
    );
});

it('does not allow new company screen to create another establishment for an existing root', function () {
    $service =
        integrityCompanyService();

    $company =
        $service
            ->createOrUpdateFromEstablishment(
                [
                    'corporate_name' => 'Grupo Existente Ltda',

                    'share_capital' => 5000000,
                ],
                [
                    'cnpj' => '11.222.333/0001-81',

                    'type' => 'matrix',
                ],
            );

    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $base =
        '112223330002';

    $branchCnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::companies.create'
        )
        ->set(
            'cnpj',
            $branchCnpj
        )
        ->set(
            'corporateName',
            'Nome Que Não Deve Sobrescrever'
        )
        ->set(
            'type',
            'branch'
        )
        ->call(
            'save'
        )
        ->assertHasErrors([
            'cnpj',
        ]);

    $company->refresh();

    expect(
        Company::query()
            ->count()
    )->toBe(
        1
    );

    expect(
        $company
            ->establishments()
            ->count()
    )->toBe(
        1
    );

    expect(
        $company
            ->corporate_name
    )->toBe(
        'Grupo Existente Ltda'
    );

    expect(
        $company
            ->share_capital
    )->toBe(
        '5000000.00'
    );
});
