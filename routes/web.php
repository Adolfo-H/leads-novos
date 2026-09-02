<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| Prospector ExportControl - Empresas
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire(
        '/empresas',
        'pages::companies.index'
    )->name('companies.index');

    Route::livewire(
        '/empresas/nova',
        'pages::companies.create'
    )->name('companies.create');

    Route::livewire(
        '/empresas/{company:uuid}/editar',
        'pages::companies.edit'
    )->name('companies.edit');

    Route::livewire(
        '/empresas/{company:uuid}',
        'pages::companies.show'
    )->name('companies.show');
});
