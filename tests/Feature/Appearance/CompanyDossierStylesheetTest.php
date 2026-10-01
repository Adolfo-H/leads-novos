<?php

it('loads the dedicated company dossier stylesheet', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    expect(
        $css
    )->toContain(
        "@import './company-dossier.css';"
    );
});

it('keeps dossier navigation styles outside the main stylesheet', function () {
    $appCss =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    $dossierCss =
        file_get_contents(
            resource_path(
                'css/company-dossier.css'
            )
        );

    expect(
        $appCss
    )->not->toContain(
        'Company dossier - navegacao dinamica'
    );

    expect(
        $dossierCss
    )->toContain(
        '.ec-dossier-tabs'
    );

    expect(
        $dossierCss
    )->toContain(
        '.ec-dossier-tab'
    );

    expect(
        $dossierCss
    )->toContain(
        '.ec-show-more-button'
    );
});

it('keeps global alpine cloak and late dossier refinement in app css', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    expect(
        $css
    )->toContain(
        '[x-cloak]'
    );

    expect(
        $css
    )->toContain(
        'Company dossier - refinamento V3'
    );
});

it('does not move shared visual primitives into dossier stylesheet', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/company-dossier.css'
            )
        );

    expect(
        $css
    )->not->toContain(
        '.ec-intelligence-card'
    );

    expect(
        $css
    )->not->toContain(
        '.ec-detail-panel'
    );

    expect(
        $css
    )->not->toContain(
        '.ec-cnae-row'
    );
});
