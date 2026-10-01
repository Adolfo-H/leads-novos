<?php

it('loads the leads workspace stylesheet from the main application css', function () {
    $appCss =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    expect(
        $appCss
    )->toContain(
        "@import './leads-workspace.css';"
    );
});

it('keeps leads styles outside the blade views', function () {
    $views = [
        resource_path(
            'views/pages/leads/⚡index.blade.php'
        ),

        resource_path(
            'views/partials/hubspot-unmatched-leads.blade.php'
        ),

        resource_path(
            'views/partials/hubspot-company-linker.blade.php'
        ),
    ];

    foreach (
        $views as $view
    ) {
        $contents =
            file_get_contents(
                $view
            );

        expect(
            $contents
        )->not->toContain(
            '<style>'
        );
    }
});

it('keeps all leads visual modules in the dedicated stylesheet', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/leads-workspace.css'
            )
        );

    expect(
        $css
    )->toContain(
        '.leads-rf'
    );

    expect(
        $css
    )->toContain(
        '.rf-crm-only-panel'
    );

    expect(
        $css
    )->toContain(
        '.rf-company-linker'
    );
});
