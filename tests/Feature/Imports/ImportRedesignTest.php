<?php

use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use Livewire\Livewire;

it('filters imported companies by name and processing status', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $alpha = Company::query()->create([
        'cnpj_root' => '12345678',
        'corporate_name' => 'Empresa Alpha do Agronegócio',
    ]);
    $beta = Company::query()->create([
        'cnpj_root' => '87654321',
        'corporate_name' => 'Empresa Beta da Indústria',
    ]);

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'source_type' => 'manual',
        'status' => 'completed',
        'total_rows' => 2,
        'valid_rows' => 2,
    ]);

    ImportItem::query()->create([
        'import_batch_id' => $batch->id,
        'row_number' => 1,
        'raw_cnpj' => '12345678000100',
        'normalized_cnpj' => '12345678000100',
        'status' => 'completed',
        'company_id' => $alpha->id,
    ]);
    ImportItem::query()->create([
        'import_batch_id' => $batch->id,
        'row_number' => 2,
        'raw_cnpj' => '87654321000100',
        'normalized_cnpj' => '87654321000100',
        'status' => 'failed',
        'company_id' => $beta->id,
        'error_message' => 'Falha de integração simulada',
    ]);

    Livewire::actingAs($user)
        ->test('pages::imports.index')
        ->set('batchId', $batch->id)
        ->set('search', 'alpha')
        ->assertSee('Empresa Alpha do Agronegócio')
        ->assertDontSee('Empresa Beta da Indústria')
        ->set('search', '')
        ->set('statusFilter', 'failed')
        ->assertSee('Empresa Beta da Indústria')
        ->assertDontSee('Empresa Alpha do Agronegócio');
});

it('expands import history beyond the three most recent batches', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    foreach (range(1, 4) as $number) {
        ImportBatch::query()->create([
            'user_id' => $user->id,
            'source_type' => 'manual',
            'status' => 'completed',
            'total_rows' => $number,
        ]);
    }

    Livewire::actingAs($user)
        ->test('pages::imports.index')
        ->assertSee('Ver mais lotes')
        ->call('showMoreHistory')
        ->assertDontSee('Ver mais lotes')
        ->assertSee('Lote #1');
});
