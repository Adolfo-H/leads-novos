<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Services\ExportResearchEntityMatcher;

it('rejects unrelated export content with similar generic words', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $company =
        new Company([
            'cnpj_root' => '45059898',

            'corporate_name' => 'AGRO CUMARU S.A.',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment
    );

    expect(
        $matcher->matches(
            company: $company,
            title: 'Rotina Fiscal - Venda com fim específico de exportação',
            content: 'Venda para empresa comercial exportadora com fim específico de exportação.',
            url: 'https://www.rotinafiscal.com.br/operacoes-fiscais',
        )
    )->toBeFalse();

    expect(
        $matcher->matches(
            company: $company,
            title: 'Agro Comercial Exportadora S.A.',
            content: 'Agro Comercial Exportadora S.A. is a produce company.',
            url: 'https://example.com/agro-comercial-exportadora',
        )
    )->toBeFalse();
});

it('rejects another entity that only shares the word Itaipu', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $company =
        new Company([
            'cnpj_root' => '12093365',

            'corporate_name' => 'ITAIPU EMPREENDIMENTOS AGRICOLAS S/A',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment
    );

    expect(
        $matcher->matches(
            company: $company,
            title: 'BNDES Setorial',
            content: 'O protótipo nacional Gurgel Itaipu tinha motor elétrico.',
            url: 'https://web.bndes.gov.br/documento.pdf',
        )
    )->toBeFalse();
});

it('accepts the actual company name', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $company =
        new Company([
            'cnpj_root' => '45059898',

            'corporate_name' => 'AGRO CUMARU S.A.',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment
    );

    expect(
        $matcher->matches(
            company: $company,
            title: 'Agro Cumaru - Produção sustentável',
            content: 'A Agro Cumaru atua no agronegócio desde 2000.',
            url: 'https://www.linkedin.com/company/agrocumaru',
        )
    )->toBeTrue();
});

it('accepts a corporate website matching the company email domain', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $company =
        new Company([
            'cnpj_root' => '49213747',

            'corporate_name' => 'RAIZEN CENTRO-SUL PAULISTA S.A',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment([
            'email' => 'fiscal@raizen.com.br',
        ])
    );

    expect(
        $matcher->matches(
            company: $company,
            title: 'Raízen | Our Business',
            content: 'We produce, market, and export sugar.',
            url: 'https://www.raizen.com.br/en/our-business/sugar',
        )
    )->toBeTrue();
});

it('rejects Itaipu collision even when generic agricultural terms are present', function () {
    $matcher =
        new ExportResearchEntityMatcher;

    $company =
        new Company([
            'cnpj_root' => '12093365',

            'corporate_name' => 'ITAIPU EMPREENDIMENTOS AGRICOLAS S/A',
        ]);

    $company->setRelation(
        'matrix',
        new Establishment([
            'fantasy_name' => 'ITAIPU',
        ])
    );

    expect(
        $matcher->matches(
            company: $company,
            title: 'BNDES Setorial',
            content: 'O setor de máquinas agrícolas teve evolução relevante. '
                .'O protótipo nacional Gurgel Itaipu tinha motor elétrico.',
            url: 'https://web.bndes.gov.br/documento.pdf',
        )
    )->toBeFalse();
});
