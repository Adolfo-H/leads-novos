<?php

declare(strict_types=1);

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('applies a strong dark text color to titles on light Importacoes Figma cards', function (): void {
    $entry = file_get_contents(resource_path('css/app.css'));
    $fix = file_get_contents(resource_path('css/imports-figma-consolidated.css'));

    expect($entry)->not->toBeFalse()
        ->and($fix)->not->toBeFalse()
        ->and(substr_count($entry, "@import './imports-figma-consolidated.css';"))->toBe(1)
        ->and($fix)->toContain('.ecim-page.ecimv2.ecim65-page')
        ->toContain('.im65-content :is(h1, h2)')
        ->toContain('.im65-hero h1')
        ->toContain('.im65-card h2')
        ->toContain('-webkit-text-fill-color: #17364b !important')
        ->toContain('-webkit-text-fill-color: #183a50 !important');
});

it('preserves the actual titles on both import creation and batch detail screens', function (): void {
    Http::preventStrayRequests();
    Queue::fake();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($manager)->get(route('imports.index'))
        ->assertOk()
        ->assertSee('Importar uma lista de CNPJs')
        ->assertSee('Cole sua lista')
        ->assertSee('Importações recentes');

    $batch = ImportBatch::query()->create([
        'user_id' => $manager->id,
        'source_type' => 'manual',
        'status' => 'completed',
        'total_rows' => 1,
        'valid_rows' => 1,
        'processed_rows' => 1,
    ]);

    $batch->items()->create([
        'row_number' => 1,
        'raw_cnpj' => '11222333000181',
        'normalized_cnpj' => '11222333000181',
        'status' => 'completed',
    ]);

    Livewire::actingAs($manager)->test('pages::imports.index')
        ->call('openBatch', $batch->id)
        ->assertSee('Lote IMP-')
        ->assertSee('Pesquisar no lote')
        ->assertSee('Registros do lote')
        ->assertSee('Inteligência comercial do lote');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
