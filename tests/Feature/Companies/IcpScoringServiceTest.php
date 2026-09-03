<?php

use App\Services\CompanyService;
use App\Services\EstablishmentService;
use App\Services\IcpScoringService;
use App\Support\Cnpj;

it('classifies a strong exportcontrol icp as grade A', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Cooperativa Forte Agro',

            'share_capital' => 5000000,

            'size_code' => '05',

            'size_description' => 'Demais',

            'legal_nature_code' => '2143',

            'legal_nature_description' => 'Cooperativa',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'state' => 'MT',

            'municipality_name' => 'Sorriso',
        ],
        [
            [
                'code' => '4622200',

                'description' => 'Comércio atacadista de soja',

                'is_primary' => true,
            ],
        ]
    );

    $result = app(
        IcpScoringService::class
    )->calculate(
        $company
    );

    expect(
        $result->score
    )->toBe(90);

    expect(
        $result->grade
    )->toBe('A');

    expect(
        $result->label
    )->toBe(
        'Alta aderência'
    );

    expect(
        data_get(
            $result->factors,
            'cnae.points'
        )
    )->toBe(30);

    expect(
        data_get(
            $result->factors,
            'state.points'
        )
    )->toBe(15);
});

it('uses a secondary focus cnae with reduced score', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Empresa CNAE Secundário',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'state' => 'PR',
        ],
        [
            [
                'code' => '4711302',

                'description' => 'Atividade não prioritária',

                'is_primary' => true,
            ],
            [
                'code' => '0115600',

                'description' => 'Cultivo de soja',

                'is_primary' => false,
            ],
        ]
    );

    $result = app(
        IcpScoringService::class
    )->calculate(
        $company
    );

    expect(
        data_get(
            $result->factors,
            'cnae.points'
        )
    )->toBe(20);

    expect(
        data_get(
            $result->factors,
            'cnae.matched'
        )
    )->toBe(
        '0115600'
    );
});

it('gives regional relevance points when branches are known', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Grupo Regional Teste',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',
        ]
    );

    $base =
        $company->cnpj_root.'0002';

    $branchCnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    app(
        EstablishmentService::class
    )->createBranch(
        $company,
        [
            'cnpj' => $branchCnpj,

            'state' => 'GO',

            'municipality_name' => 'Rio Verde',
        ]
    );

    $result = app(
        IcpScoringService::class
    )->calculate(
        $company->fresh()
    );

    expect(
        data_get(
            $result->factors,
            'regional_relevance.points'
        )
    )->toBe(10);

    expect(
        data_get(
            $result->factors,
            'regional_relevance.establishments'
        )
    )->toBe(2);
});

it('classifies a low fit company as grade D', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'Pequena Empresa Teste',

            'share_capital' => 100000,

            'size_code' => '01',

            'legal_nature_description' => 'Sociedade Empresária Limitada',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'state' => 'PR',
        ],
        [
            [
                'code' => '6201501',

                'description' => 'Desenvolvimento de software',

                'is_primary' => true,
            ],
        ]
    );

    $result = app(
        IcpScoringService::class
    )->calculate(
        $company
    );

    expect(
        $result->score
    )->toBe(0);

    expect(
        $result->grade
    )->toBe('D');

    expect(
        $result->label
    )->toBe(
        'Baixa aderência'
    );
});

it('uses an active branch cnae and state when scoring the company group', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'GRUPO AGRO MULTIUF',

            'share_capital' => 5000000,

            'size_code' => '05',

            'legal_nature_code' => '2143',

            'legal_nature_description' => 'Cooperativa',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'state' => 'PR',
        ],
        [
            [
                'code' => '4692300',

                'description' => 'Comércio atacadista em geral',

                'is_primary' => true,
            ],
        ]
    );

    $branchBase =
        $company->cnpj_root.'0002';

    $branchCnpj =
        $branchBase
        .Cnpj::calculateCheckDigits(
            $branchBase
        );

    $branch = app(
        EstablishmentService::class
    )->createBranch(
        $company,
        [
            'cnpj' => $branchCnpj,

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'state' => 'MS',
        ]
    );

    app(
        EstablishmentService::class
    )->addCnae(
        $branch,
        [
            'code' => '4632001',

            'description' => 'Comércio atacadista de cereais',

            'is_primary' => true,
        ]
    );

    $result = app(
        IcpScoringService::class
    )->calculate(
        $company->fresh()
    );

    expect(
        data_get(
            $result->factors,
            'cnae.points'
        )
    )->toBe(30);

    expect(
        data_get(
            $result->factors,
            'cnae.establishment_cnpj'
        )
    )->toBe(
        $branchCnpj
    );

    expect(
        data_get(
            $result->factors,
            'state.points'
        )
    )->toBe(15);

    expect(
        data_get(
            $result->factors,
            'state.matched_states'
        )
    )->toContain('MS');

    expect(
        $result->score
    )->toBe(100);

    expect(
        $result->grade
    )->toBe('A');
});

it('ignores an inactive branch when calculating cnae and geography', function () {
    $company = app(
        CompanyService::class
    )->createOrUpdateFromEstablishment(
        [
            'corporate_name' => 'GRUPO COM FILIAL BAIXADA',
        ],
        [
            'cnpj' => '11.222.333/0001-81',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'state' => 'PR',
        ],
        [
            [
                'code' => '6201501',

                'description' => 'Desenvolvimento de software',

                'is_primary' => true,
            ],
        ]
    );

    $branchBase =
        $company->cnpj_root.'0002';

    $branchCnpj =
        $branchBase
        .Cnpj::calculateCheckDigits(
            $branchBase
        );

    $branch = app(
        EstablishmentService::class
    )->createBranch(
        $company,
        [
            'cnpj' => $branchCnpj,

            'registration_status_code' => '08',

            'registration_status' => 'BAIXADA',

            'state' => 'MS',
        ]
    );

    app(
        EstablishmentService::class
    )->addCnae(
        $branch,
        [
            'code' => '4632001',

            'description' => 'Comércio atacadista de cereais',

            'is_primary' => true,
        ]
    );

    $result = app(
        IcpScoringService::class
    )->calculate(
        $company->fresh()
    );

    expect(
        data_get(
            $result->factors,
            'cnae.points'
        )
    )->toBe(0);

    expect(
        data_get(
            $result->factors,
            'state.points'
        )
    )->toBe(0);

    expect(
        data_get(
            $result->factors,
            'regional_relevance.establishments'
        )
    )->toBe(1);
});
