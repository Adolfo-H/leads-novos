<?php

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('does not show failures from unrelated items after switching P03 to the leads filter', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $batch = ImportBatch::query()->create([
        'source_type' => 'prospecting',
        'status' => 'completed',
        'total_rows' => 1,
        'processed_rows' => 1,
    ]);

    $batch->items()->create([
        'row_number' => 1,
        'raw_cnpj' => '88001122000200',
        'normalized_cnpj' => '88001122000200',
        'status' => 'failed',
        'error_message' => 'Falha exclusiva do candidato V63.1',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::prospecting.show', ['batch' => $batch])
        ->set('filter', 'failed')
        ->assertSee('Falha exclusiva do candidato V63.1')
        ->set('filter', 'leads')
        ->assertSee('Nenhuma empresa neste filtro')
        ->assertDontSee('Falha exclusiva do candidato V63.1')
        ->set('filter', 'all')
        ->assertSee('Falha exclusiva do candidato V63.1');
});

it('keeps the complete P03 implementation while tying its error banner to filtered results', function (): void {
    $view = file_get_contents(resource_path(
        'views/pages/prospecting/partials/figma-show-v63.blade.php'
    ));

    expect($view)
        ->toContain('data-ec-prospecting-figma="P03-V63"')
        ->toContain('$items = $this->filteredItems;')
        ->toContain('$firstFailure = collect($items)->first')
        ->not->toContain('$firstFailure = collect($allItems)->first')
        ->toContain('wire:click="exportExcel"')
        ->toContain('prv63-results-card');
});
