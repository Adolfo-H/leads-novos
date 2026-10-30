<?php

test('A24.15 identifica os componentes reais dos filtros e campanhas', function () {
    $head = file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/head.blade.php');
    $contacts = file_get_contents(dirname(__DIR__, 2).'/resources/views/pages/contacts/⚡index.blade.php');
    $css = file_get_contents(dirname(__DIR__, 2).'/public/css/ec-ui-dark-v74.css');
    $js = file_get_contents(dirname(__DIR__, 2).'/public/js/ec-ui-hardfix-v74.js');
    expect($head)->toContain('ec-ui-dark-v74.css');
    expect($head)->toContain('ec-ui-hardfix-v74.js');
    expect(substr_count($contacts, 'class="ecv75-filter-column"'))->toBe(2);
    expect($contacts)->toContain('ecc3-filters-foot');
    expect($css)->toContain('.ec68-stat');
    expect($css)->toContain('.ec76-metric');
    expect($css)->toContain('.ec21-history');
    expect($css)->toContain('.ecv75-filter-column');
    expect($js)->toContain('ProspectorDarkA24');
    expect($js)->toContain('livewire:navigated');
});
