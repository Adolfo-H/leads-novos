<?php

it('keeps the company dossier page split by top level tabs', function () {
    $show =
        file_get_contents(
            resource_path(
                'views/pages/companies/⚡show.blade.php'
            )
        );

    expect($show)->toContain("'partials.company-dossier.figma-v53'");
    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));
    expect($figma)->toContain("'partials.company-dossier.figma-crm-v53'")
        ->toContain("'partials.company-dossier.figma-group-v53'")
        ->toContain("'partials.company-dossier.figma-cnaes-v53'");
});

it('keeps the commercial tab in its dedicated partial', function () {
    $contents =
        file_get_contents(
            resource_path(
                'views/partials/company-dossier/commercial.blade.php'
            )
        );

    expect(
        $contents
    )->toContain(
        'INTELIGÊNCIA COMERCIAL'
    );

    expect(
        $contents
    )->toContain(
        "=== 'commercial'"
    );

    expect(
        $contents
    )->toContain(
        'EVIDÊNCIAS DE EXPORTAÇÃO'
    );
});

it('keeps company group information in its dedicated partial', function () {
    $contents =
        file_get_contents(
            resource_path(
                'views/partials/company-dossier/company.blade.php'
            )
        );

    expect(
        $contents
    )->toContain(
        'PRESENÇA OPERACIONAL DO GRUPO'
    );

    expect(
        $contents
    )->toContain(
        "=== 'company'"
    );

    expect(
        $contents
    )->toContain(
        'DADOS + CONTATO'
    );

    expect(
        $contents
    )->toContain(
        'ESTABELECIMENTOS'
    );

    expect(
        $contents
    )->toContain(
        'CNAES DO GRUPO'
    );
});

it('keeps cnae management in its dedicated partial', function () {
    $contents =
        file_get_contents(
            resource_path(
                'views/partials/company-dossier/cnaes.blade.php'
            )
        );

    expect(
        $contents
    )->toContain(
        "=== 'cnaes'"
    );

    expect(
        $contents
    )->toContain(
        'FORMULÁRIO CNAE'
    );

    expect(
        $contents
    )->toContain(
        'wire:click="makeCnaePrimary'
    );

    expect(
        $contents
    )->toContain(
        'wire:click="removeCnae'
    );
});
