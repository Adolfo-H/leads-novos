<?php

it('uses the shared white topbar for the prospecting motor and preserves settings in management', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));
    $sidebar = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));

    expect($css)->not->toBeFalse();
    expect($sidebar)->not->toBeFalse();

    expect(substr_count((string) $css, 'EC_MOTOR_TOPBAR_BRANCO_GESTAO_V637_BEGIN'))
        ->toBe(1);

    expect((string) $css)
        ->toContain('body.ec-app-body:has(.prv63-page) .ec-topbar')
        ->toContain('background: #ffffff !important;')
        ->toContain('body.ec-app-body:has(.ecgm62) .ec-sidebar .ec-sidebar-world-v7');

    expect((string) $sidebar)
        ->not->toContain('ec-sidebar-world-v7')
        ->toContain('Configurações');
});
