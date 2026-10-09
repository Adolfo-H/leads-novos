<?php

it('preserves operational controls and qualification evidence in the v20 compact panel', function (): void {
    $blade = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/leads-v20-1.css'));

    expect($blade)
        ->toContain('lv23-actions')
        ->not->toContain('x-show="detailsOpen"')
        ->toContain('Dossiê ↗')
        ->toContain('$scoreReasons')
        ->toContain('$crmDeals')
        ->toContain('claimLead(')
        ->toContain('Assumir lead')
        ->toContain('verifyHubSpotNow(')
        ->toContain('resumeLead(')
        ->toContain('private function filteredLeadsQuery()')
        ->toContain('wire:click="exportExcel"')
        ->and($css)->toContain('lv201-context-grid');
});
