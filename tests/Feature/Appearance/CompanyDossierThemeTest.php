<?php

it('uses global theme tokens in the company dossier core styles', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    expect(
        $css
    )->toContain(
        '.ec-dossier-tabs'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-surface)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-surface-soft)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-text)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-text-muted)'
    );
});

it('does not keep the old hardcoded dossier color compatibility section', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    expect(
        $css
    )->not->toContain(
        'CORES HARDCODED ANTIGAS DO DOSSIÊ'
    );
});

it('does not use the old dark neutral backgrounds in dossier blade views', function () {
    $files = [
        resource_path(
            'views/pages/companies/⚡show.blade.php'
        ),

        resource_path(
            'views/partials/company-commercial-timeline.blade.php'
        ),
    ];

    $legacy = [
        'bg-[#171d3c]/45',
        'bg-[#697394]',
        'bg-white/5',
        'border-white/10',
    ];

    foreach (
        $files as $file
    ) {
        $contents =
            file_get_contents(
                $file
            );

        foreach (
            $legacy as $class
        ) {
            expect(
                $contents
            )->not->toContain(
                $class
            );
        }
    }
});
