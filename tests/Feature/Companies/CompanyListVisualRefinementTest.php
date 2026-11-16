<?php

declare(strict_types=1);

it('adds the E01 to E04 visual refinement without overwriting V64 and retains Settings', function (): void {
    $appCss = file_get_contents(resource_path('css/app.css'));
    $bundleCss = file_get_contents(resource_path('css/companies-figma-consolidated.css'));
    $sidebar = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));

    expect($appCss)->not->toBeFalse()
        ->and($bundleCss)->not->toBeFalse()
        ->and($sidebar)->not->toBeFalse()
        ->and(substr_count((string) $appCss, "@import './companies-figma-consolidated.css';"))->toBe(1)
        ->and($bundleCss)->toContain('.ec-e01-page')
        ->toContain('EMPRESAS_FIGMA_V643')
        ->toContain('.ec-sidebar-world-v7')
        ->toContain('.ec-sidebar-footer')
        ->toContain('-webkit-text-fill-color: #17354b !important;')
        ->toContain('background: #fff !important;')
        ->and($sidebar)->toContain('Configurações');
});

it('keeps the companies list and manual registration on their original Figma E01 and E02 components', function (): void {
    $index = file_get_contents(resource_path('views/pages/companies/⚡index.blade.php'));
    $create = file_get_contents(resource_path('views/pages/companies/⚡create.blade.php'));

    expect($index)->not->toBeFalse()
        ->and($create)->not->toBeFalse()
        ->and($index)->toContain('data-ec-companies-figma="E01-V64"')
        ->toContain('wire:model.live.debounce.400ms="search"')
        ->and($create)->toContain('data-ec-companies-figma="E02-V64"')
        ->toContain('wire:submit="save"');
});
