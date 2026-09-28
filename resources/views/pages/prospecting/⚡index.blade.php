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

<div
    class="ec-page-shell pr-workspace"
    data-test="prospecting-workspace"
    x-data="{ ufQuery: '', cnaeQuery: '', dirty: false, filtersOpen: true, matches(query, text) { const norm = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); return norm(text).includes(norm(query)); } }"
>
    @php
        $runs = $this->recentRuns;
        $previewCount = count($prospects);
        $stage = $executedBatchUuid ? 4 : ($confirmingExecution ? 3 : ($searched && $previewCount > 0 ? 2 : 1));
        $number = static fn ($value) => number_format((int) $value, 0, ',', '.');
    @endphp

    <header class="pr-page-header">
        <div>
            <span class="pr-eyebrow">DESCOBERTA COMERCIAL</span>
            <h1>Motor de Prospecção</h1>
            <p>Defina o perfil, revise os candidatos e acompanhe a qualificação.</p>
        </div>
        <a href="#pr-history" class="pr-btn pr-btn-secondary">
            @include('pages.prospecting.partials.workspace-icon', ['name' => 'history'])
            Ver histórico
        </a>
    </header>

    <ol class="pr-steps" aria-label="Etapas da prospecção">
        @foreach (['Configurar filtros', 'Revisar prévia', 'Confirmar envio', 'Acompanhar rodada'] as $step)
            <li class="{{ $loop->iteration === $stage ? 'is-current' : ($loop->iteration < $stage ? 'is-done' : '') }}"
                @if ($loop->iteration === $stage) aria-current="step" @endif>
                <span class="pr-step-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span>{{ $step }}</span>
            </li>
        @endforeach
    </ol>

    <form wire:submit="search" x-on:submit="dirty = false; filtersOpen = false" class="pr-panel pr-config" id="pr-config">
        <div class="pr-panel-heading">
            <div class="pr-heading-with-icon">
                <span class="pr-icon-tile">@include('pages.prospecting.partials.workspace-icon', ['name' => 'sliders'])</span>
                <div><h2>Configurar descoberta</h2><p>{{ $limit }} candidatos · {{ count($selectedStates) }} estados · {{ count($selectedCnaes) }} CNAEs selecionados</p></div>
            </div>
            <div class="pr-header-actions">
                <span class="pr-tag">Base local da Receita</span>
                <button type="button" class="pr-btn pr-btn-small pr-btn-quiet" x-on:click="filtersOpen = !filtersOpen" x-bind:aria-expanded="filtersOpen" aria-controls="pr-filter-body">
                    <span x-text="filtersOpen ? 'Recolher filtros' : 'Editar filtros'">Recolher filtros</span>
                </button>
            </div>
        </div>

        @if ($errors->any())
            <div class="pr-inline-warning" x-init="filtersOpen = true" role="alert">Revise os filtros destacados antes de buscar.</div>
        @endif
        <fieldset id="pr-filter-body" x-show="filtersOpen" wire:loading.attr="disabled" wire:target="search,executeProspecting" @disabled($confirmingExecution)>
            <legend class="pr-sr-only">Filtros para descoberta de empresas</legend>
            <div class="pr-config-grid">
                <div class="pr-filter-column">
                    <div class="pr-field-heading"><label for="pr-limit-custom">Quantidade de candidatos</label><span>Máximo por rodada</span></div>
                    <div class="pr-quantity" role="group" aria-label="Quantidades sugeridas">
                        @foreach ([10, 20, 50, 100] as $quantity)
                            <label class="pr-quantity-option">
                                <input type="radio" name="pr-limit" wire:model.live.number="limit" value="{{ $quantity }}" x-on:change="dirty = true">
                                <span>{{ $quantity }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="pr-custom-limit">
                        <label for="pr-limit-custom">Outra quantidade</label>
                        <input id="pr-limit-custom" type="number" min="1" max="200" step="1" wire:model.blur.number="limit" x-on:input="dirty = true" inputmode="numeric">
                        <span>1 a 200</span>
                    </div>
                    @error('limit')
                        <p class="pr-field-error" role="alert">{{ $message }}</p>
                    @enderror

                    <div class="pr-field-heading pr-gap-top">
                        <h3>Estados <span class="pr-count">{{ count($selectedStates) }}</span></h3>
                        <div class="pr-text-actions">
                            <button type="button" wire:click="selectAllStates" x-on:click="dirty = true">Todos</button>
                            <button type="button" wire:click="clearStates" x-on:click="dirty = true">Limpar</button>
                        </div>
                    </div>
                    <div class="pr-search-field">
                        @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
                        <input type="search" x-model="ufQuery" placeholder="Buscar estado ou sigla" aria-label="Buscar nas opções de estados">
                    </div>
                    <div class="pr-state-grid">
                        @foreach ($stateOptions as $uf => $stateName)
                            <label class="pr-choice pr-state-choice" wire:key="pr-state-{{ $uf }}" data-search="{{ $uf.' '.$stateName }}" x-show="matches(ufQuery, $el.dataset.search)">
                                <input type="checkbox" wire:model.live="selectedStates" value="{{ $uf }}" x-on:change="dirty = true">
                                <span><strong>{{ $uf }}</strong><small>{{ $stateName }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedStates')
                        <p class="pr-field-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="pr-filter-column">
                    <div class="pr-field-heading">
                        <h3>Atividades prioritárias <span class="pr-count">{{ count($selectedCnaes) }}</span></h3>
                        <div class="pr-text-actions">
                            <button type="button" wire:click="selectAllCnaes" x-on:click="dirty = true">Todos</button>
                            <button type="button" wire:click="clearCnaes" x-on:click="dirty = true">Limpar</button>
                        </div>
                    </div>
                    <div class="pr-search-field">
                        @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
                        <input type="search" x-model="cnaeQuery" placeholder="Buscar atividade ou CNAE" aria-label="Buscar nas opções de CNAEs">
                    </div>
                    <div class="pr-cnae-grid">
                        @foreach ($cnaeOptions as $code => $description)
                            <label class="pr-choice pr-cnae-choice" wire:key="pr-cnae-{{ $code }}" data-search="{{ $code.' '.$description }}" x-show="matches(cnaeQuery, $el.dataset.search)">
                                <input type="checkbox" wire:model.live="selectedCnaes" value="{{ $code }}" x-on:change="dirty = true">
                                <span><strong>{{ $code }}</strong><small>{{ $description }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedCnaes')
                        <p class="pr-field-error" role="alert">{{ $message }}</p>
                    @enderror
                    <p class="pr-help">O pré-ICP indica aderência cadastral. A verificação no CRM e a pesquisa de exportação acontecem na qualificação.</p>
                </div>
            </div>

            <div class="pr-action-bar">
                <div><strong>Resumo da busca</strong><p><b>{{ $limit }}</b> candidatos · <b>{{ count($selectedStates) }}</b> estados · <b>{{ count($selectedCnaes) }}</b> CNAEs</p></div>
                <button type="submit" class="pr-btn pr-btn-primary" wire:loading.attr="disabled" wire:target="search,executeProspecting">
                    @include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])
                    <span wire:loading.remove wire:target="search">Buscar prospects</span>
                    <span wire:loading wire:target="search">Buscando candidatos…</span>
                </button>
            </div>
        </fieldset>
    </form>

    <div wire:loading.flex wire:target="search" class="pr-notice" role="status">
        <span class="pr-spinner" aria-hidden="true"></span>
        <div><strong>Consultando a base local</strong><p>Aguarde a prévia. Nenhuma rodada é iniciada nesta etapa.</p></div>
    </div>

    @if ($error)
        <div class="pr-notice pr-notice-danger" role="alert" wire:key="pr-operation-error">
            @include('pages.prospecting.partials.workspace-icon', ['name' => 'alert'])
            <div><strong>Não foi possível concluir a operação</strong><p>{{ $error }}</p></div>
        </div>
    @endif

    @if ($executionMessage)
        <div class="pr-notice {{ $executedBatchUuid ? 'pr-notice-success' : '' }}" role="status" wire:key="pr-execution-result">
            @include('pages.prospecting.partials.workspace-icon', ['name' => $executedBatchUuid ? 'check' : 'info'])
            <div class="pr-grow">
                <strong>{{ $executedBatchUuid ? 'Prospecção iniciada' : 'Nenhum novo envio' }}</strong>
                <p>{{ $executionMessage }}</p>
                @if ($executedBatchUuid)
                    <small>A prévia abaixo já mostra os próximos candidatos disponíveis.</small>
                @endif
            </div>
            @if ($executedBatchUuid)
                <a href="{{ route('prospecting.show', $executedBatchUuid) }}" wire:navigate class="pr-btn pr-btn-primary">
                    Acompanhar rodada @include('pages.prospecting.partials.workspace-icon', ['name' => 'arrow'])
                </a>
            @endif
        </div>
    @endif

    @if ($searched)
        <section class="pr-panel" id="pr-preview" aria-labelledby="pr-preview-title" wire:key="pr-preview">
            <div class="pr-panel-heading">
                <div><h2 id="pr-preview-title">Prospects encontrados</h2><p>{{ $number($previewCount) }} candidatos nesta pré-visualização · CRM ainda não validado nesta etapa</p></div>
                @if ($prospects !== [] && ! $error && ! $errors->any())
                    <button type="button" class="pr-btn pr-btn-primary" wire:click="confirmExecution" x-bind:disabled="dirty || $wire.confirmingExecution" wire:loading.attr="disabled" wire:target="search,confirmExecution,executeProspecting" @disabled($confirmingExecution)>
                        Revisar envio @include('pages.prospecting.partials.workspace-icon', ['name' => 'arrow'])
                    </button>
                @endif
            </div>

            <div class="pr-preview-metrics">
                <div><span>Descobertos</span><strong>{{ $number($discoveredCount) }}</strong><small>Candidatos examinados pelo motor</small></div>
                <div><span>Já trabalhados</span><strong>{{ $number($knownCount) }}</strong><small>Conforme a regra atual de exclusão</small></div>
                <div class="pr-metric-accent"><span>Novos disponíveis</span><strong>{{ $number($newCount) }}</strong><small>{{ $number($previewCount) }} exibidos nesta prévia</small></div>
            </div>

            <div x-show="dirty" x-cloak class="pr-inline-warning" role="status">
                @include('pages.prospecting.partials.workspace-icon', ['name' => 'alert'])
                Filtros alterados. Clique em “Buscar prospects” para atualizar a prévia antes de confirmar.
            </div>

            @if ($confirmingExecution)
                <section class="pr-confirmation" aria-labelledby="pr-confirm-title" wire:key="pr-execution-confirmation">
                    <div class="pr-confirm-copy">
                        <span class="pr-eyebrow">CONFIRMAÇÃO DE ENVIO</span>
                        <h3 id="pr-confirm-title">Iniciar a qualificação de até {{ $number($limit) }} empresas?</h3>
                        <p>A prévia contém {{ $number($previewCount) }} candidatos. O motor revalida a disponibilidade no envio; a quantidade final pode mudar.</p>
                        <div class="pr-process-tags"><span>Receita</span><span>ICP</span><span>HubSpot</span><span>Pesquisa pública, quando elegível</span></div>
                        <p class="pr-help">A pesquisa pode utilizar créditos do provedor configurado. O envio não confirma que a empresa exporta.</p>
                    </div>
                    <div class="pr-confirm-actions">
                        <button type="button" class="pr-btn pr-btn-secondary" wire:click="cancelExecution" wire:loading.attr="disabled" wire:target="executeProspecting">Cancelar</button>
                        <button type="button" class="pr-btn pr-btn-primary" wire:click="executeProspecting" x-bind:disabled="dirty" wire:loading.attr="disabled" wire:target="executeProspecting,cancelExecution">
                            <span wire:loading.remove wire:target="executeProspecting">Confirmar e executar</span>
                            <span wire:loading wire:target="executeProspecting">Enviando…</span>
                        </button>
                    </div>
                </section>
            @endif

            @if ($prospects !== [])
                <div class="pr-table-scroll pr-preview-scroll" tabindex="0" role="region" aria-label="Tabela da prévia de candidatos">
                    <table class="pr-table pr-preview-table">
                        <thead><tr><th scope="col">Empresa / CNPJ</th><th scope="col">UF</th><th scope="col">CNAE aderente</th><th scope="col">Pré-ICP</th><th scope="col">Unidades ativas</th><th scope="col" class="pr-align-right">Capital social</th></tr></thead>
                        <tbody>
                            @foreach ($prospects as $prospect)
                                @php
                                    $candidateCnpj = (string) ($prospect['cnpj'] ?? '');
                                    $matchedCnae = (string) ($prospect['matched_cnae'] ?? '');
                                @endphp
                                <tr data-cnpj="{{ $candidateCnpj }}" wire:key="pr-candidate-{{ $candidateCnpj ?: $loop->index }}">
                                    <td class="pr-company-cell">
                                        <strong>{{ $prospect['corporate_name'] ?? 'Empresa sem nome cadastral' }}</strong>
                                        <span class="pr-mono">{{ $candidateCnpj !== '' ? \App\Support\Cnpj::format($candidateCnpj) : 'CNPJ não informado' }}</span>
                                        <details class="pr-inline-details">
                                            <summary>Detalhes cadastrais</summary>
                                            <dl>
                                                <div><dt>CNPJ raiz</dt><dd>{{ $prospect['cnpj_root'] ?? '—' }}</dd></div>
                                                <div><dt>Atividade aderente</dt><dd>{{ $cnaeOptions[$matchedCnae] ?? $matchedCnae ?: 'Não informada' }}</dd></div>
                                                <div><dt>Estados com unidades ativas</dt><dd>{{ $prospect['active_states'] ?? 'Não informado' }}</dd></div>
                                            </dl>
                                            <p>Prévia cadastral; dossiê disponível após a criação da empresa pelo pipeline.</p>
                                        </details>
                                    </td>
                                    <td><span class="pr-uf">{{ $prospect['state'] ?? '—' }}</span></td>
                                    <td><strong class="pr-mono">{{ $matchedCnae ?: '—' }}</strong><small>{{ match ($prospect['cnae_match_type'] ?? null) { 'primary' => 'Principal', 'secondary' => 'Secundário', default => 'Vínculo não informado' } }}</small></td>
                                    <td><span class="pr-score">{{ $prospect['discovery_score'] ?? '—' }}</span></td>
                                    <td>{{ $prospect['active_establishments'] ?? '—' }}</td>
                                    <td class="pr-align-right pr-nowrap">{{ $this->capital($prospect['share_capital'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="pr-table-note">Pré-ICP não é confirmação comercial. As contagens representam etapas diferentes e não devem ser somadas.</div>
            @else
                <div class="pr-empty">
                    <span class="pr-empty-icon">@include('pages.prospecting.partials.workspace-icon', ['name' => 'search'])</span>
                    <h3>{{ $error ? 'Prévia indisponível' : 'Nenhum prospect encontrado' }}</h3>
                    <p>{{ $error ? 'Confira a mensagem de erro e tente a busca novamente.' : 'Tente ampliar os estados ou CNAEs selecionados.' }}</p>
                </div>
            @endif
        </section>
    @endif

    <section class="pr-panel" id="pr-history" aria-labelledby="pr-history-title">
        <div class="pr-panel-heading">
            <div><h2 id="pr-history-title">Histórico de prospecção</h2><p>As últimas 10 rodadas e seus resultados registrados.</p></div>
            <div class="pr-header-actions">
                <button type="button" class="pr-btn pr-btn-quiet" wire:click="$refresh" wire:loading.attr="disabled" aria-label="Atualizar histórico">
                    @include('pages.prospecting.partials.workspace-icon', ['name' => 'refresh']) Atualizar
                </button>
                <a href="{{ route('imports.index') }}" wire:navigate class="pr-link">Ver importações →</a>
            </div>
        </div>
        @if ($runs !== [])
            <div class="pr-table-scroll" tabindex="0" role="region" aria-label="Histórico das rodadas de prospecção">
                <table class="pr-table pr-history-table">
                    <thead><tr><th scope="col">Rodada</th><th scope="col">Status</th><th scope="col">Processamento</th><th scope="col">Leads</th><th scope="col">Pesquisados</th><th scope="col">Exportadores</th><th scope="col">Falhas</th><th scope="col"><span class="pr-sr-only">Ação</span></th></tr></thead>
                    <tbody>
                        @foreach ($runs as $run)
                            @php
                                $runTotal = max(0, (int) ($run['total_rows'] ?? 0));
                                $runProcessed = max(0, (int) ($run['processed_rows'] ?? 0));
                                $runPercent = $runTotal > 0 ? min(100, (int) round($runProcessed * 100 / $runTotal)) : 0;
                                $runStatus = (string) ($run['status'] ?? '');
                                $runTone = match ($runStatus) { 'completed' => 'success', 'failed' => 'danger', 'processing' => 'info', default => 'neutral' };
                            @endphp
                            <tr wire:key="pr-run-{{ $run['uuid'] }}">
                                <td class="pr-company-cell">
                                    <a class="pr-company-link" href="{{ route('prospecting.show', $run['uuid']) }}" wire:navigate>{{ ! empty($run['created_at']) ? \Illuminate\Support\Carbon::parse($run['created_at'])->format('d/m/Y H:i') : 'Abrir rodada' }}</a>
                                    <small>{{ $number($run['discovered_count'] ?? 0) }} descobertos · {{ $number($run['crm_blocked_count'] ?? 0) }} bloqueados no CRM</small>
                                </td>
                                <td><span class="pr-badge pr-badge-{{ $runTone }}">{{ $this->runStatusLabel($runStatus) }}</span></td>
                                <td><div class="pr-mini-progress"><span style="width: {{ $runPercent }}%"></span></div><small>{{ $number($runProcessed) }} de {{ $number($runTotal) }} enviados</small></td>
                                <td class="pr-value-success">{{ $number($run['lead_count'] ?? 0) }}</td>
                                <td>{{ $number($run['researched_count'] ?? 0) }}</td>
                                <td>{{ $number($run['export_identified_count'] ?? 0) }}</td>
                                <td class="{{ ($run['failed_count'] ?? 0) > 0 ? 'pr-value-danger' : '' }}">{{ $number($run['failed_count'] ?? 0) }}</td>
                                <td><a href="{{ route('prospecting.show', $run['uuid']) }}" wire:navigate class="pr-btn pr-btn-small pr-btn-secondary" aria-label="Abrir rodada {{ $run['uuid'] }}">Abrir rodada →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="pr-empty pr-empty-small">
                <span class="pr-empty-icon">@include('pages.prospecting.partials.workspace-icon', ['name' => 'history'])</span>
                <h3>Nenhuma rodada de prospecção registrada ainda.</h3>
                <p>Busque os candidatos e confirme o envio. Sua primeira rodada aparecerá aqui.</p>
            </div>
        @endif
    </section>
</div>
