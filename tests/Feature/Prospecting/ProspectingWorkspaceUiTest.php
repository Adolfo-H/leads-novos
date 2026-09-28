<?php

use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

function prospectingWorkspaceManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array<string, mixed> */
function prospectingWorkspaceCandidate(): array
{
    return [
        'cnpj_root' => '06315338',
        'cnpj' => '06315338003134',
        'corporate_name' => 'CANDIDATO TESTE DE INTERFACE',
        'state' => 'MT',
        'matched_cnae' => '4622200',
        'cnae_match_type' => 'primary',
        'primary_cnae' => '4622200',
        'share_capital' => 1500000,
        'size_code' => '05',
        'legal_nature_code' => '2054',
        'active_establishments' => 3,
        'active_states' => 2,
        'discovery_score' => 85,
    ];
}

it('renders the compact workspace without the old globe', function () {
    $this->actingAs(prospectingWorkspaceManager())
        ->get(route('prospecting.index'))
        ->assertSuccessful()
        ->assertSee('data-test="prospecting-workspace"', false)
        ->assertSee('Configurar filtros')
        ->assertSee('Atividades prioritárias')
        ->assertSee('Histórico de prospecção')
        ->assertDontSee('ec-prospecting-global-visual', false);

    Http::assertNothingSent();
});

it('validates empty filter selections without making a request', function () {
    Livewire::actingAs(prospectingWorkspaceManager())
        ->test('pages::prospecting.index')
        ->set('selectedStates', [])
        ->set('selectedCnaes', [])
        ->call('search')
        ->assertHasErrors(['selectedStates', 'selectedCnaes']);

    Http::assertNothingSent();
    expect(ImportBatch::query()->count())->toBe(0);
});

it('renders the real preview while keeping it separate from CRM validation', function () {
    config(['services.receita_local.base_url' => 'http://receita-data:8000']);
    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [prospectingWorkspaceCandidate()],
            'count' => 1,
            'limit' => 50,
            'offset' => 0,
            'filters' => ['states' => ['MT'], 'cnaes' => ['4622200']],
        ]),
    ]);

    Livewire::actingAs(prospectingWorkspaceManager())
        ->test('pages::prospecting.index')
        ->set('limit', 1)
        ->set('selectedStates', ['MT'])
        ->set('selectedCnaes', ['4622200'])
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee('CANDIDATO TESTE DE INTERFACE')
        ->assertSee('06.315.338/0031-34')
        ->assertSee('Detalhes cadastrais')
        ->assertSee('CRM ainda não validado nesta etapa')
        ->call('confirmExecution')
        ->assertSet('confirmingExecution', true)
        ->assertSee('Confirmar e executar')
        ->call('cancelExecution')
        ->assertSet('confirmingExecution', false);

    expect(ImportBatch::query()->count())->toBe(0);
});

it('links the completed dispatch message to the exact run instead of the imports list', function () {
    $batch = ImportBatch::query()->create(['source_type' => 'prospecting', 'status' => 'ready']);

    Livewire::actingAs(prospectingWorkspaceManager())
        ->test('pages::prospecting.index')
        ->set('executionMessage', '2 empresas enviadas para qualificação.')
        ->set('executedBatchUuid', (string) $batch->uuid)
        ->set('dispatchedCount', 2)
        ->assertSee('Acompanhar rodada')
        ->assertSee(route('prospecting.show', $batch), false)
        ->assertSee('próximos candidatos disponíveis');
});

it('renders an empty run without dividing by zero', function () {
    $batch = ImportBatch::query()->create([
        'source_type' => 'prospecting', 'status' => 'completed',
        'total_rows' => 0, 'processed_rows' => 0,
    ]);

    $this->actingAs(prospectingWorkspaceManager())->get(route('prospecting.show', $batch))
        ->assertSuccessful()
        ->assertSee('data-test="prospecting-run-workspace"', false)
        ->assertSee('aria-valuenow="0"', false)
        ->assertSee('Nenhuma empresa neste filtro')
        ->assertSee('Exportar Excel');
});

it('keeps polling while an export research is queued even after the batch is complete', function () {
    $company = Company::query()->create([
        'cnpj_root' => '88001122', 'corporate_name' => 'PESQUISA PENDENTE TESTE',
    ]);
    $company->exportIntelligence()->create(['research_status' => 'queued']);
    $batch = ImportBatch::query()->create([
        'source_type' => 'prospecting', 'status' => 'completed',
        'total_rows' => 1, 'processed_rows' => 1,
    ]);
    $batch->items()->create([
        'row_number' => 1, 'raw_cnpj' => '88001122000100',
        'normalized_cnpj' => '88001122000100', 'status' => 'completed',
        'company_id' => $company->id,
    ]);

    $this->actingAs(prospectingWorkspaceManager())->get(route('prospecting.show', $batch))
        ->assertSuccessful()
        ->assertSee('wire:poll.visible.10s', false)
        ->assertSee('Na fila')
        ->assertSee('Pesquisa pública')
        ->assertSee('pr-detail-', false);
});

it('renders failures and filters them using the existing filter property', function () {
    $batch = ImportBatch::query()->create([
        'source_type' => 'prospecting', 'status' => 'completed',
        'total_rows' => 1, 'processed_rows' => 1,
    ]);
    $batch->items()->create([
        'row_number' => 1, 'raw_cnpj' => '88001122000200',
        'normalized_cnpj' => '88001122000200', 'status' => 'failed',
        'error_message' => 'Falha simulada no cadastro',
    ]);

    Livewire::actingAs(prospectingWorkspaceManager())
        ->test('pages::prospecting.show', ['batch' => $batch])
        ->set('filter', 'failed')
        ->assertSee('Falha simulada no cadastro')
        ->assertSee('Dossiê indisponível')
        ->set('filter', 'leads')
        ->assertSee('Nenhuma empresa neste filtro')
        ->assertDontSee('Falha simulada no cadastro');
});

it('keeps the manager restriction on both prospecting routes', function () {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $batch = ImportBatch::query()->create(['source_type' => 'prospecting', 'status' => 'completed']);

    $this->actingAs($seller)->get(route('prospecting.index'))->assertForbidden();
    $this->actingAs($seller)->get(route('prospecting.show', $batch))->assertForbidden();
});

it('does not offer a misleading Excel export for unsupported CRM filters', function () {
    $batch = ImportBatch::query()->create([
        'source_type' => 'prospecting', 'status' => 'completed',
        'total_rows' => 0, 'processed_rows' => 0,
    ]);

    Livewire::actingAs(prospectingWorkspaceManager())
        ->test('pages::prospecting.show', ['batch' => $batch])
        ->set('filter', 'new_crm')
        ->assertSee('O filtro atual não é suportado pelo exportador.')
        ->assertSee('title="O exportador atual não suporta este filtro"', false)
        ->set('filter', 'all')
        ->assertDontSee('O filtro atual não é suportado pelo exportador.');
});
