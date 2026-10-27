<?php

use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Cnpj;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function importsFigmaV65Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

it('shows the I01 Figma layout with a manual list and real history', function (): void {
    $this->actingAs(importsFigmaV65Manager())
        ->get(route('imports.index'))
        ->assertSuccessful()
        ->assertSee('data-ec-imports-figma="I01-I02-V65"', false)
        ->assertSee('Importar uma lista de CNPJs')
        ->assertSee('Processar CNPJs')
        ->assertSee('Histórico de lotes')
        ->assertSee('Importações recentes')
        ->assertSee('Duas etapas distintas')
        ->assertDontSee('ec-imports-hero', false);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('validates the list before offering enrichment, without making API requests', function (): void {
    $cnpjBase = '112223330002';
    $cnpj = $cnpjBase.Cnpj::calculateCheckDigits($cnpjBase);

    Livewire::actingAs(importsFigmaV65Manager())
        ->test('pages::imports.index')
        ->set('input', $cnpj."\n00.000.000/0000-00")
        ->call('process')
        ->assertHasNoErrors()
        ->assertSet('input', '')
        ->assertSee('Resultado do lote')
        ->assertSee('Válidos novos')
        ->assertSee('Duplicados / Inválidos')
        ->assertSee('Aguardando enriquecimento')
        ->assertSee('Iniciar enriquecimento')
        ->assertDontSee('Enriquecimento concluído');

    expect(ImportBatch::query()->count())->toBe(1);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('renders I02 failures with their actual diagnostics and never reports success instead', function (): void {
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
        'error_message' => 'Falha simulada na Receita para o teste V65.',
    ]);

    Livewire::actingAs(importsFigmaV65Manager())
        ->test('pages::imports.index')
        ->call('openBatch', $batch->id)
        ->assertSee('Finalizado com falhas')
        ->assertSee('Diagnóstico por registro')
        ->assertSee('Falha simulada na Receita para o teste V65.')
        ->assertSee('Inteligência comercial do lote')
        ->assertSee('aria-valuenow="100"', false)
        ->assertDontSee('Enriquecimento concluído');
});

it('keeps earlier batches accessible from the new I01 history table', function (): void {
    foreach (range(1, 4) as $number) {
        ImportBatch::query()->create([
            'source_type' => 'manual',
            'status' => 'completed',
            'total_rows' => $number,
        ]);
    }

    Livewire::actingAs(importsFigmaV65Manager())
        ->test('pages::imports.index')
        ->assertSee('Ver mais lotes')
        ->call('showMoreHistory')
        ->assertDontSee('Ver mais lotes')
        ->assertSee('Lote #1');
});
