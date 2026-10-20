<?php

it('filtra os nomes das opcoes localmente, sem vinculo Livewire no campo', function (): void {
    $contents = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));

    expect($contents)->not->toBeFalse();
    expect((string) $contents)
        ->toContain('x-model="filterKeyword"')
        ->toContain('data-filter-label="{{ mb_strtolower($label) }}"')
        ->toContain('$el.dataset.filterLabel')
        ->not->toContain('wire:model.live.debounce.200ms="filterKeyword"')
        ->not->toContain('public string $filterKeyword = \'\';');

    expect(substr_count((string) $contents, 'data-filter-label="{{ mb_strtolower($label) }}"'))->toBe(3);
});

it('consulta contexto da empresa somente com o inspetor aberto', function (): void {
    $contents = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));

    expect((string) $contents)
        ->toContain('$contextKey = $inspectorOpen ? (')
        ->toContain('($selected->person_type ?? \'\') === \'hubspot\'');
});
