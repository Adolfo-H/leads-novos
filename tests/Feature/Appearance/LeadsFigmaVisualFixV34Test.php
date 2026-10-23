<?php

it('isolates the light Figma palette to Leads and commercial management', function (): void {
    $css = file_get_contents(resource_path('css/leads-figma-v34.css'));
    $app = file_get_contents(resource_path('css/app.css'));

    expect($css)->not->toBeFalse()
        ->toContain('body.ec-app-body:has(.leads-rf)')
        ->toContain('body.ec-app-body:has(.ecgm-page)')
        ->toContain('.ec-page-shell.leads-rf .lv13-lead-line')
        ->toContain('.ecgm-page.ecgm-v2 .ecgm-metric')
        ->toContain('background: #fff !important;');

    expect($app)->not->toBeFalse()
        ->toContain("@import './leads-figma-v34.css';");
});
