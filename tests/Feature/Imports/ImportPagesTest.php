<?php

use App\Jobs\EnrichImportItem;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use App\Support\Cnpj;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('renders the imports page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->get(route('imports.index'))
        ->assertSuccessful()
        ->assertSee('Importações')
        ->assertSee('Processar CNPJs');
});

it('processes a pasted cnpj list', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $base = '112223330002';

    $validCnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    Livewire::actingAs($user)
        ->test('pages::imports.index')
        ->set(
            'input',
            $validCnpj
            ."\n00.000.000/0000-00"
        )
        ->call('process')
        ->assertHasNoErrors()
        ->assertSee('Resultado do lote')
        ->assertSee('Prontos')
        ->assertSee('Inválidos');

    expect(
        ImportBatch::query()
            ->count()
    )->toBe(1);

    expect(
        ImportItem::query()
            ->count()
    )->toBe(2);
});

it('does not allow guests to access imports', function () {
    $this
        ->get(route('imports.index'))
        ->assertRedirect(route('login'));
});

it('queues ready cnpjs from the imports page', function () {
    Queue::fake();

    config([
        'services.apibrasil.token' => 'fake-token',
    ]);

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $base = '334445550001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $component =
        Livewire::actingAs($user)
            ->test(
                'pages::imports.index'
            )
            ->set(
                'input',
                $cnpj
            )
            ->call('process')
            ->assertHasNoErrors()
            ->call('queueCurrentBatch')
            ->assertHasNoErrors();

    expect(
        ImportItem::query()
            ->firstOrFail()
            ->status
    )->toBe('queued');

    Queue::assertPushed(
        EnrichImportItem::class,
        1
    );
});
