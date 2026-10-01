<?php

it('does not force dark mode in the application layouts', function () {
    $layouts = [
        resource_path(
            'views/layouts/app/sidebar.blade.php'
        ),

        resource_path(
            'views/layouts/app/header.blade.php'
        ),

        resource_path(
            'views/layouts/auth/card.blade.php'
        ),

        resource_path(
            'views/layouts/auth/split.blade.php'
        ),
    ];

    foreach (
        $layouts as $layout
    ) {
        $contents =
            file_get_contents(
                $layout
            );

        expect(
            $contents
        )->not->toContain(
            'class="dark"'
        );
    }
});

it('does not define a private color palette inside dashboard mode', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    expect(
        preg_match(
            '/\.ec-dashboard-mode\s*\{[^}]*--ec-bg\s*:/s',
            $css
        )
    )->toBe(
        0
    );

    expect(
        preg_match(
            '/\.ec-dashboard-mode\s*\{[^}]*--ec-sidebar\s*:/s',
            $css
        )
    )->toBe(
        0
    );
});

it('uses theme variables for dashboard shell', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    expect(
        $css
    )->toContain(
        'var(--ec-bg)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-sidebar)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-topbar)'
    );
});

it('keeps Flux appearance enabled in the document head', function () {
    $head =
        file_get_contents(
            resource_path(
                'views/partials/head.blade.php'
            )
        );

    expect(
        $head
    )->toContain(
        '@fluxAppearance'
    );
});

// EC_THEME_CLEANUP_5B1

it('keeps only one light and one dark application color palette', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    /*
     * Depois da limpeza esperamos:
     *
     * :root     -> claro
     * html.dark -> escuro
     *
     * Não há mais a antiga paleta inicial nem
     * a cópia de compatibilidade body.dark.
     */
    expect(
        substr_count(
            $css,
            '--ec-bg:'
        )
    )->toBe(
        2
    );

    expect(
        preg_match(
            '/body\.dark\s*\{[^}]*--ec-bg\s*:/s',
            $css
        )
    )->toBe(
        0
    );
});

it('does not keep old neutral hardcoded text colors in the main commercial views', function () {
    $files = [
        resource_path(
            'views/pages/companies/⚡show.blade.php'
        ),

        resource_path(
            'views/partials/company-commercial-timeline.blade.php'
        ),

        resource_path(
            'views/pages/imports/⚡index.blade.php'
        ),

        resource_path(
            'views/pages/prospecting/⚡show.blade.php'
        ),

        resource_path(
            'views/pages/leads/⚡index.blade.php'
        ),
    ];

    $legacyColors = [
        'text-[#eef1ff]',
        'text-[#e8ebf7]',
        'text-[#d9ddef]',
        'text-[#cbd1e7]',
        'text-[#7f89aa]',
        'text-[#7f87a7]',
        'text-[#737e9f]',
        'text-[#697394]',
        'text-[#43b9a7]',
    ];

    foreach (
        $files as $file
    ) {
        $contents =
            file_get_contents(
                $file
            );

        foreach (
            $legacyColors as $legacyColor
        ) {
            expect(
                $contents
            )->not->toContain(
                $legacyColor
            );
        }
    }
});
