<?php

use Illuminate\Support\Facades\Route;

it('usa o tema global nas três configurações sem mostrar o comentário inválido da V63', function () {
    $root = base_path('resources/views/pages/settings/');

    foreach (['profile', 'security', 'appearance'] as $screen) {
        $page = file_get_contents($root.'⚡'.$screen.'.blade.php');
        expect($page)->toContain("@include('pages.settings.partials.".$screen."-v63')");
        expect($page)->not->toContain('{-- CONFIGURACOES_FIGMA_V63');
        expect(substr_count((string) $page, '?>'))->toBe(1);
    }

    $head = file_get_contents(base_path('resources/views/partials/head.blade.php'));
    expect($head)->toContain('@fluxAppearance');
    expect($head)->toContain('data-ec-theme-unified="v64"');
    expect(substr_count((string) $head, 'data-ec-theme-unified="v64"'))->toBe(1);
});

it('usa a mesma fonte Flux para claro, escuro e sistema', function () {
    $appearance = file_get_contents(base_path('resources/views/pages/settings/partials/appearance-v63.blade.php'));
    $css = file_get_contents(public_path('css/ec-theme-unified-v64.css'));

    expect($appearance)->toContain('x-model="$flux.appearance"');
    expect($appearance)->toContain("['light', 'Claro'");
    expect($appearance)->toContain("['dark', 'Escuro'");
    expect($appearance)->toContain("['system', 'Sistema'");
    expect($css)->toContain('html:not(.dark)');
    expect($css)->toContain('html.dark');
    expect($css)->toContain('.ec-sidebar');
    expect($css)->toContain('.ec-topbar');
});

it('preserva as três rotas e a confirmação de senha para segurança', function () {
    foreach (['profile.edit', 'security.edit', 'appearance.edit'] as $route) {
        expect(Route::has($route))->toBeTrue();
    }

    $security = Route::getRoutes()->getByName('security.edit');
    expect($security)->not->toBeNull();
    expect(implode('|', $security->gatherMiddleware()))->toContain('password.confirm');
});
