<?php

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function prospectingRedesignManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

it('renders the redesigned discovery workspace with the existing business steps', function () {
    $this->actingAs(prospectingRedesignManager())
        ->get(route('prospecting.index'))
        ->assertSuccessful()
        ->assertSee('data-test="prospecting-workspace"', false)
        ->assertSee('prv2-page', false)
        ->assertSee('Configurar filtros')
        ->assertSee('Revisar prévia')
        ->assertSee('Confirmar envio')
        ->assertSee('Acompanhar rodada')
        ->assertSee('Atividades prioritárias')
        ->assertSee('Histórico de prospecção');

    Http::assertNothingSent();
});

it('previews companies without creating a batch before the final confirmation', function () {
    config(['services.receita_local.base_url' => 'http://receita-data:8000']);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [[
                'cnpj_root' => '06315338',
                'cnpj' => '06315338003134',
                'corporate_name' => 'CANDIDATO VISUAL TESTE',
                'state' => 'MT',
                'matched_cnae' => '4622200',
                'cnae_match_type' => 'primary',
                'primary_cnae' => '4622200',
                'share_capital' => 1000000,
                'size_code' => '05',
                'legal_nature_code' => '2054',
                'active_establishments' => 2,
                'active_states' => 1,
                'discovery_score' => 90,
            ]],
            'count' => 1,
            'limit' => 50,
            'offset' => 0,
            'filters' => ['states' => ['MT'], 'cnaes' => ['4622200']],
        ]),
    ]);

    Livewire::actingAs(prospectingRedesignManager())
        ->test('pages::prospecting.index')
        ->set('limit', 1)
        ->set('selectedStates', ['MT'])
        ->set('selectedCnaes', ['4622200'])
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee('CANDIDATO VISUAL TESTE')
        ->assertSee('Detalhes cadastrais')
        ->assertSee('CRM ainda não validado nesta etapa')
        ->call('confirmExecution')
        ->assertSet('confirmingExecution', true)
        ->assertSee('Confirmar e executar');

    expect(ImportBatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('preserves run detail status, progress, filters and export button', function () {
    $batch = ImportBatch::query()->create([
        'source_type' => 'prospecting',
        'status' => 'completed',
        'total_rows' => 0,
        'processed_rows' => 0,
    ]);

    $this->actingAs(prospectingRedesignManager())
        ->get(route('prospecting.show', $batch))
        ->assertSuccessful()
        ->assertSee('data-test="prospecting-run-workspace"', false)
        ->assertSee('Processamento da rodada')
        ->assertSee('aria-valuenow="0"', false)
        ->assertSee('Empresas da rodada')
        ->assertSee('Nenhuma empresa neste filtro')
        ->assertSee('Exportar Excel');
});
