<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::livewire(
        '/importacoes',
        'pages::imports.index'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('imports.index');

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
        '/prospeccao',
        'pages::prospecting.index'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('prospecting.index');

    Route::livewire(
        '/prospeccao/rodadas/{batch:uuid}',
        'pages::prospecting.show'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('prospecting.show');

    Route::livewire(
        '/leads',
        'pages::leads.index'
    )->name('leads.index');

    Route::livewire(
        '/leads/gestao',
        'pages::leads.management'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('leads.management');

    Route::livewire(
        '/empresas',
        'pages::companies.index'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('companies.index');

    Route::livewire(
        '/empresas/nova',
        'pages::companies.create'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('companies.create');

    Route::livewire(
        '/empresas/{company:uuid}/editar',
        'pages::companies.edit'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('companies.edit');

    Route::livewire(
        '/empresas/{company:uuid}/filiais/nova',
        'pages::companies.branches.create'
    )
        ->middleware(
            'commercial.manager'
        )
        ->name('companies.branches.create');

    Route::livewire(
        '/empresas/{company:uuid}',
        'pages::companies.show'
    )
        ->middleware(
            'commercial.company-access'
        )
        ->name('companies.show');
});
