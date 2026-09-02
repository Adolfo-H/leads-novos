<?php

use App\Services\CnpjEnrichmentService;

it('enriches a company using brasilapi data', function () {
    $company = app(
        CnpjEnrichmentService::class
    )->enrich(
        '11.222.333/0001-81',
        [
            'cnpj' => '11222333000181',

            'razao_social' => 'AGRO BRASIL TESTE LTDA',

            'nome_fantasia' => 'AGRO BRASIL',

            'capital_social' => 5000000,

            'codigo_porte' => 5,

            'porte' => 'DEMAIS',

            'codigo_natureza_juridica' => 2062,

            'natureza_juridica' => 'Sociedade Empresária Limitada',

            'identificador_matriz_filial' => 1,

            'descricao_identificador_matriz_filial' => 'MATRIZ',

            'situacao_cadastral' => 2,

            'descricao_situacao_cadastral' => 'ATIVA',

            'data_inicio_atividade' => '2020-01-10',

            'uf' => 'PR',

            'municipio' => 'TOLEDO',

            'codigo_municipio_ibge' => 4127700,

            'ddd_telefone_1' => '4533334444',

            'email' => 'FISCAL@AGROTESTE.COM.BR',

            /*
             * A API retorna números JSON
             * sem zero à esquerda.
             *
             * 0115600 chega como 115600.
             */
            'cnae_fiscal' => 115600,

            'cnae_fiscal_descricao' => 'Cultivo de soja',

            'cnaes_secundarios' => [
                [
                    'codigo' => 4622200,

                    'descricao' => 'Comércio atacadista de soja',
                ],
            ],

            'qsa' => [
                [
                    'nome_socio' => 'JOÃO DA SILVA',
                ],
            ],
        ],
        'brasilapi',
    );

    expect(
        $company->corporate_name
    )->toBe(
        'AGRO BRASIL TESTE LTDA'
    );

    expect(
        $company->share_capital
    )->toBe(
        '5000000.00'
    );

    $matrix = $company
        ->establishments()
        ->where(
            'type',
            'matrix'
        )
        ->firstOrFail();

    expect(
        $matrix->registration_status
    )->toBe('ATIVA');

    expect(
        $matrix->state
    )->toBe('PR');

    expect(
        $matrix->email
    )->toBe(
        'fiscal@agroteste.com.br'
    );

    $matrix->load('cnaes');

    expect(
        $matrix->cnaes
    )->toHaveCount(2);

    $primary = $matrix
        ->cnaes
        ->first(
            fn ($cnae) => (bool)
                $cnae
                    ->pivot
                    ->is_primary
        );

    expect(
        $primary?->code
    )->toBe(
        '0115600'
    );
});
