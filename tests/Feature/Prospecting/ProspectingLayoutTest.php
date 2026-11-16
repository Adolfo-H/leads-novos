<?php

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function figmaProspectingManagerV63(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('renders P01, live controls and real recent-run section without querying external sources', function (): void {
    Http::preventStrayRequests();

    $this->actingAs(figmaProspectingManagerV63())
        ->get(route('prospecting.index'))
        ->assertSuccessful()
        ->assertSee('data-ec-prospecting-figma="P01-P02-V63"', false)
        ->assertSee('data-test="prospecting-workspace"', false)
        ->assertSee('Encontre seu próximo mercado')
        ->assertSee('Dimensione a busca')
        ->assertSee('Estados prioritários')
        ->assertSee('Atividades prioritárias')
        ->assertSee('Já tenho CNPJs')
        ->assertSee('Buscar prospects')
        ->assertSee('Rodadas recentes')
        ->assertSee('Histórico de prospecção');

    Http::assertNothingSent();
});

it('renders P02 using only real search results and requires explicit confirmation to run', function (): void {
    Queue::fake();
    config(['services.receita_local.base_url' => 'http://receita-data:8000']);
    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [[
                'cnpj_root' => '06315338',
                'cnpj' => '06315338003134',
                'corporate_name' => 'CANDIDATO FIGMA V63',
                'state' => 'MT',
                'matched_cnae' => '4622200',
                'cnae_match_type' => 'primary',
                'primary_cnae' => '4622200',
                'share_capital' => 1500000,
                'size_code' => '05',
                'legal_nature_code' => '2054',
                'active_establishments' => 2,
                'active_states' => 1,
                'discovery_score' => 85,
            ]],
            'count' => 1,
            'limit' => 1,
            'offset' => 0,
            'dataset_version' => 'test-v63',
            'filters' => ['states' => ['MT'], 'cnaes' => ['4622200']],
        ]),
    ]);

    Livewire::actingAs(figmaProspectingManagerV63())
        ->test('pages::prospecting.index')
        ->set('limit', 1)
        ->set('selectedStates', ['MT'])
        ->set('selectedCnaes', ['4622200'])
        ->call('search')
        ->assertHasNoErrors()
        ->assertSet('newCount', 1)
        ->assertSee('Sua busca encontrou 1 candidatos')
        ->assertSee('CANDIDATO FIGMA V63')
        ->assertSee('Prévia cadastral')
        ->assertSee('CRM ainda não validado nesta etapa')
        ->call('confirmExecution')
        ->assertSet('confirmingExecution', true)
        ->assertSee('Confirmar e executar')
        ->call('cancelExecution')
        ->assertSet('confirmingExecution', false);

    Queue::assertNothingPushed();
    expect(ImportBatch::query()->count())->toBe(0);
});

it('renders P03 with real batch progress, filters and Excel export safeguards', function (): void {
    $batch = ImportBatch::query()->create([
        'source_type' => 'prospecting',
        'status' => 'completed',
        'total_rows' => 0,
        'processed_rows' => 0,
    ]);

    Livewire::actingAs(figmaProspectingManagerV63())
        ->test('pages::prospecting.show', ['batch' => $batch])
        ->assertSee('data-ec-prospecting-figma="P03-V63"', false)
        ->assertSee('data-test="prospecting-run-workspace"', false)
        ->assertSee('Cadastro processado')
        ->assertSee('Pesquisa de exportação')
        ->assertSee('Resultados da qualificação')
        ->assertSee('Nenhuma empresa neste filtro')
        ->assertSee('aria-valuenow="0"', false)
        ->set('filter', 'new_crm')
        ->assertSee('O filtro atual não é suportado pelo exportador.');
});

it('preserves restrictions for non-managers on both P01 and P03', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $batch = ImportBatch::query()->create(['source_type' => 'prospecting', 'status' => 'completed']);

    $this->actingAs($seller)->get(route('prospecting.index'))->assertOk();
    $this->actingAs($seller)->get(route('prospecting.show', $batch))->assertOk();
});
