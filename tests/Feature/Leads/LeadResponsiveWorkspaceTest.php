<?php

it('contains the responsive card layout for the leads workspace', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/leads-workspace.css'
            )
        );

    expect(
        $css
    )
        ->toContain(
            'LEADS_RESPONSIVE_POLISH_START'
        )
        ->toContain(
            '@media ('
        )
        ->toContain(
            'max-width: 900px'
        )
        ->toContain(
            'max-width: 600px'
        )
        ->toContain(
            '.rf-col-company'
        )
        ->toContain(
            '.rf-col-action'
        )
        ->toContain(
            'prefers-reduced-motion'
        );
});

it('turns the lead table into cards on smaller screens', function () {
    $css =
        file_get_contents(
            resource_path(
                'css/leads-workspace.css'
            )
        );

    expect(
        $css
    )
        ->toContain(
            '.leads-rf .rf-list-head'
        )
        ->toContain(
            'display:'
        )
        ->toContain(
            'none;'
        )
        ->toContain(
            'grid-column:'
        )
        ->toContain(
            '1 / -1'
        );
});

it('keeps visual feedback and accessible live regions in the workspace', function () {
    $view =
        file_get_contents(
            resource_path(
                'views/pages/leads/⚡index.blade.php'
            )
        );

    expect(
        $view
    )
        ->toContain(
            'rf-filters-loading'
        )
        ->toContain(
            'aria-live="polite"'
        )
        ->toContain(
            'aria-live="assertive"'
        );
});
