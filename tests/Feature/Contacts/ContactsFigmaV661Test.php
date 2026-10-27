<?php

declare(strict_types=1);

it('keeps the Contacts V66.1 topbar light and makes the entire inspector scrollable', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)->not->toBeFalse();
    expect((string) $css)
        ->toContain('CONTATOS_FIGMA_V661_BEGIN')
        ->toContain('background: #ffffff !important')
        ->toContain('overflow-y: auto !important')
        ->toContain('.ecc3-filters.is-open');
});

it('makes the select-all control an actual Livewire action and adds branded integration icons', function (): void {
    $view = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    $icons = file_get_contents(resource_path('views/partials/contacts/integration-icon-v661.blade.php'));

    expect($view)->not->toBeFalse();
    expect($icons)->not->toBeFalse();
    expect((string) $view)
        ->toContain('data-ec-contacts-figma="C01-C05-V66"')
        ->toContain('wire:click="toggleCurrentPage"')
        ->toContain('c661-select-all')
        ->toContain('c661-receita-card')
        ->toContain("'service' => 'hubspot'")
        ->toContain("'service' => 'whatsapp'")
        ->toContain('c661-filter-toggle')
        ->toContain('x-on:click.outside="if (!$event.target.closest(')
        ->and((string) $icons)->toContain('c661-brand-icon--hubspot', 'c661-brand-icon--whatsapp');
});
