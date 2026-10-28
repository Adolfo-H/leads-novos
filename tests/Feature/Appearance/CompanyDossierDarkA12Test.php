<?php

it('usa somente a preferencia Flux para o tema escuro do dossie Figma', function (): void {
    $head = file_get_contents(resource_path('views/partials/head.blade.php'));
    $base = file_get_contents(resource_path('css/company-dossier-figma-v53.css'));
    $theme = file_get_contents(public_path('css/ec-theme-unified-v64.css'));

    expect($head)->toContain('@fluxAppearance')
        ->toContain('data-ec-theme-unified="v64"')
        ->toContain('?v=64.12');
    expect($base)->toContain('.ec-page-shell.ec-dossier-page.e53-dossier');
    expect($theme)->toContain('EC_DOSSIER_THEME_A12')
        ->toContain('html.dark body.ec-app-body .ec-page-shell.ec-dossier-page.e53-dossier')
        ->toContain('--e53-white: var(--v64-panel)')
        ->toContain('--e53-canvas: var(--v64-page)')
        ->toContain('--e53-ink: var(--v64-ink)')
        ->toContain('--e53-line: var(--v64-line)')
        ->toContain('.e53-modal, .e591-channel-card')
        ->toContain('.e53-form input:not([type="checkbox"])')
        ->toContain('.e53-table thead, .e53-contact-extra');
});

it('preserva a aparencia clara e as acoes administrativas do dossie', function (): void {
    $theme = file_get_contents(public_path('css/ec-theme-unified-v64.css'));
    $show = file_get_contents(resource_path('views/pages/companies/⚡show.blade.php'));
    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));

    // Nao criar mecanismo adicional de tema nem carregar CSS duplicado na view.
    expect($theme)->not->toContain('localStorage.setItem("dossier-theme"')
        ->toContain('html:not(.dark) {');
    expect($show)->toContain("@include('partials.company-dossier.figma-v53')");
    expect($figma)->toContain("@include('partials.company-dossier.figma-modals-v53')");
});
