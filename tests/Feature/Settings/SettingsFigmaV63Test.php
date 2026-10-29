<?php

use Illuminate\Support\Facades\Route;

it('preserva as rotas originais das três configurações', function () {
    foreach (['profile.edit', 'security.edit', 'appearance.edit'] as $name) {
        expect(Route::has($name))->toBeTrue();
    }
    $security = Route::getRoutes()->getByName('security.edit');
    expect($security)->not->toBeNull();
    expect(implode('|', $security->gatherMiddleware()))->toContain('password.confirm');
});

it('mantém as ações reais do Livewire e as áreas do Figma', function () {
    $root = base_path('resources/views/pages/settings/');
    $profile = file_get_contents($root.'partials/profile-v63.blade.php');
    $security = file_get_contents($root.'partials/security-v63.blade.php');
    $appearance = file_get_contents($root.'partials/appearance-v63.blade.php');
    expect($profile)->toContain('wire:submit="updateProfileInformation"');
    expect($profile)->toContain('wire:model="name"');
    expect($profile)->toContain('wire:model="email"');
    expect($profile)->toContain('pages::settings.delete-user-form');
    expect($security)->toContain('wire:submit="updatePassword"');
    expect($security)->toContain('two-factor-setup-modal');
    expect($security)->toContain('pages::settings.two-factor.recovery-codes');
    expect($security)->toContain('x-passkey-registration');
    expect($security)->toContain('deletePasskey');
    expect($appearance)->toContain('x-model="theme"');
    expect($appearance)->toContain('window.EcAppearanceV65.set($event.target.value)');
    expect($appearance)->toContain('x-on:ec-appearance-changed.window');
    expect($appearance)->toContain("['light', 'Claro'");
    expect($appearance)->toContain("['dark', 'Escuro'");
    expect($appearance)->toContain("['system', 'Sistema'");
});

it('não altera os métodos PHP originais dos componentes Volt', function () {
    $root = base_path('resources/views/pages/settings/');
    foreach (['profile', 'security', 'appearance'] as $page) {
        $content = file_get_contents($root.'⚡'.$page.'.blade.php');
        expect($content)->toContain('class extends Component');
        expect($content)->toContain("@include('pages.settings.partials.".$page."-v63')");
        expect(substr_count($content, '?>'))->toBe(1);
    }
    expect(file_get_contents(base_path('resources/views/partials/head.blade.php')))
        ->toContain('EC_SETTINGS_FIGMA_V63');
});
