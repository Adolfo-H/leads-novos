<?php

use App\Services\ProspectingEngineService;
use App\Services\ProspectingRunSummaryService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $limit = 20;

    /**
     * @var list<string>
     */
    public array $selectedStates = [
        'MA',
        'SP',
        'PA',
        'RO',
        'MT',
        'MS',
        'TO',
        'GO',
        'MG',
    ];

    /**
     * @var list<string>
     */
    public array $selectedCnaes = [
        '4622200',
        '4632001',
        '0115600',
        '0111302',
        '1071600',
    ];

    /**
     * @var array<string, string>
     */
    public array $stateOptions = [
        'MA' => 'Maranhão',
        'SP' => 'São Paulo',
        'PA' => 'Pará',
        'RO' => 'Rondônia',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'TO' => 'Tocantins',
        'GO' => 'Goiás',
        'MG' => 'Minas Gerais',
    ];

    /**
     * @var array<string, string>
     */
    public array $cnaeOptions = [
        '4622200' => 'Comércio atacadista de soja',
        '4632001' => 'Comércio atacadista de cereais',
        '0115600' => 'Cultivo de soja',
        '0111302' => 'Cultivo de milho',
        '1071600' => 'Fabricação de açúcar em bruto',
    ];

    /**
     * @var list<array<string, mixed>>
     */
    public array $prospects = [];

    public ?string $error = null;

    public bool $searched = false;

    public int $discoveredCount = 0;

    public int $knownCount = 0;

    public int $newCount = 0;

    public bool $confirmingExecution = false;

    public ?string $executionMessage = null;

    public ?string $executedBatchUuid = null;

    public int $dispatchedCount = 0;

    public function selectAllStates(): void
    {
        $this->selectedStates =
            array_keys(
                $this->stateOptions
            );
    }

    public function clearStates(): void
    {
        $this->selectedStates = [];
    }

    public function selectAllCnaes(): void
    {
        $this->selectedCnaes =
            array_keys(
                $this->cnaeOptions
            );
    }

    public function clearCnaes(): void
    {
        $this->selectedCnaes = [];
    }

    public function search(
        ProspectingEngineService $engine
    ): void {
        $this->validateFilters();

        $this->error = null;

        $this->executionMessage = null;

        $this->executedBatchUuid = null;

        $this->dispatchedCount = 0;

        $this->confirmingExecution = false;

        try {
            $this->loadPreview(
                $engine
            );
        } catch (Throwable $exception) {
            $this->prospects = [];

            $this->searched = true;

            $this->error =
                $exception->getMessage();
        }
    }

    public function confirmExecution(): void
    {
        if ($this->prospects === []) {
            return;
        }

        $this->confirmingExecution = true;
    }

    public function cancelExecution(): void
    {
        $this->confirmingExecution = false;
    }

    public function executeProspecting(
        ProspectingEngineService $engine
    ): void {
        $this->validateFilters();

        if (! $this->confirmingExecution) {
            return;
        }

        $this->error = null;

        try {
            $authId =
                auth()->id();

            $result =
                $engine->execute(
                    limit: $this->limit,
                    states: array_values(
                        $this->selectedStates
                    ),
                    cnaes: array_values(
                        $this->selectedCnaes
                    ),
                    userId: is_numeric(
                        $authId
                    )
                        ? (int) $authId
                        : null,
                );

            $batch =
                $result[
                    'batch'
                ];

            $this->confirmingExecution =
                false;

            if ($batch === null) {
                $this->executionMessage =
                    'Nenhuma empresa nova estava disponível para processamento.';

                $this->executedBatchUuid =
                    null;

                $this->dispatchedCount =
                    0;
            } else {
                $this->executedBatchUuid =
                    (string) $batch->uuid;

                $this->dispatchedCount =
                    $result[
                        'dispatched'
                    ];

                $this->executionMessage =
                    $this->dispatchedCount === 1
                        ? '1 empresa enviada para qualificação.'
                        : $this->dispatchedCount
                            .' empresas enviadas para qualificação.';
            }

            /*
             * Depois da execução, atualizamos
             * a tela para mostrar o próximo
             * conjunto de empresas inéditas.
             */
            $this->loadPreview(
                $engine
            );

        } catch (Throwable $exception) {
            $this->confirmingExecution =
                false;

            $this->error =
                $exception->getMessage();
        }
    }

    private function loadPreview(
        ProspectingEngineService $engine
    ): void {
        $result =
            $engine->preview(
                limit: $this->limit,
                states: array_values(
                    $this->selectedStates
                ),
                cnaes: array_values(
                    $this->selectedCnaes
                ),
            );

        $this->prospects =
            $result[
                'items'
            ];

        $this->discoveredCount =
            $result[
                'discovered_count'
            ];

        $this->knownCount =
            $result[
                'known_count'
            ];

        $this->newCount =
            $result[
                'new_count'
            ];

        $this->searched = true;
    }

    private function validateFilters(): void
    {
        $this->validate([
            'limit' => [
                'required',
                'integer',
                'min:1',
                'max:200',
            ],

            'selectedStates' => [
                'required',
                'array',
                'min:1',
            ],

            'selectedCnaes' => [
                'required',
                'array',
                'min:1',
            ],
        ], [
            'limit.required' => 'Selecione a quantidade de candidatos.',

            'limit.integer' => 'A quantidade de candidatos deve ser um número inteiro.',

            'limit.min' => 'A quantidade mínima é de 1 candidato.',

            'limit.max' => 'A quantidade máxima é de 200 candidatos.',

            'selectedStates.required' => 'Selecione pelo menos um estado.',

            'selectedStates.array' => 'A seleção de estados é inválida.',

            'selectedStates.min' => 'Selecione pelo menos um estado.',

            'selectedCnaes.required' => 'Selecione pelo menos um CNAE.',

            'selectedCnaes.array' => 'A seleção de CNAEs é inválida.',

            'selectedCnaes.min' => 'Selecione pelo menos um CNAE.',
        ]);
    }

    #[Computed]
    public function recentRuns(): array
    {
        return app(
            ProspectingRunSummaryService::class
        )->recent(
            10
        );
    }

    public function runStatusLabel(
        string $status
    ): string {
        return match ($status) {
            'completed' => 'Concluído',

            'processing' => 'Processando',

            'failed' => 'Falhou',

            'ready' => 'Pronto',

            default => ucfirst($status),
        };
    }

    public function capital(
        mixed $value
    ): string {
        if (! is_numeric($value)) {
            return '—';
        }

        return 'R$ '
            .number_format(
                (float) $value,
                0,
                ',',
                '.'
            );
    }
};
?>

