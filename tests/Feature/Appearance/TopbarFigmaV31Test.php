<?php

it('keeps the dashboard search separated from its title and makes the account menu readable', function (): void {
    $css = file_get_contents(resource_path('css/dashboard-figma-v29.css'));

    expect($css)->not->toBeFalse()
        ->toContain('EC_TOPBAR_V31_START')
        ->toContain('ec-topbar-context')
        ->toContain('margin-right: 30px !important')
        ->toContain('ec-topbar-account-menu')
        ->toContain('ec-topbar-profile-trigger:is(:hover');
});

it('does not repeat the user initials inside the desktop account dropdown', function (): void {
    $blade = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));

    expect($blade)->not->toBeFalse()
        ->toContain('ec-topbar-account-menu')
        ->toContain('ec-profile-menu-header')
        ->toContain('ec-profile-menu-item')
        ->toContain("route('logout')")
        ->toContain("route('profile.edit')");

    $parts = explode('{{-- TOPBAR MOBILE --}}', (string) $blade, 2);
    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toContain('ec-topbar-avatar')
        ->not->toContain('<flux:avatar');

    // O menu mobile conserva a identificação existente.
    expect($parts[1])->toContain('<flux:avatar');
});
