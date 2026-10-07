<?php

it('uses the simplified commercial hierarchy on the leads workspace', function () {
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
            'partials.leads-action-center'
        )
        ->toContain(
            'partials.leads-crm-stage-strip'
        )
        ->toContain(
            'rf-filters-head'
        )
        ->not->toContain(
            '<section class="rf-kpis">'
        )
        ->not->toContain(
            '<section class="rf-status-guide">'
        )
        ->not->toContain(
            '<section class="rf-stage-summary">'
        );
});

it('keeps the daily queue in the action center instead of duplicating it in filters', function () {
    $action =
        file_get_contents(
            resource_path(
                'views/partials/leads-action-center.blade.php'
            )
        );

    $view =
        file_get_contents(
            resource_path(
                'views/pages/leads/⚡index.blade.php'
            )
        );

    expect(
        $action
    )
        ->toContain(
            'Minha fila hoje'
        )
        ->toContain(
            'Na operação'
        );

    expect(
        substr_count(
            $view,
            'wire:click="applyDailyView"'
        )
    )->toBe(
        0
    );
});

it('renders the compact HubSpot pipeline strip', function () {
    $partial =
        file_get_contents(
            resource_path(
                'views/partials/leads-crm-stage-strip.blade.php'
            )
        );

    expect(
        $partial
    )
        ->toContain(
            'Pipeline HubSpot'
        )
        ->toContain(
            'rf-stage-strip-chip'
        )
        ->toContain(
            'applyCrmStageView'
        );
});