{{--
    Motor de Prospecção · ExportControl · Workspace V2
    Este arquivo é apenas a interface: toda a lógica Livewire permanece
    no componente pages::prospecting.index original.
--}}
<div
    class="ec-page-shell pr-workspace prv2-page"
    data-test="prospecting-workspace"
    x-data="{
        ufQuery: '', cnaeQuery: '', candidateQuery: '',
        dirty: false, filtersOpen: true,
        matches(query, value) {
            const normalize = text => String(text || '').normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '').toLowerCase();
            return normalize(value).includes(normalize(query));
        }
    }"
>
    @php
        $runs = $this->recentRuns;
        $previewCount = count($prospects);
        $stage = $executedBatchUuid ? 4 : ($confirmingExecution ? 3 : ($searched && $previewCount > 0 ? 2 : 1));
        $number = static fn ($value) => number_format((int) $value, 0, ',', '.');
        $noveltyRate = $discoveredCount > 0
            ? (int) round(min(100, max(0, $newCount * 100 / $discoveredCount)))
            : 0;
        $noveltyLabel = $discoveredCount > 0
            ? number_format(min(100, max(0, $newCount * 100 / $discoveredCount)), 1, ',', '.').'%' : '—';
        $steps = [
            ['name' => 'Configurar filtros', 'hint' => 'Defina os critérios de busca'],
            ['name' => 'Revisar prévia', 'hint' => 'Analise os candidatos encontrados'],
            ['name' => 'Confirmar envio', 'hint' => 'Valide antes de qualificar'],
            ['name' => 'Acompanhar rodada', 'hint' => 'Consulte os resultados'],
        ];
    @endphp

    <header class="prv2-header">
        <div class="prv2-header-icon" aria-hidden="true">
            @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
        </div>
        <div class="prv2-title-block">
            <span class="prv2-overline">DESCOBERTA COMERCIAL</span>
            <h1>Motor de Prospecção</h1>
            <p>Encontre novas oportunidades comerciais na base local da Receita, com critérios claros e qualificação controlada.</p>
        </div>
        <div class="prv2-header-right">
            <div class="prv2-header-insight">
                <span class="prv2-insight-symbol" aria-hidden="true">◎</span>
                <span><strong>Mais mercados. Mais oportunidades.</strong><small>Busca cadastral, análise ICP e CRM em etapas separadas.</small></span>
            </div>
            <a class="prv2-button prv2-button-outline" href="#pr-history">
                @include('pages.prospecting.partials.workspace-icon', ['name' => 'history'])
                Histórico
            </a>
        </div>
    </header>

    <ol class="prv2-steps" aria-label="Etapas da prospecção">
        @foreach ($steps as $step)
            <li class="{{ $loop->iteration === $stage ? 'is-current' : ($loop->iteration < $stage ? 'is-done' : '') }}"
                @if ($loop->iteration === $stage) aria-current="step" @endif>
                <span class="prv2-step-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span class="prv2-step-copy"><strong>{{ $step['name'] }}</strong><small>{{ $step['hint'] }}</small></span>
                @if (! $loop->last)<span class="prv2-step-chevron" aria-hidden="true">›</span>@endif
            </li>
        @endforeach
    </ol>

    <form
        id="pr-config"
        class="prv2-panel prv2-search-config"
        wire:submit="search"
        x-on:submit="dirty = false; filtersOpen = false"
        novalidate
    >
        <div class="prv2-panel-heading">
            <div class="prv2-heading-group">
                <span class="prv2-heading-icon" aria-hidden="true">@include('pages.prospecting.partials.workspace-icon', ['name' => 'sliders'])</span>
                <div>
                    <h2>Configurar descoberta</h2>
                    <p>{{ $number($limit) }} candidatos · {{ count($selectedStates) }} estados · {{ count($selectedCnaes) }} CNAEs selecionados</p>
                </div>
            </div>
            <div class="prv2-actions">
                <span class="prv2-source-pill">@include('pages.prospecting.partials.workspace-icon', ['name' => 'list']) Base local da Receita</span>
                <button type="button" class="prv2-button prv2-button-outline" x-on:click="filtersOpen = !filtersOpen" x-bind:aria-expanded="filtersOpen" aria-controls="prv2-filter-body">
                    <span x-text="filtersOpen ? 'Recolher filtros' : 'Editar filtros'">Recolher filtros</span>
                </button>
            </div>
        </div>

        @if ($errors->any())
            <div class="prv2-validation-alert" role="alert" x-init="filtersOpen = true">
                Revise os filtros destacados antes de buscar.
            </div>
        @endif

        <fieldset id="prv2-filter-body" x-show="filtersOpen" wire:loading.attr="disabled" wire:target="search,executeProspecting" @disabled($confirmingExecution)>
            <legend class="prv2-screen-reader">Filtros da descoberta comercial</legend>
            <div class="prv2-filter-grid">
                <section class="prv2-filter-cell">
                    <div class="prv2-field-top">
                        <label for="prv2-other-limit">Quantidade de candidatos</label>
                        <span>Máximo: 200</span>
                    </div>
                    <div class="prv2-quantity-options" role="group" aria-label="Quantidades sugeridas">
                        @foreach ([10, 20, 50, 100] as $quantity)
                            <label class="prv2-quantity-choice">
                                <input type="radio" name="prv2-limit" value="{{ $quantity }}" wire:model.live.number="limit" x-on:change="dirty = true">
                                <span>{{ $quantity }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="prv2-other-limit">
                        <label for="prv2-other-limit">Outra quantidade</label>
                        <input id="prv2-other-limit" type="number" min="1" max="200" step="1" inputmode="numeric" wire:model.blur.number="limit" x-on:input="dirty = true" placeholder="Ex.: 150">
                        <span>1 a 200</span>
                    </div>
                    @error('limit') <p class="prv2-field-error" role="alert">{{ $message }}</p> @enderror
                    <div class="prv2-instruction">
                        <strong>Como funciona?</strong>
                        <p>O motor encontra candidatos na Receita. CRM, ICP e pesquisa pública são tratados durante a qualificação.</p>
                    </div>
                </section>

                <section class="prv2-filter-cell">
                    <div class="prv2-field-top">
                        <h3>Estados <span class="prv2-count">{{ count($selectedStates) }}</span></h3>
                        <div class="prv2-text-actions">
                            <button type="button" wire:click="selectAllStates" x-on:click="dirty = true">Todos</button>
                            <button type="button" wire:click="clearStates" x-on:click="dirty = true">Limpar</button>
                        </div>
                    </div>
                    <label class="prv2-search-input">
                        @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
                        <input type="search" x-model="ufQuery" placeholder="Buscar estado ou sigla..." aria-label="Buscar estados">
                    </label>
                    <div class="prv2-chip-summary" aria-label="Estados selecionados">
                        @foreach ($selectedStates as $uf)
                            <span>{{ $uf }}</span>
                        @endforeach
                    </div>
                    <div class="prv2-state-list" aria-label="Selecionar estados">
                        @foreach ($stateOptions as $uf => $stateName)
                            <label class="prv2-choice prv2-state-choice" wire:key="prv2-uf-{{ $uf }}" data-search="{{ $uf.' '.$stateName }}" x-show="matches(ufQuery, $el.dataset.search)">
                                <input type="checkbox" value="{{ $uf }}" wire:model.live="selectedStates" x-on:change="dirty = true">
                                <span><strong>{{ $uf }}</strong><small>{{ $stateName }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedStates') <p class="prv2-field-error" role="alert">{{ $message }}</p> @enderror
                </section>

                <section class="prv2-filter-cell">
                    <div class="prv2-field-top">
                        <h3>Atividades prioritárias (CNAE) <span class="prv2-count">{{ count($selectedCnaes) }}</span></h3>
                        <div class="prv2-text-actions">
                            <button type="button" wire:click="selectAllCnaes" x-on:click="dirty = true">Todos</button>
                            <button type="button" wire:click="clearCnaes" x-on:click="dirty = true">Limpar</button>
                        </div>
                    </div>
                    <label class="prv2-search-input">
                        @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
                        <input type="search" x-model="cnaeQuery" placeholder="Buscar CNAE ou descrição..." aria-label="Buscar CNAEs">
                    </label>
                    <div class="prv2-chip-summary" aria-label="CNAEs selecionados">
                        @foreach ($selectedCnaes as $code)
                            <span>{{ $code }}</span>
                        @endforeach
                    </div>
                    <div class="prv2-cnae-list" aria-label="Selecionar CNAEs">
                        @foreach ($cnaeOptions as $code => $description)
                            <label class="prv2-choice prv2-cnae-choice" wire:key="prv2-cnae-{{ $code }}" data-search="{{ $code.' '.$description }}" x-show="matches(cnaeQuery, $el.dataset.search)">
                                <input type="checkbox" value="{{ $code }}" wire:model.live="selectedCnaes" x-on:change="dirty = true">
                                <span><strong>{{ $code }}</strong><small>{{ $description }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedCnaes') <p class="prv2-field-error" role="alert">{{ $message }}</p> @enderror
                </section>
            </div>
            <div class="prv2-config-footer">
                <div><strong>Resumo da busca</strong><small>{{ $number($limit) }} candidatos · {{ count($selectedStates) }} estados · {{ count($selectedCnaes) }} CNAEs</small></div>
                <button type="submit" class="prv2-button prv2-button-primary" wire:loading.attr="disabled" wire:target="search,executeProspecting">
                    @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
                    <span wire:loading.remove wire:target="search">Buscar prospects</span>
                    <span wire:loading wire:target="search">Consultando a Receita...</span>
                    <span aria-hidden="true">→</span>
                </button>
            </div>
        </fieldset>
    </form>

    <div wire:loading.flex wire:target="search" class="prv2-notice" role="status">
        <span class="prv2-spinner" aria-hidden="true"></span>
        <div><strong>Consultando a base local</strong><p>Aguarde a prévia. Nenhuma rodada é iniciada nesta etapa.</p></div>
    </div>

    @if ($error)
        <div class="prv2-notice prv2-notice-error" role="alert" wire:key="prv2-operation-error">
            @include('pages.prospecting.partials.workspace-icon', ['name' => 'alert'])
            <div><strong>Não foi possível concluir a operação</strong><p>{{ $error }}</p></div>
        </div>
    @endif

    @if ($executionMessage)
        <div class="prv2-notice {{ $executedBatchUuid ? 'prv2-notice-success' : '' }}" role="status" wire:key="prv2-execution-result">
            @include('pages.prospecting.partials.workspace-icon', ['name' => $executedBatchUuid ? 'check' : 'info'])
            <div class="prv2-grow"><strong>{{ $executedBatchUuid ? 'Prospecção iniciada' : 'Nenhum novo envio' }}</strong>
                <p>{{ $executionMessage }}</p>
                @if ($executedBatchUuid)<small>A prévia abaixo já mostra os próximos candidatos disponíveis.</small>@endif
            </div>
            @if ($executedBatchUuid)
                <a href="{{ route('prospecting.show', $executedBatchUuid) }}" wire:navigate class="prv2-button prv2-button-primary">
                    Acompanhar rodada →
                </a>
            @endif
        </div>
    @endif

    @if ($searched && ! $error)
        <section id="pr-preview" class="prv2-preview" aria-labelledby="prv2-preview-title" wire:key="prv2-preview">
            <div class="prv2-stat-cards" aria-label="Indicadores da prévia">
                <div class="prv2-stat-card">
                    <span class="prv2-stat-icon" aria-hidden="true">@include('pages.prospecting.partials.workspace-icon', ['name' => 'list'])</span>
                    <div><span>Prospects encontrados</span><strong>{{ $number($discoveredCount) }}</strong><small>Candidatos examinados pelo motor</small></div>
                </div>
                <div class="prv2-stat-card">
                    <span class="prv2-stat-icon" aria-hidden="true">@include('pages.prospecting.partials.workspace-icon', ['name' => 'history'])</span>
                    <div><span>Já trabalhados</span><strong>{{ $number($knownCount) }}</strong><small>Conforme as regras de exclusão</small></div>
                </div>
                <div class="prv2-stat-card prv2-stat-highlight">
                    <span class="prv2-stat-icon" aria-hidden="true">@include('pages.prospecting.partials.workspace-icon', ['name' => 'check'])</span>
                    <div><span>Novos disponíveis</span><strong>{{ $number($newCount) }}</strong><small>{{ $number($previewCount) }} exibidos nesta prévia</small></div>
                </div>
                <div class="prv2-stat-card">
                    <span class="prv2-stat-ring" style="--prv2-progress: {{ $noveltyRate }}%" aria-hidden="true"></span>
                    <div><span>Taxa de novidade</span><strong>{{ $noveltyLabel }}</strong><small>Novos sobre descobertos</small></div>
                </div>
            </div>

            <section class="prv2-panel prv2-prospects-panel" aria-labelledby="prv2-preview-title">
                <div class="prv2-panel-heading">
                    <div class="prv2-heading-group">
                        <span class="prv2-heading-icon" aria-hidden="true">@include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])</span>
                        <div><h2 id="prv2-preview-title">Lista de prospects</h2><p>{{ $number($previewCount) }} candidatos nesta pré-visualização · CRM ainda não validado nesta etapa</p></div>
                    </div>
                    @if ($prospects !== [] && ! $errors->any())
                        <button type="button" class="prv2-button prv2-button-primary" wire:click="confirmExecution"
                            x-bind:disabled="dirty || $wire.confirmingExecution" wire:loading.attr="disabled"
                            wire:target="search,confirmExecution,executeProspecting" @disabled($confirmingExecution)>
                            Revisar envio →
                        </button>
                    @endif
                </div>

                <div x-show="dirty" x-cloak class="prv2-inline-warning" role="status">
                    Os filtros foram alterados. Faça uma nova busca antes de confirmar o envio.
                </div>

                @if ($confirmingExecution)
                    <section class="prv2-confirmation" aria-labelledby="prv2-confirm-title" wire:key="prv2-execution-confirmation">
                        <div>
                            <span class="prv2-overline">CONFIRMAÇÃO DE ENVIO</span>
                            <h3 id="prv2-confirm-title">Iniciar a qualificação de até {{ $number($limit) }} empresas?</h3>
                            <p>Esta prévia contém {{ $number($previewCount) }} candidatos. A disponibilidade será revalidada no envio; a quantidade final pode mudar.</p>
                            <div class="prv2-process-tags"><span>Receita</span><span>ICP</span><span>HubSpot</span><span>Pesquisa pública, quando elegível</span></div>
                            <small>A pesquisa pública pode consumir créditos. A seleção de um candidato não confirma exportação.</small>
                        </div>
                        <div class="prv2-confirmation-actions">
                            <button type="button" wire:click="cancelExecution" class="prv2-button prv2-button-outline" wire:loading.attr="disabled" wire:target="executeProspecting">Cancelar</button>
                            <button type="button" wire:click="executeProspecting" x-bind:disabled="dirty" class="prv2-button prv2-button-primary"
                                wire:loading.attr="disabled" wire:target="executeProspecting,cancelExecution">
                                <span wire:loading.remove wire:target="executeProspecting">Confirmar e executar</span>
                                <span wire:loading wire:target="executeProspecting">Enviando...</span>
                            </button>
                        </div>
                    </section>
                @endif

                @if ($prospects !== [])
                    <div class="prv2-list-toolbar">
                        <label class="prv2-search-input prv2-search-table">
                            @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
                            <input type="search" x-model="candidateQuery" placeholder="Filtrar esta prévia por empresa ou CNPJ..." aria-label="Filtrar candidatos exibidos">
                        </label>
                        <span class="prv2-browse-note">A prévia mostra candidatos; a seleção definitiva ocorre no envio.</span>
                    </div>
                    <div class="prv2-table-scroll" role="region" tabindex="0" aria-label="Candidatos da prévia">
                        <table class="prv2-table prv2-candidates-table">
                            <thead><tr>
                                <th scope="col">Empresa / CNPJ</th>
                                <th scope="col">UF</th>
                                <th scope="col">CNAE aderente</th>
                                <th scope="col">Pré-ICP</th>
                                <th scope="col">Unidades ativas</th>
                                <th scope="col" class="prv2-align-right">Capital social</th>
                                <th scope="col">Detalhes</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($prospects as $prospect)
                                    @php
                                        $candidateCnpj = (string) ($prospect['cnpj'] ?? '');
                                        $matchedCnae = (string) ($prospect['matched_cnae'] ?? '');
                                    @endphp
                                    <tr wire:key="prv2-candidate-{{ $candidateCnpj ?: $loop->index }}"
                                        data-search="{{ ($prospect['corporate_name'] ?? '').' '.$candidateCnpj.' '.($prospect['state'] ?? '').' '.$matchedCnae }}"
                                        x-show="matches(candidateQuery, $el.dataset.search)">
                                        <td class="prv2-company-cell">
                                            <strong>{{ $prospect['corporate_name'] ?? 'Empresa sem nome cadastral' }}</strong>
                                            <small>{{ $candidateCnpj !== '' ? \App\Support\Cnpj::format($candidateCnpj) : 'CNPJ não informado' }}</small>
                                        </td>
                                        <td><span class="prv2-uf">{{ $prospect['state'] ?? '—' }}</span></td>
                                        <td><strong>{{ $matchedCnae ?: '—' }}</strong><small>{{ $cnaeOptions[$matchedCnae] ?? ($matchedCnae ?: 'Não informado') }}</small><small>{{ match ($prospect['cnae_match_type'] ?? null) { 'primary' => 'Principal', 'secondary' => 'Secundário', default => 'Vínculo não informado' } }}</small></td>
                                        <td><strong class="prv2-score">{{ $prospect['discovery_score'] ?? '—' }}</strong></td>
                                        <td>{{ $prospect['active_establishments'] ?? '—' }}</td>
                                        <td class="prv2-align-right prv2-nowrap">{{ $this->capital($prospect['share_capital'] ?? null) }}</td>
                                        <td>
                                            <details class="prv2-candidate-details">
                                                <summary>Detalhes cadastrais</summary>
                                                <dl>
                                                    <div><dt>CNPJ raiz</dt><dd>{{ $prospect['cnpj_root'] ?? '—' }}</dd></div>
                                                    <div><dt>Estados com unidades</dt><dd>{{ $prospect['active_states'] ?? 'Não informado' }}</dd></div>
                                                    <div><dt>CNAE encontrado</dt><dd>{{ $cnaeOptions[$matchedCnae] ?? ($matchedCnae ?: '—') }}</dd></div>
                                                </dl>
                                                <p>Prévia cadastral. O dossiê será disponibilizado após a criação da empresa no pipeline.</p>
                                            </details>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="prv2-table-footer"><span>Exibindo {{ $number($previewCount) }} candidatos encontrados para esta prévia</span><span>Pré-ICP é aderência estimada, não comprovação de exportação.</span></div>
                @else
                    <div class="prv2-empty"><h3>Nenhum prospect encontrado</h3><p>Amplie os estados ou CNAEs para uma nova busca.</p></div>
                @endif
            </section>
        </section>
    @endif

    <section class="prv2-panel prv2-history" id="pr-history" aria-labelledby="prv2-history-title">
        <div class="prv2-panel-heading">
            <div class="prv2-heading-group">
                <span class="prv2-heading-icon" aria-hidden="true">@include('pages.prospecting.partials.workspace-icon', ['name' => 'history'])</span>
                <div><h2 id="prv2-history-title">Histórico de prospecção</h2><p>Últimas 10 rodadas registradas no sistema.</p></div>
            </div>
            <div class="prv2-actions">
                <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="prv2-button prv2-button-outline">
                    @include('pages.prospecting.partials.workspace-icon', ['name' => 'refresh']) Atualizar
                </button>
                <a href="{{ route('imports.index') }}" wire:navigate class="prv2-link">Ver importações →</a>
            </div>
        </div>
        @if ($runs !== [])
            <div class="prv2-table-scroll" tabindex="0" role="region" aria-label="Histórico de prospecção">
                <table class="prv2-table prv2-history-table">
                    <thead><tr><th scope="col">Rodada</th><th scope="col">Status</th><th scope="col">Processamento</th><th scope="col">Leads</th><th scope="col">Pesquisados</th><th scope="col">Exportadores</th><th scope="col">Falhas</th><th scope="col">Ação</th></tr></thead>
                    <tbody>
                        @foreach ($runs as $run)
                            @php
                                $runTotal = max(0, (int) ($run['total_rows'] ?? 0));
                                $runProcessed = max(0, (int) ($run['processed_rows'] ?? 0));
                                $runPercent = $runTotal > 0 ? min(100, (int) round($runProcessed * 100 / $runTotal)) : 0;
                                $runStatus = (string) ($run['status'] ?? '');
                                $runTone = match ($runStatus) { 'completed' => 'success', 'failed' => 'danger', 'processing' => 'info', default => 'neutral' };
                            @endphp
                            <tr wire:key="prv2-run-{{ $run['uuid'] }}">
                                <td class="prv2-company-cell">
                                    <a href="{{ route('prospecting.show', $run['uuid']) }}" wire:navigate class="prv2-row-link">{{ ! empty($run['created_at']) ? \Illuminate\Support\Carbon::parse($run['created_at'])->format('d/m/Y H:i') : 'Abrir rodada' }}</a>
                                    <small>{{ $number($run['discovered_count'] ?? 0) }} descobertos · {{ $number($run['crm_blocked_count'] ?? 0) }} bloqueados no CRM</small>
                                </td>
                                <td><span class="prv2-badge prv2-badge-{{ $runTone }}">{{ $this->runStatusLabel($runStatus) }}</span></td>
                                <td><div class="prv2-progress-mini"><span style="width: {{ $runPercent }}%"></span></div><small>{{ $number($runProcessed) }} de {{ $number($runTotal) }} processados</small></td>
                                <td>{{ $number($run['lead_count'] ?? 0) }}</td>
                                <td>{{ $number($run['researched_count'] ?? 0) }}</td>
                                <td>{{ $number($run['export_identified_count'] ?? 0) }}</td>
                                <td class="{{ ($run['failed_count'] ?? 0) > 0 ? 'prv2-value-danger' : '' }}">{{ $number($run['failed_count'] ?? 0) }}</td>
                                <td><a href="{{ route('prospecting.show', $run['uuid']) }}" wire:navigate class="prv2-button prv2-button-outline prv2-button-small">Abrir rodada →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="prv2-empty"><h3>Nenhuma rodada registrada.</h3><p>Após confirmar o envio de uma prévia, as rodadas aparecerão aqui.</p></div>
        @endif
    </section>
</div>
