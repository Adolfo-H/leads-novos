<?php

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('displays the candidate search only after a real preview was requested', function (): void {
    Queue::fake();
    Http::preventStrayRequests();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    config(['services.receita_local.base_url' => 'http://receita-data:8000']);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [[
                'cnpj_root' => '06315338',
                'cnpj' => '06315338003134',
                'corporate_name' => 'EMPRESA CANDIDATA TESTE V633',
                'state' => 'MT',
                'matched_cnae' => '4622200',
                'cnae_match_type' => 'primary',
                'primary_cnae' => '4622200',
                'share_capital' => 1500000,
                'size_code' => '05',
                'legal_nature_code' => '2054',
                'active_establishments' => 3,
                'active_states' => 1,
                'discovery_score' => 85,
            ]],
            'count' => 1,
            'limit' => 50,
            'offset' => 0,
            'dataset_version' => 'fixture-v633',
            'filters' => ['states' => ['MT'], 'cnaes' => ['4622200']],
        ]),
    ]);

    Livewire::actingAs($manager)
        ->test('pages::prospecting.index')
        ->assertDontSee('Buscar candidato...')
        ->set('limit', 1)
        ->set('selectedStates', ['MT'])
        ->set('selectedCnaes', ['4622200'])
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee('Prévia cadastral')
        ->assertSee('EMPRESA CANDIDATA TESTE V633')
        ->assertSee('Buscar candidato...');

    expect(ImportBatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
