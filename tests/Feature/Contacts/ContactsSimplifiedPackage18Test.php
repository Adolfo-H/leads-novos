<?php

use Illuminate\Support\Facades\Route;

it('removes only the redundant fiscal actions from the Contacts workspace', function (): void {
    $view = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    expect($view)->not->toBeFalse();
    expect($view)->not->toContain('Canais Receita')
        ->not->toContain("['fiscal', 'Vínculo fiscal'")
        ->not->toContain("['fiscal', 'Com vínculo fiscal seguro'")
        ->toContain("['receita', 'Receita Federal'");
});

it('keeps the original Receita company channel route and fiscal integrity code', function (): void {
    expect(Route::has('contacts.receita'))->toBeTrue();
    $directory = file_get_contents(app_path('Services/Contacts/ContactPeopleDirectory.php'));
    expect($directory)->not->toBeFalse();
    // O mapeamento fiscal interno continua disponível para vínculos legítimos.
    expect($directory)->toContain('fiscal');
});
