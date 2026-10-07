<?php

it('uses the same filtered lead query for screen and Excel export', function () {
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
            'private function filteredLeadsQuery()'
        )
        ->toContain(
            'public function exportExcel('
        )
        ->toContain(
            'LeadExportService $service'
        )
        ->toContain(
            'wire:click="exportExcel"'
        )
        ->toContain(
            'Exportar Excel'
        );
});

it('keeps pagination only on the screen query', function () {
    $view =
        file_get_contents(
            resource_path(
                'views/pages/leads/⚡index.blade.php'
            )
        );

    expect(
        substr_count(
            $view,
            '->paginate('
        )
    )->toBeGreaterThanOrEqual(
        1
    );

    expect(
        $view
    )->toContain(
        '->get();'
    );
});

it('contains responsive styles for lead exports', function () {
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
            'LEADS_EXPORT_ACTIONS_START'
        )
        ->toContain(
            '.rf-export-button'
        );
});
