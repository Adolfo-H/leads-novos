<?php

it('preserves functional lead and management pages while applying the scoped contrast layer', function (): void {
    $style = file_get_contents(resource_path('css/app.css'));
    $leads = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $management = file_get_contents(resource_path('views/pages/leads/⚡management.blade.php'));

    expect($style)->not->toBeFalse()
        ->toContain('EC_LEADS_V35_BEGIN')
        ->toContain('EC_LEADS_V35_END')
        ->toContain('.leads-rf .rf-list-panel')
        ->toContain('.ecgm-page.ecgm-v2 .ecgm-metrics');

    expect($leads)->not->toBeFalse()
        ->toContain('leads-rf')
        ->toContain('wire:click')
        ->toContain('HubSpot');

    expect($management)->not->toBeFalse()
        ->toContain('ecgm-v2')
        ->toContain('distributionSellerIds')
        ->toContain('distributionLimit');
});
