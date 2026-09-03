<?php

use App\Contracts\CnpjGroupDataProvider;
use App\Services\CompanyGroupEnrichmentService;
use App\Support\Cnpj;

it('creates the complete company group from a provider', function () {
    $matrixBase =
        '112223330001';

    $branch2Base =
        '112223330002';

    $branch3Base =
        '112223330003';

    $matrixCnpj =
        $matrixBase
        .Cnpj::calculateCheckDigits(
            $matrixBase
        );

    $branch2Cnpj =
        $branch2Base
        .Cnpj::calculateCheckDigits(
            $branch2Base
        );

    $branch3Cnpj =
        $branch3Base
        .Cnpj::calculateCheckDigits(
            $branch3Base
        );

    $provider =
        new class($matrixCnpj, $branch2Cnpj, $branch3Cnpj) implements CnpjGroupDataProvider
        {
            public function __construct(
                private readonly string $matrix,
                private readonly string $branch2,
                private readonly string $branch3,
            ) {}

            public function name(): string
            {
                return 'fake-receita';
            }

            public function lookupRoot(
                string $cnpjRoot
            ): array {
                return [
                    'company' => [
                        'corporate_name' => 'Cooperativa Agro Teste',

                        'legal_nature_code' => '2143',

                        'legal_nature_description' => 'Cooperativa',

                        'share_capital' => 5000000,

                        'size_code' => '05',

                        'size_description' => 'Demais',

                        'source' => $this->name(),
                    ],

                    'establishments' => [
                        [
                            'establishment' => [
                                'cnpj' => $this->matrix,

                                'type' => 'matrix',

                                'fantasy_name' => 'AGRO TESTE',

                                'registration_status' => 'ATIVA',

                                'state' => 'MT',

                                'municipality_name' => 'Sorriso',

                                'source' => $this->name(),
                            ],

                            'cnaes' => [
                                [
                                    'code' => '4622200',

                                    'description' => 'Comércio atacadista de soja',

                                    'is_primary' => true,
                                ],
                            ],
                        ],

                        [
                            'establishment' => [
                                'cnpj' => $this->branch2,

                                'type' => 'branch',

                                'registration_status' => 'ATIVA',

                                'state' => 'GO',

                                'municipality_name' => 'Rio Verde',

                                'source' => $this->name(),
                            ],

                            'cnaes' => [],
                        ],

                        [
                            'establishment' => [
                                'cnpj' => $this->branch3,

                                'type' => 'branch',

                                'registration_status' => 'ATIVA',

                                'state' => 'MS',

                                'municipality_name' => 'Dourados',

                                'source' => $this->name(),
                            ],

                            'cnaes' => [],
                        ],
                    ],
                ];
            }
        };

    $company = app(
        CompanyGroupEnrichmentService::class
    )->enrich(
        $matrixCnpj,
        $provider,
    );

    expect(
        $company->corporate_name
    )->toBe(
        'Cooperativa Agro Teste'
    );

    expect(
        $company
            ->establishments
            ->count()
    )->toBe(3);

    expect(
        $company
            ->establishments
            ->where(
                'type',
                'branch'
            )
            ->count()
    )->toBe(2);

    expect(
        $company
            ->establishments
            ->firstWhere(
                'type',
                'matrix'
            )
            ?->cnaes
            ->first()
            ?->code
    )->toBe(
        '4622200'
    );

    expect(
        $company
            ->icpScore
            ?->factors[
                'regional_relevance'
            ][
                'points'
            ]
    )->toBe(10);
});

it('rejects an establishment from another cnpj root', function () {
    $matrixBase =
        '112223330001';

    $matrixCnpj =
        $matrixBase
        .Cnpj::calculateCheckDigits(
            $matrixBase
        );

    $foreignBase =
        '998887770001';

    $foreignCnpj =
        $foreignBase
        .Cnpj::calculateCheckDigits(
            $foreignBase
        );

    $provider =
        new class($foreignCnpj) implements CnpjGroupDataProvider
        {
            public function __construct(
                private readonly string $foreignCnpj,
            ) {}

            public function name(): string
            {
                return 'invalid-provider';
            }

            public function lookupRoot(
                string $cnpjRoot
            ): array {
                return [
                    'company' => [
                        'corporate_name' => 'Empresa Teste',
                    ],

                    'establishments' => [
                        [
                            'establishment' => [
                                'cnpj' => $this
                                    ->foreignCnpj,

                                'type' => 'branch',
                            ],

                            'cnaes' => [],
                        ],
                    ],
                ];
            }
        };

    app(
        CompanyGroupEnrichmentService::class
    )->enrich(
        $matrixCnpj,
        $provider,
    );
})->throws(
    InvalidArgumentException::class,
    'A fonte retornou um estabelecimento pertencente a outra raiz de CNPJ.'
);
