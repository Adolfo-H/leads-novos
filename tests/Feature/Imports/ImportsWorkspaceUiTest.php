<?php

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function importsWorkspaceManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

it('renders the imports workspace without the previous hero', function () {
    $this->actingAs(importsWorkspaceManager())->get(route('imports.index'))
        ->assertSuccessful()
        ->assertSee('ecim-page', false)
        ->assertSee('Nova importação')
        ->assertSee('Processar CNPJs')
        ->assertSee('Importações recentes')
        ->assertDontSee('ec-imports-hero', false);
});

it('keeps validation separate from enrichment', function () {
    Livewire::actingAs(importsWorkspaceManager())
        ->test('pages::imports.index')
        ->set('input', "11.222.333/0001-81\n00.000.000/0000-00")
        ->call('process')
        ->assertHasNoErrors()
        ->assertSet('input', '')
        ->assertSee('Resultado do lote')
        ->assertSee('Aguardando enriquecimento')
        ->assertSee('Iniciar enriquecimento')
        ->assertDontSee('Enriquecimento concluído');

    expect(ImportBatch::query()->count())->toBe(1);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('does not show invalid-only batches as enriched', function () {
    $batch = ImportBatch::query()->create([
        'source_type' => 'manual',
        'status' => 'completed',
        'total_rows' => 1,
        'invalid_rows' => 1,
    ]);

    $batch->items()->create([
        'row_number' => 1,
        'raw_cnpj' => '00000000000000',
        'status' => 'invalid',
        'error_message' => 'CNPJ inválido para teste.',
    ]);

    Livewire::actingAs(importsWorkspaceManager())
        ->test('pages::imports.index')
        ->call('openBatch', $batch->id)
        ->assertSet('batchId', $batch->id)
        ->assertSee('Validação concluída')
        ->assertSee('CNPJ inválido')
        ->assertDontSee('Enriquecimento concluído');
});

it('keeps polling and ready actions available during processing', function () {
    $batch = ImportBatch::query()->create([
        'source_type' => 'manual',
        'status' => 'processing',
        'total_rows' => 2,
        'valid_rows' => 2,
    ]);

    $batch->items()->create([
        'row_number' => 1,
        'raw_cnpj' => '11222333000181',
        'status' => 'queued',
    ]);

    $batch->items()->create([
        'row_number' => 2,
        'raw_cnpj' => '11222333000262',
        'status' => 'ready',
    ]);

    Livewire::actingAs(importsWorkspaceManager())
        ->test('pages::imports.index')
        ->call('openBatch', $batch->id)
        ->assertSee('Enriquecendo')
        ->assertSee('Iniciar enriquecimento')
        ->assertSee('wire:poll.2s="refreshCurrentBatch"', false)
        ->assertDontSee('Enriquecimento concluído');

    Queue::assertNothingPushed();
});

it('keeps failures visible instead of showing a successful completion', function () {
    $batch = ImportBatch::query()->create([
        'source_type' => 'manual',
        'status' => 'completed',
        'total_rows' => 1,
        'valid_rows' => 1,
        'processed_rows' => 1,
    ]);

    $batch->items()->create([
        'row_number' => 1,
        'raw_cnpj' => '11222333000181',
        'status' => 'failed',
        'error_message' => 'Falha simulada no provedor.',
    ]);

    Livewire::actingAs(importsWorkspaceManager())
        ->test('pages::imports.index')
        ->call('openBatch', $batch->id)
        ->assertSee('Finalizado com falhas')
        ->assertSee('Falha simulada no provedor.')
        ->assertSee('aria-valuenow="100"', false)
        ->assertDontSee('Enriquecimento concluído');
});
