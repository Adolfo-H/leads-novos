<?php

test('a2414 atualiza o CSS de tema e inclui a correção segura do DOM', function () {
    $head = file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/head.blade.php');
    $css = file_get_contents(dirname(__DIR__, 2).'/public/css/ec-ui-dark-v74.css');
    $script = file_get_contents(dirname(__DIR__, 2).'/public/js/ec-ui-hardfix-v74.js');
    expect($head)->toContain('/css/ec-ui-dark-v74.css');
    expect($head)->toContain('/js/ec-ui-hardfix-v74.js');
    expect($css)->toContain('.lv15-selected-filter');
    expect($css)->toContain('.ecgm62-person-link');
    expect($css)->toContain('.ecc3-inspector');
    expect($css)->toContain('.ec22-card');
    expect($css)->toContain('.ec21-inbox-item');
    expect($script)->toContain('livewire:navigated');
    expect($script)->toContain('ProspectorDarkA24');
    expect($script)->not->toContain('fetch(');
});
