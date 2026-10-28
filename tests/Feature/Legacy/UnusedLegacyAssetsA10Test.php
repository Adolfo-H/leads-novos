<?php

it('does not retain six unused legacy CSS and Blade assets', function (): void {
    foreach ([
        'resources/css/leads-fixes-v362.css',
        'resources/css/leads-fixes-v363.css',
        'resources/css/dashboard-figma-v28.css',
        'resources/views/partials/leads-detail-extras-v18.blade.php',
        'resources/views/partials/leads-figma-tabs-v34.blade.php',
        'resources/views/partials/settings-heading.blade.php',
    ] as $path) {
        expect(is_file(base_path($path)))->toBeFalse();
    }
});

it('keeps the active dashboard and leads styles in the Vite entry point', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain("@import './dashboard-figma-v29.css';")
        ->toContain("@import './leads-figma-v34.css';");
});
