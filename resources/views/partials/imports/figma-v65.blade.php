{{--
    IMPORTACOES_FIGMA_V65 — telas I01/I02 do protótipo ExportControl.
    Partial visual; reaproveita integralmente os métodos públicos e #[Computed] do
    componente Volt pages::imports.index. Nenhuma consulta externa é disparada
    durante a renderização; os comandos são acionados por ações explícitas.
--}}
@php
    $batch = $this->currentBatch;
    $recentBatches = $this->recentBatches;
    $items = $this->currentItems;
    $counts = $this->currentStatusCounts;
    $ready = (int) ($counts['ready'] ?? 0);
    $queued = (int) ($counts['queued'] ?? 0);
    $processing = (int) ($counts['processing'] ?? 0);
    $completed = (int) ($counts['completed'] ?? 0);
    $failed = (int) ($counts['failed'] ?? 0);
    $pending = (int) ($counts['pending'] ?? 0);
    $busy = $queued + $processing > 0;
    $enrichmentTotal = $ready + $queued + $processing + $completed + $failed;
    $finished = $completed + $failed;
    $progress = $enrichmentTotal > 0 ? min(100, (int) round(100 * $finished / $enrichmentTotal)) : 0;
    $format = static fn ($value): string => number_format((int) $value, 0, ',', '.');
    $batchLabel = match (true) {
        $busy => 'Enriquecendo',
        $ready > 0 => 'Aguardando enriquecimento',
        $pending > 0 => 'Aguardando validação',
        $failed > 0 => 'Finalizado com falhas',
        $completed > 0 => 'Enriquecimento concluído',
        $batch !== null && (int) $batch->total_rows > 0 => 'Validação concluída',
        default => 'Lote sem itens',
    };
    $hasMoreHistory = $this->historyLimit < 100 && \App\Models\ImportBatch::query()->count() > $recentBatches->count();
    $intelligence = $batch ? $this->currentIntelligenceCounts : [];
    $failedItems = $items->filter(fn ($item) => (string) $item->status === 'failed')->values();
    $batchCode = $batch ? 'IMP-'.str_pad((string) $batch->id, 3, '0', STR_PAD_LEFT) : '';
    $maxItemsPage = max(1, (int) ceil($items->count() / 12));
