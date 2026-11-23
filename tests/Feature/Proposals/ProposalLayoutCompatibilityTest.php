<?php

it('preserva a guarda da barra superior de Campanhas ao acrescentar Propostas', function (): void {
    $source = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));

    expect($source)->not->toBeFalse()
        ->toContain("'access.requests.*', 'contacts.whatsapp', 'campaigns.*'))")
        ->toContain("request()->routeIs('proposals.*')")
        ->toContain('Propostas Comerciais');
});
