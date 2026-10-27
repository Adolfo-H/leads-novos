<?php

declare(strict_types=1);

it('keeps the V62.4 alignment stylesheet imported once', function (): void {
    $css = file_get_contents(base_path('resources/css/app.css'));

    expect(substr_count($css, "@import './commercial-management-figma-v624.css';"))
        ->toBe(1);

    $overlay = file_get_contents(base_path('resources/css/commercial-management-figma-v624.css'));

    expect($overlay)
        ->toContain('.ecgm62-load-card')
        ->toContain('.ecgm62-role-body')
        ->toContain('grid-template-columns: repeat(2, minmax(0, 1fr))');
});
