<?php

// A24.11: regressões de interface; nenhuma consulta ao banco.
$base = dirname(__DIR__, 2);

test('o visual V72 é carregado depois do reparo V71', function () use ($base) {
    $head = file_get_contents($base.'/resources/views/partials/head.blade.php');
    expect($head)->toContain('data-ec-ui-polish="v72"');
    expect(strpos($head, 'data-ec-ui-polish="v72"'))
        ->toBeGreaterThan(strpos($head, 'data-ec-dark-v71-js="v71"'));
});

test('a confirmacao de papel é interna e a autorizacao continua no servidor', function () use ($base) {
    $page = file_get_contents($base.'/resources/views/pages/leads/⚡management.blade.php');
    expect($page)->toContain('roleConfirmOpen')
        ->toContain('ec-role-modal-v72')
        ->toContain('openRoleConfirm(')
        ->toContain('await this.$wire.updateCommercialRole(id, role)')
        ->toContain('wire:loading.attr="disabled"')
        ->not->toContain('wire:confirm="Alterar o perfil comercial');
});

test('correcoes de cor permanecem restritas ao modo escuro e preservam Flux', function () use ($base) {
    $css = file_get_contents($base.'/public/css/ec-ui-polish-v72.css');
    foreach (['lf34-header-link', 'lv15-selected-filter', 'lf37-global-tag', 'ecgm62-person-link',
        'ecgm61-avatar', 'ecgm61-distribution-gain', 'ec-role-dialog-v72'] as $selector) {
        expect($css)->toContain($selector);
    }
    expect($css)->toContain('html.dark:not([data-ec-appearance="light"]):not([data-ec-appearance="system"])');
});
