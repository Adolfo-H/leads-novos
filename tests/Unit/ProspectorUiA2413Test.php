<?php

test('a2413 injeta o css v74 no head', function () {
    $head = file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/head.blade.php');
    expect($head)->toContain('/css/ec-ui-dark-v74.css');
});
