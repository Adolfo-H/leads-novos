<?php

test('A24.16 reforca contraste das campanhas', function () {
    $head = file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/head.blade.php');
    $css = file_get_contents(dirname(__DIR__, 2).'/public/css/ec-ui-dark-v74.css');
    expect($head)->toContain('ec-ui-dark-v74.css?v=74.16');
    expect($head)->toContain('ec-ui-hardfix-v74.js?v=74.16');
    expect($css)->toContain('A24.16 — refinamentos de contraste e legibilidade em Campanhas');
    expect($css)->toContain('.ec68-starter-grid > *');
    expect($css)->toContain('.ec21-history');
    expect($css)->toContain('.ec22-email-paper');
});
