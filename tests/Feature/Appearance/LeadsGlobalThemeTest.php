<?php

it('owns its light and dark compatibility inside the leads stylesheet', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/leads-workspace.css'
            )
        );

    expect(
        $css
    )->toContain(
        'EC_LEADS_THEME_START'
    );

    expect(
        $css
    )->toContain(
        '--rf-panel:'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-surface)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-text)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-primary)'
    );
});

it('does not keep the old leads compatibility section in app css', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/app.css'
            )
        );

    $legacySection =
        preg_match(
            '/\|\s+LEADS\s*\n\|[-]+\n\*\/.*?\.leads-rf/s',
            $css
        );

    expect(
        $legacySection
    )->toBe(
        0
    );
});

it('keeps semantic colors while neutral colors use application theme tokens', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/leads-workspace.css'
            )
        );

    expect(
        $css
    )->toContain(
        '--rf-green:'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-success)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-warning)'
    );

    expect(
        $css
    )->toContain(
        'var(--ec-danger)'
    );
});
