<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::livewire(
        '/importacoes',
        'pages::imports.index'
    )->name('imports.index');

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
    )->name('prospecting.index');

    Route::livewire(
        '/prospeccao/rodadas/{batch:uuid}',
        'pages::prospecting.show'
    )->name('prospecting.show');

    Route::livewire(
        '/leads',
        'pages::leads.index'
    )->name('leads.index');

    Route::livewire(
        '/leads/gestao',
        'pages::leads.management'
    )->name('leads.management');

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
        '/empresas/{company:uuid}/filiais/nova',
        'pages::companies.branches.create'
    )->name('companies.branches.create');

    Route::livewire(
        '/empresas/{company:uuid}',
        'pages::companies.show'
    )->name('companies.show');
});
