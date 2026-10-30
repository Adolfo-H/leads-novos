<?php

it('usa mensagens em português na validação da lista de CNPJs', function () {
    $code = file_get_contents(dirname(__DIR__, 2).'/resources/views/pages/imports/⚡index.blade.php');
    expect($code)
        ->toContain("'input.required' => 'Informe pelo menos um CNPJ para iniciar a importação.'")
        ->toContain("'input.max' => 'A lista é muito grande. Reduza o conteúdo e tente novamente.'")
        ->toContain("'required'")
        ->toContain("'max:1000000'");
});

it('carrega o ajuste final de contraste e mantém a aparência limitada ao escuro', function () {
    $head = file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/head.blade.php');
    $css = file_get_contents(dirname(__DIR__, 2).'/public/css/ec-ui-dark-v73.css');
    expect($head)->toContain('data-ec-ui-dark="v73"');
    expect(substr_count($head, 'data-ec-ui-dark="v73"'))->toBe(1);
    expect($css)->toContain('html.dark:not([data-ec-appearance="light"]):not([data-ec-appearance="system"])')
        ->toContain('.lv15-selected-filter')
        ->toContain('.ecgm62-person-link')
        ->toContain('.im65-alerts')
        ->toContain('.ecc3-filter-group')
        ->toContain('.ecc3-table tbody tr')
        ->toContain('.e592-account-trigger');
});
