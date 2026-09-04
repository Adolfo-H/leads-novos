<?php

use App\Jobs\EnrichImportItem;
use App\Models\Company;
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
        ->assertSee('Válidos')
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

it('shows commercial alerts for CRM conflicts', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '11222333',

        'corporate_name' => 'Empresa com Divergência CRM',
    ]);

    $company->crmCheck()->create([
        'provider' => 'hubspot',

        'status' => 'client',

        'lifecycle_stage' => 'opportunity',

        'contacted_count' => 10,

        'associated_deals_count' => 2,

        'metadata' => [
            'status_source' => 'exportcontrol_customer_registry',

            'crm_checked' => true,

            'crm_reported_status' => 'opportunity',

            'crm_conflict' => true,
        ],

        'checked_at' => now(),
    ]);

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,

        'source_type' => 'manual',

        'status' => 'completed',

        'total_rows' => 1,

        'valid_rows' => 1,

        'processed_rows' => 1,
    ]);

    ImportItem::query()->create([
        'import_batch_id' => $batch->id,

        'row_number' => 1,

        'raw_cnpj' => '11.222.333/0001-81',

        'normalized_cnpj' => '11222333000181',

        'status' => 'completed',

        'company_id' => $company->id,

        'metadata' => [
            'provider' => 'receita-local',

            'group_enrichment' => true,

            'crm' => [
                'checked' => true,

                'status' => 'client',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(
            'pages::imports.index'
        )
        ->set(
            'batchId',
            $batch->id
        )
        ->assertSee('Alertas')
        ->assertSee(
            'Divergência CRM'
        )
        ->assertSee(
            'Prospector: Cliente'
        )
        ->assertSee(
            'HubSpot: Oportunidade'
        );
});