@endphp
<div class="ecim-page ecimv2 ecim65-page" data-ec-imports-figma="I01-I02-V65" >
    <div class="im65-content">
        <header class="im65-hero">
            <span class="im65-eyebrow">IMPORTAÇÕES{{ $batch ? ' · Resultado do lote' : '' }}</span>
            @if ($batch)
                <h1>Lote {{ $batchCode }}{{ $failed > 0 && ! $busy && $ready === 0 ? ' · resultado' : '' }}</h1>
                <p>{{ $batch->source_type === 'prospecting' ? 'Origem: Prospecção' : 'Lista de CNPJs' }} · {{ $batch->created_at?->format('d/m/Y H:i') ?? 'Sem data' }} · {{ $batchLabel }}</p>
            @else
                <h1>Importar uma lista de CNPJs</h1>
                <p>Valide os identificadores antes de iniciar o enriquecimento.</p>
            @endif
            <div class="im65-hero-actions">
                @if ($batch)
                    <a class="im65-button im65-button-secondary" href="{{ route('imports.index') }}" wire:navigate>Nova importação</a>
                    <button type="button" class="im65-button im65-button-secondary" wire:click="refreshCurrentBatch"
                        wire:loading.attr="disabled" wire:target="refreshCurrentBatch,queueCurrentBatch,process,openBatch">Atualizar</button>
                    @if ($ready > 0)
                        <button type="button" class="im65-button im65-button-primary" wire:click="queueCurrentBatch"
                            wire:loading.attr="disabled" wire:target="refreshCurrentBatch,queueCurrentBatch,process,openBatch">
                            <span wire:loading.remove wire:target="queueCurrentBatch">Iniciar enriquecimento ({{ $format($ready) }})</span>
                            <span wire:loading wire:target="queueCurrentBatch">Enfileirando...</span>
                        </button>
                    @endif
                @else
                    <a class="im65-button im65-button-secondary" href="#im65-history">Histórico de lotes</a>
                    <button type="submit" form="im65-composer-form" class="im65-button im65-button-primary"
                        wire:loading.attr="disabled" wire:target="process,openBatch,queueCurrentBatch">
                        <span wire:loading.remove wire:target="process">Processar CNPJs</span>
                        <span wire:loading wire:target="process">Validando...</span>
                    </button>
                @endif
            </div>
        </header>

        @if (session('success'))
            <div class="im65-notice im65-notice-success" role="status">{{ session('success') }}</div>
        @endif
        @error('input')
            <div class="im65-notice im65-notice-error" role="alert">{{ $message }}</div>
        @enderror

        @if (! $batch)
            {{-- I01 — criação de lote, sempre separada da consulta cadastral. --}}
            <nav class="im65-tabs" aria-label="Navegação das importações">
                <a href="#im65-composer" class="is-active">Nova importação</a>
                <a href="#im65-history">Histórico</a>
            </nav>

            <form id="im65-composer-form" wire:submit="process" novalidate>
                <section class="im65-card im65-composer" id="im65-composer" aria-labelledby="im65-composer-title">
                    <h2 id="im65-composer-title">Cole sua lista</h2>
                    <label for="im65-cnpjs" class="im65-label">CNPJs</label>
                    <textarea id="im65-cnpjs" name="cnpjs" wire:model="input" rows="5" maxlength="1000000"
                        placeholder="00.000.000/0001-00&#10;00.000.000/0002-00" spellcheck="false"
                        autocomplete="off" autocapitalize="off" aria-invalid="{{ $errors->has('input') ? 'true' : 'false' }}"
                        wire:loading.attr="disabled" wire:target="process"></textarea>
                    <small>Cole os CNPJs recebidos. Separe por linha, vírgula ou ponto e vírgula. A validação verifica os identificadores informados.</small>
                </section>
            </form>

            <div class="im65-note im65-note-info" role="note">
                <span aria-hidden="true">✓</span>
                <div><strong>Primeiro, valide a lista</strong><p>O sistema identifica CNPJs válidos, duplicados e já cadastrados. Nenhum enriquecimento é iniciado automaticamente.</p></div>
            </div>
            <div class="im65-note im65-note-success" role="note">
                <span aria-hidden="true">✓</span>
                <div><strong>Duas etapas distintas</strong><p>Processar valida a lista e cria um lote. A consulta cadastral começa somente após você clicar em Iniciar enriquecimento.</p></div>
            </div>

            <section class="im65-card im65-history" id="im65-history" aria-labelledby="im65-history-title">
                <header class="im65-card-header"><h2 id="im65-history-title">Importações recentes</h2><span>{{ $format($recentBatches->count()) }} na lista</span></header>
                @if ($recentBatches->isNotEmpty())
                    <div class="im65-table-scroll">
                        <table class="im65-table">
                            <thead><tr><th>Lote</th><th>Origem</th><th>Data</th><th>Registros</th><th>Estado</th><th>Ação</th></tr></thead>
                            <tbody>
                                @foreach ($recentBatches as $recent)
                                    <tr wire:key="im65-recent-{{ $recent->id }}">
                                        <td><strong>Lote #{{ $recent->id }}</strong><small>IMP-{{ str_pad((string) $recent->id, 3, '0', STR_PAD_LEFT) }}</small></td>
                                        <td>{{ $recent->source_type === 'prospecting' ? 'Prospecção' : ($recent->source_type === 'manual' ? 'Manual' : ($recent->source_type ?: 'Outra')) }}</td>
                                        <td>{{ $recent->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                        <td>{{ $format($recent->total_rows) }}</td>
                                        <td><span class="im65-status">{{ $this->statusLabel((string) $recent->status) }}</span></td>
                                        <td><button type="button" class="im65-link" wire:click="openBatch({{ $recent->id }})"
                                            wire:loading.attr="disabled" wire:target="openBatch,process">Ver lote →</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($hasMoreHistory)
                        <div class="im65-history-footer"><button type="button" class="im65-button im65-button-secondary" wire:click="showMoreHistory" wire:loading.attr="disabled" wire:target="showMoreHistory">Ver mais lotes</button></div>
                    @endif
                @else
                    <div class="im65-empty">Ainda não há importações registradas. Cole uma lista de CNPJs para começar.</div>
                @endif
            </section>
        @else
            {{-- I02 — lote real, contendo ações de fila e todos os diagnósticos. --}}
            <div class="im65-tabs" role="group" aria-label="Filtrar processamento do lote">
                @foreach ([
                    'all' => 'Todos', 'ready' => 'Prontos', 'queued' => 'Na fila',
                    'processing' => 'Processando', 'completed' => 'Concluídos', 'failed' => 'Falhas',
                ] as $tabStatus => $tabLabel)
                    <button type="button" class="{{ $statusFilter === $tabStatus ? 'is-active' : '' }}"
                        wire:click="$set('statusFilter', '{{ $tabStatus }}')" x-on:click="$dispatch('im65:reset-page')">{{ $tabLabel }}</button>
                @endforeach
            </div>

            @if ($finished > 0 && ! $busy && $ready === 0)
                {{-- I02-ERRORS: resultados reais quando o enriquecimento foi finalizado. --}}
                <section class="im65-kpis" aria-label="Resultados do enriquecimento do lote">
                    <article class="im65-kpi"><span>Enriquecidos</span><strong>{{ $format($completed) }} / {{ $format($finished) }}</strong><small>Registros finalizados</small></article>
                    <article class="im65-kpi"><span>Falhas</span><strong>{{ $format($failed) }}</strong><small>Consulta cadastral não concluída</small></article>
                    <article class="im65-kpi"><span>Novos no CRM</span><strong>{{ $format($intelligence['new'] ?? 0) }}</strong><small>Resultado da verificação comercial</small></article>
                    <article class="im65-kpi"><span>ICP A / B</span><strong>{{ $format(($intelligence['icp_a'] ?? 0) + ($intelligence['icp_b'] ?? 0)) }}</strong><small>Registros classificados</small></article>
                </section>
            @else
                {{-- I02: painel da validação antes ou durante o processamento. --}}
                <section class="im65-kpis" aria-label="Indicadores de validação do lote">
                    <article class="im65-kpi"><span>Total recebido</span><strong>{{ $format($batch->total_rows) }}</strong><small>Registros analisados</small></article>
                    <article class="im65-kpi is-highlight"><span>Válidos novos</span><strong>{{ $format($batch->valid_rows) }}</strong><small>Elegíveis para enriquecimento</small></article>
                    <article class="im65-kpi"><span>Já cadastrados</span><strong>{{ $format($batch->existing_rows) }}</strong><small>Preservados na base local</small></article>
                    <article class="im65-kpi"><span>Duplicados / Inválidos</span><strong>{{ $format((int) $batch->duplicate_rows + (int) $batch->invalid_rows) }}</strong><small>{{ $format($batch->duplicate_rows) }} duplicados · {{ $format($batch->invalid_rows) }} inválidos</small></article>
                </section>
            @endif

            <section class="im65-note {{ $failed > 0 ? 'im65-note-warning' : 'im65-note-success' }}"
                     @if ($busy) wire:poll.2s="refreshCurrentBatch" @endif aria-label="Situação do lote">
                <span aria-hidden="true">{{ $failed > 0 ? '!' : '✓' }}</span>
                <div>
                    <strong>{{ $batchLabel }}</strong>
                    <p>
                        @if ($busy)
                            A fila está processando os CNPJs. Atualização automática enquanto houver registros na fila.
                        @elseif ($ready > 0)
                            A lista foi validada. Clique em Iniciar enriquecimento para consultar os {{ $format($ready) }} registros prontos.
                        @elseif ($failed > 0)
                            {{ $format($completed) }} registro(s) concluído(s) e {{ $format($failed) }} com falha. Os resultados bem-sucedidos foram preservados.
                        @else
                            Validação e acompanhamento do lote. A consulta cadastral é separada do processamento da lista.
                        @endif
                    </p>
                </div>
            </section>

            @if ($enrichmentTotal > 0)
                <div class="im65-progress" aria-label="Progresso do enriquecimento">
                    <div class="im65-progress-head"><span>Enriquecimento cadastral</span><strong>{{ $progress }}%</strong></div>
                    <div class="im65-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}" aria-label="Progresso do enriquecimento">
                        <span style="width: {{ $progress }}%"></span>
                    </div>
                    <small>{{ $format($finished) }} de {{ $format($enrichmentTotal) }} registros finalizados · {{ $format($queued) }} na fila · {{ $format($processing) }} processando</small>
                </div>
            @endif

            <section class="im65-card im65-filters" aria-labelledby="im65-filters-title">
                <h2 id="im65-filters-title">Pesquisar no lote</h2>
                <div class="im65-filters-grid">
                    <div><label for="im65-search">Empresa ou CNPJ</label>
                        <input id="im65-search" type="search" wire:model.live.debounce.350ms="search" x-on:input="$dispatch('im65:reset-page')" placeholder="Pesquisar empresa ou CNPJ..." autocomplete="off"></div>
                    <div><label for="im65-status">Processamento</label>
                        <select id="im65-status" wire:model.live="statusFilter" x-on:change="$dispatch('im65:reset-page')">
                            <option value="all">Todos</option>
                            <option value="ready">Pronto</option>
                            <option value="queued">Na fila</option>
                            <option value="processing">Processando</option>
                            <option value="completed">Concluído</option>
                            <option value="existing">Já cadastrado</option>
                            <option value="duplicate">Duplicado</option>
                            <option value="invalid">Inválido</option>
                            <option value="failed">Falhou</option>
                            <option value="pending">Pendente</option>
                        </select></div>
                </div>
                <small>A busca consulta os itens de todo o lote e exibe até 100 resultados por pesquisa.</small>
            </section>

            <section class="im65-card im65-results" aria-labelledby="im65-results-title" x-data="{ page: 1 }" x-on:im65:reset-page.window="page=1">
                <header class="im65-card-header"><h2 id="im65-results-title">Registros do lote</h2><span>{{ $format($items->count()) }} exibido(s) · máximo de 100 por consulta</span></header>
                <div class="im65-table-scroll">
                    <table class="im65-table im65-items-table">
                        <thead><tr><th>Linha</th><th>Empresa</th><th>CNPJ</th><th>ICP / score</th><th>CRM</th><th>Processamento</th><th>Alertas / Ação</th></tr></thead>
                        <tbody>
                            @forelse ($items as $item)
                                @php
                                    $company = $item->company;
                                    $icp = $company?->icpScore;
                                    $crm = $company?->crmCheck;
                                    $alerts = $this->itemAlerts($item);
                                    $cnpj = (string) ($item->normalized_cnpj ?: $item->raw_cnpj ?: '');
                                    $displayCnpj = $cnpj !== '' && \App\Support\Cnpj::isWellFormed($cnpj)
                                        ? \App\Support\Cnpj::format($cnpj) : $cnpj;
                                @endphp
                                <tr wire:key="im65-item-{{ $item->id }}" x-show="page === {{ intdiv($loop->index, 12) + 1 }}" x-cloak>
                                    <td data-label="Linha">{{ $item->row_number }}</td>
                                    <td data-label="Empresa">
                                        @if ($company)
                                            <a class="im65-company" href="{{ route('companies.show', $company) }}" wire:navigate>{{ $company->corporate_name }}</a>
                                        @elseif ((string) $item->status === 'duplicate')
                                            <span>Registro duplicado</span>
                                        @elseif ((string) $item->status === 'invalid')
                                            <span>Não identificada</span>
                                        @else
                                            <span>Aguardando consulta</span>
                                        @endif
                                    </td>
                                    <td data-label="CNPJ" class="im65-cnpj">{{ $displayCnpj ?: 'Não informado' }}</td>
                                    <td data-label="ICP / score">{{ $icp ? ($icp->grade.' · '.$icp->score) : 'Não calculado' }}</td>
                                    <td data-label="CRM">{{ $this->crmStatusLabel($crm?->status) }}</td>
                                    <td data-label="Processamento"><span class="im65-status im65-status-{{ $item->status }}">{{ $this->statusLabel((string) $item->status) }}</span></td>
                                    <td data-label="Alertas / Ação">
                                        @if ($company)
                                            <a class="im65-link" href="{{ route('companies.show', $company) }}" wire:navigate>Abrir dossiê ↗</a>
                                        @endif
                                        @if (count($alerts) > 0)
                                            <details class="im65-alerts"><summary>Alertas ({{ count($alerts) }})</summary>
                                                @foreach ($alerts as $alert)
                                                    <div><strong>{{ $alert['label'] }}</strong><p>{{ $alert['detail'] ?: 'Sem detalhes adicionais.' }}</p></div>
                                                @endforeach
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="im65-empty">Nenhum CNPJ encontrado para os filtros selecionados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <footer class="im65-table-footer">
                    <span>O lote contém {{ $format($batch->total_rows) }} registro(s). Indicadores consideram o lote inteiro; a tabela exibe até 100.</span>
                    <div class="im65-pager" @if ($maxItemsPage <= 1) hidden @endif>
                        <button type="button" class="im65-button im65-button-secondary" x-on:click="page = Math.max(1, page - 1)" x-bind:disabled="page <= 1">Anterior</button>
                        <span>Página <strong x-text="page">1</strong> de {{ $maxItemsPage }}</span>
                        <button type="button" class="im65-button im65-button-secondary" x-on:click="page = Math.min({{ $maxItemsPage }}, page + 1)" x-bind:disabled="page >= {{ $maxItemsPage }}">Próxima</button>
                    </div>
                </footer>
            </section>

            @if ($failed > 0)
                <section class="im65-card im65-diagnostics" aria-labelledby="im65-diagnostics-title">
                    <header class="im65-card-header"><h2 id="im65-diagnostics-title">Diagnóstico por registro</h2><span>{{ $format($failed) }} falha(s) no lote</span></header>
                    @if ($failedItems->isNotEmpty())
                        <div class="im65-table-scroll"><table class="im65-table"><thead><tr><th>Linha</th><th>Empresa / CNPJ</th><th>Processamento</th><th>Motivo</th><th>Cadastro preservado</th></tr></thead>
                            <tbody>
                                @foreach ($failedItems as $failedItem)
                                    <tr wire:key="im65-failure-{{ $failedItem->id }}">
                                        <td>{{ $failedItem->row_number }}</td>
                                        <td>{{ $failedItem->company?->corporate_name ?: ($failedItem->raw_cnpj ?: 'Não identificada') }}</td>
                                        <td>Falhou</td>
                                        <td>{{ $failedItem->error_message ?: 'Sem diagnóstico registrado' }}</td>
                                        <td>{{ $failedItem->company_id ? 'Sim' : 'Sem cadastro anterior' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    @else
                        <div class="im65-empty">Filtre por Falhas para consultar os registros que não foram concluídos. A busca pode restringir os itens exibidos.</div>
                    @endif
                </section>
            @endif

            <section class="im65-card im65-intelligence" aria-labelledby="im65-intelligence-title">
                <header class="im65-card-header"><h2 id="im65-intelligence-title">Inteligência comercial do lote</h2><span>Classificação dos registros enriquecidos</span></header>
                <div class="im65-intelligence-grid">
                    <table class="im65-table"><thead><tr><th>Situação no CRM</th><th>Registros</th></tr></thead><tbody>
                        @foreach (['clients' => 'Clientes', 'opportunities' => 'Oportunidades', 'prospected' => 'Prospectados', 'known' => 'Conhecidos', 'new' => 'Novos'] as $key => $label)
                            <tr><td>{{ $label }}</td><td>{{ $format($intelligence[$key] ?? 0) }}</td></tr>
                        @endforeach
                    </tbody></table>
                    <table class="im65-table"><thead><tr><th>Perfil ICP</th><th>Registros</th></tr></thead><tbody>
                        @foreach (['icp_a' => 'ICP A', 'icp_b' => 'ICP B', 'icp_c' => 'ICP C', 'icp_d' => 'ICP D'] as $key => $label)
                            <tr><td>{{ $label }}</td><td>{{ $format($intelligence[$key] ?? 0) }}</td></tr>
                        @endforeach
                    </tbody></table>
                </div>
                <p class="im65-footnote">Contagens por registro deste lote, não por grupo econômico ou negócio no HubSpot. Indícios de exportação não comprovam operação exportadora.</p>
            </section>

            <div class="im65-batch-navigation">
                <a class="im65-link" href="{{ route('imports.index') }}" wire:navigate>Voltar para Importações</a>
            </div>
        @endif
        <footer class="im65-page-footer">ExportControl Prospector · Dados locais e processamentos efetivamente registrados</footer>
    </div>
</div>
