<?php

it('carrega uma folha por modulo sem importar as camadas antigas separadamente', function (): void {
    $appCss = file_get_contents(resource_path('css/app.css'));
    expect($appCss)->toBeString();

    $bundles = [
        'commercial-management-consolidated.css' => [
            'commercial-management-v2.css',
            'commercial-management-figma-v61.css',
            'commercial-management-figma-v62.css',
            'commercial-management-figma-v624.css',
        ],
        'companies-figma-consolidated.css' => [
            'companies-figma-v64.css',
            'companies-figma-v643.css',
        ],
        'imports-figma-consolidated.css' => [
            'imports-figma-v65.css',
            'imports-figma-v652.css',
        ],
    ];

    foreach ($bundles as $bundle => $originals) {
        $import = "@import './{$bundle}';";
        expect(substr_count((string) $appCss, $import))->toBe(1);
        expect(file_exists(resource_path('css/'.$bundle)))->toBeTrue();

        foreach ($originals as $original) {
            expect($appCss)->not->toContain("@import './{$original}';");
            expect(file_exists(resource_path('css/'.$original)))->toBeFalse();
        }
    }
});

it('preserva as regras de gestao empresas e importacoes e a ordem dos grupos', function (): void {
    $appCss = file_get_contents(resource_path('css/app.css'));
    $management = file_get_contents(resource_path('css/commercial-management-consolidated.css'));
    $companies = file_get_contents(resource_path('css/companies-figma-consolidated.css'));
    $imports = file_get_contents(resource_path('css/imports-figma-consolidated.css'));

    expect($management)->toContain('.ecgm-v2')
        ->toContain('.ecgm61')
        ->toContain('.ecgm62');
    expect($companies)->toContain('.ec-e01-page')
        ->toContain('.ec-e04-page');
    expect($imports)->toContain('.ecim65-page')
        ->toContain('-webkit-text-fill-color');

    expect(strpos((string) $appCss, 'companies-figma-consolidated.css'))
        ->toBeLessThan(strpos((string) $appCss, 'commercial-management-consolidated.css'));
    expect(strpos((string) $appCss, 'commercial-management-consolidated.css'))
        ->toBeLessThan(strpos((string) $appCss, 'imports-figma-consolidated.css'));
});
