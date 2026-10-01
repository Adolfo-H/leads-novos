<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Services\ExportResearchEntityMatcher;
use App\Support\Cnpj;

it('does not concatenate unrelated numbers into a cnpj root', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $company =
        new Company([
            'cnpj_root' => '12345678',

            'corporate_name' => 'Empresa Sem Identidade Numerica Ltda',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment
    );

    /*
     * Antes da correção:
     *
     * 1234 + 5678
     *
     * podia virar:
     *
     * 12345678
     *
     * e casar com a raiz.
     */
    expect(
        $matcher->matches(
            company: $company,

            title: 'Relatorio de pedidos',

            content: 'O pedido 1234 registrou um total de 5678 unidades.',

            url: 'https://example.com/relatorio',
        )
    )->toBeFalse();
});

it('accepts a valid full numeric cnpj belonging to the company root', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $company =
        new Company([
            'cnpj_root' => '12345678',

            'corporate_name' => 'Nome Que Nao Aparece Na Fonte Ltda',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment
    );

    $base =
        '123456780001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    expect(
        Cnpj::isValid(
            $cnpj
        )
    )->toBeTrue();

    expect(
        $matcher->matches(
            company: $company,

            title: 'Cadastro empresarial',

            content: 'CNPJ: '
                .Cnpj::format(
                    $cnpj
                ),

            url: 'https://example.com/cadastro',
        )
    )->toBeTrue();
});

it('accepts a valid alphanumeric cnpj belonging to the company root', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $root =
        'AB345678';

    $company =
        new Company([
            'cnpj_root' => $root,

            'corporate_name' => 'Empresa CNPJ Alfanumerico Ltda',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment
    );

    $base =
        $root
        .'0001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    expect(
        Cnpj::isValid(
            $cnpj
        )
    )->toBeTrue();

    expect(
        Cnpj::isAlphanumeric(
            $cnpj
        )
    )->toBeTrue();

    expect(
        $matcher->matches(
            company: $company,

            title: 'Registro empresarial',

            content: 'Cadastro nacional: '
                .$cnpj,

            url: 'https://example.com/registro',
        )
    )->toBeTrue();
});
