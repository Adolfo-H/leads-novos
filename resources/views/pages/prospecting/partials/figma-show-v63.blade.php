{{-- EC_PROSPECTING_FIGMA_V63: P03, acompanhamento real da rodada. --}}
<div class="ec-page-shell pr-workspace pr-run-workspace prv2-page prv2-run-page prv63-page prv63-run"
     data-test="prospecting-run-workspace"
     data-ec-prospecting-figma="P03-V63"
     x-data="{ resultPage: 1 }"
     @if (in_array($this->detail['status'] ?? '', ['ready', 'processing'], true) || collect($this->detail['items'] ?? [])->contains(static fn ($row) => in_array($row['export_research_status'] ?? '', ['queued', 'processing'], true)))
         wire:poll.visible.10s
     @endif>
    @php
        $detail = $this->detail;
        $items = $this->filteredItems;
        $allItems = $detail['items'] ?? [];
        $total = max(0, (int) ($detail['total_rows'] ?? 0));
        $processed = max(0, (int) ($detail['processed_rows'] ?? 0));
        $percent = $total > 0 ? min(100, (int) round(100 * $processed / $total)) : 0;
        $pending = max(0, $total - $processed);
        $researchPending = count(array_filter($allItems, static fn ($item): bool => in_array(($item['export_research_status'] ?? ''), ['queued','processing'], true)));
        $researched = (int) ($detail['researched_count'] ?? 0);
        $inResearch = $researched + $researchPending;
        $isUpdating = in_array((string) ($detail['status'] ?? ''), ['ready', 'processing'], true) || $researchPending > 0;
        $number = static fn ($value): string => number_format((int) $value, 0, ',', '.');
        $states = data_get($detail, 'filters.states', []);
        $cnaes = data_get($detail, 'filters.cnaes', []);
        $tabs = [
            ['key' => 'all', 'label' => 'Todos', 'count' => count($allItems)],
            ['key' => 'leads', 'label' => 'Leads', 'count' => $detail['lead_count'] ?? 0],
            ['key' => 'blocked', 'label' => 'Bloqueados', 'count' => $detail['blocked_count'] ?? 0],
            ['key' => 'exporters', 'label' => 'Exportadores', 'count' => $detail['export_identified_count'] ?? 0],
            ['key' => 'failed', 'label' => 'Falhas', 'count' => $detail['failed_count'] ?? 0],
        ];
        $extraTabs = [
            ['key' => 'new_crm', 'label' => 'Novos no CRM', 'count' => $this->crmStatusCount('not_found')],
            ['key' => 'reprospecting', 'label' => 'Reprospecção', 'count' => $this->crmStatusCount('prospected')],
            ['key' => 'opportunities', 'label' => 'Oportunidades', 'count' => $this->crmStatusCount('opportunity')],
            ['key' => 'clients', 'label' => 'Clientes', 'count' => $this->crmStatusCount('client')],
        ];
        $allTabs = array_merge($tabs, $extraTabs);
        $activeLabel = collect($allTabs)->firstWhere('key', $filter)['label'] ?? 'Todos';
        $canExportCurrentFilter = in_array($filter, ['all', 'leads', 'blocked', 'exporters', 'failed'], true);
        $resultPages = max(1, (int) ceil(count($items) / 6));
        $firstFailure = collect($items)->first(fn (array $item): bool => ($item['item_status'] ?? '') === 'failed');
        $uuid = (string) ($detail['uuid'] ?? $batch->uuid);
        $runShort = strtoupper(substr($uuid, 0, 8));
    @endphp

    <header class="prv63-page-header prv63-run-header">
        <div class="prv63-kicker-line"><span class="prv63-kicker">PROSPECÇÃO</span></div>
        <h1>Rodada {{ $runShort }}</h1>
        <p>Iniciada em {{ $detail['created_label'] ?? 'data não informada' }} · {{ $number($total) }} empresa(s) enviada(s) para qualificação.</p>
        <div class="prv63-top-actions">
            <a href="{{ route('prospecting.index') }}" wire:navigate class="prv63-button prv63-button-outline">Voltar à prospecção</a>
            <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel,filter"
                    @disabled(! $canExportCurrentFilter)
                    title="{{ $canExportCurrentFilter ? 'Exportar resultado com o filtro selecionado' : 'O exportador atual não suporta este filtro' }}"
                    aria-describedby="prv63-excel-note"
                    class="prv63-button prv63-button-outline">
                <span wire:loading.remove wire:target="exportExcel">Exportar Excel</span>
                <span wire:loading wire:target="exportExcel">Gerando Excel...</span>
            </button>
            <button type="button" class="prv63-button prv63-button-quiet" wire:click="$refresh" wire:loading.attr="disabled">Atualizar</button>
        </div>
    </header>

    <div class="prv63-filter-tabs" role="group" aria-label="Filtrar resultados de prospecção">
        @foreach ($tabs as $tab)
            <button type="button" class="prv63-tab {{ $filter === $tab['key'] ? 'is-active' : '' }}"
                    wire:click="$set('filter', '{{ $tab['key'] }}')" x-on:click="resultPage=1"
                    aria-pressed="{{ $filter === $tab['key'] ? 'true' : 'false' }}">
                {{ $tab['label'] }}
            </button>
        @endforeach
        <details class="prv63-more-filters"><summary>Mais filtros <span aria-hidden="true">⌄</span></summary><div>
            @foreach ($extraTabs as $tab)
                <button type="button" class="prv63-tab {{ $filter === $tab['key'] ? 'is-active' : '' }}"
                        wire:click="$set('filter', '{{ $tab['key'] }}')" x-on:click="resultPage=1">
                    {{ $tab['label'] }} ({{ $number($tab['count']) }})
                </button>
            @endforeach
        </div></details>
    </div>

    <section class="prv63-metrics" aria-label="Indicadores da rodada">
        <article class="prv63-metric"><span>Cadastro processado</span><strong>{{ $number($processed) }} / {{ $number($total) }}</strong><small>{{ $percent }}% do processamento cadastral</small></article>
        <article class="prv63-metric"><span>Pesquisa de exportação</span><strong>{{ $number($researched) }} @if ($inResearch > 0)/ {{ $number($inResearch) }}@endif</strong><small>{{ $number($researchPending) }} em fila ou em execução</small></article>
        <article class="prv63-metric"><span>Leads classificados</span><strong>{{ $number($detail['lead_count'] ?? 0) }}</strong><small>Conforme o Score SDR atual</small></article>
        <article class="prv63-metric"><span>Itens com falha</span><strong>{{ $number($detail['failed_count'] ?? 0) }}</strong><small>Consulte os motivos registrados</small></article>
    </section>

    <div class="prv63-notice {{ $isUpdating ? 'prv63-notice-info' : 'prv63-notice-success' }}" role="status">
        <span aria-hidden="true">{{ $isUpdating ? '↻' : '✓' }}</span>
        <div>
            <strong>{{ $isUpdating ? 'Processamento ou pesquisa em andamento' : 'Atualização de processamento disponível' }}</strong>
            <p>A atualização automática acompanha esta rodada quando existem tarefas pendentes. Ela não inicia uma nova pesquisa.</p>
            <div class="prv63-progress-inline" role="progressbar" aria-label="Processamento da rodada" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><span style="width:{{ $percent }}%"></span></div>
            <small>Processamento da rodada: {{ $number($processed) }} de {{ $number($total) }} · {{ $number($pending) }} pendente(s)</small>
        </div>
    </div>

    <details class="prv63-criteria"><summary>Critérios desta rodada <span aria-hidden="true">⌄</span></summary>
        <div class="prv63-criteria-grid">
            <div><strong>Estados</strong><p>{{ is_array($states) && $states !== [] ? implode(', ', $states) : 'Não informados' }}</p></div>
            <div><strong>CNAEs</strong><p>{{ is_array($cnaes) && $cnaes !== [] ? implode(', ', $cnaes) : 'Não informados' }}</p></div>
        </div>
    </details>

    <section class="prv63-card prv63-table-card prv63-results-card" id="prv63-results" aria-labelledby="prv63-results-title">
        <div class="prv63-card-heading"><div><h2 id="prv63-results-title">Resultados da qualificação</h2><small>Empresas da rodada · {{ $number(count($items)) }} registro(s) no filtro {{ $activeLabel }}</small></div></div>
        <p class="prv63-data-note" id="prv63-excel-note">
            @if ($canExportCurrentFilter)
                O Excel respeita o filtro <strong>{{ $activeLabel }}</strong>.
            @else
                Excel disponível para Todos, Leads, Bloqueados, Exportadores e Falhas. O filtro atual não é suportado pelo exportador.
            @endif
            Os estados de ICP, CRM, pesquisa e SDR refletem os dados atuais; descobertos e enviados são registros da rodada original.
        </p>

        <div wire:loading.flex wire:target="filter,exportExcel" class="prv63-notice prv63-notice-info" role="status">Atualizando resultados...</div>
        @if ($items !== [])
            <div class="prv63-table-scroll" tabindex="0" role="region" aria-label="Resultados da rodada"><table class="prv63-table">
                <thead><tr><th>Empresa / CNPJ</th><th>ICP</th><th>CRM</th><th>Exportação</th><th>SDR</th><th>Resultado</th></tr></thead>
                @foreach ($items as $item)
                    @php
                        $itemId = (string) ($item['id'] ?? '');
                        $cnpj = (string) ($item['cnpj'] ?? '');
                        $itemName = $item['company_name'] ?: (($item['item_status'] ?? '') === 'failed' ? 'Cadastro não concluído' : 'Cadastro ainda não disponível');
                        $outcome = (string) ($item['outcome'] ?? 'Pendente');
                        $outcomeTone = match ($outcome) { 'Lead' => 'is-success', 'Cliente', 'Oportunidade', 'Bloqueado' => 'is-warning', 'Falha' => 'is-error', default => '' };
                        $researchStatus = (string) ($item['export_research_status'] ?? '');
                        $itemStatus = match ($item['item_status'] ?? '') { 'ready' => 'Pronto', 'queued' => 'Na fila', 'processing' => 'Em processamento', 'completed', 'enriched' => 'Cadastro processado', 'failed' => 'Falha no processamento', default => (string) ($item['item_status'] ?? 'Não informado') };
                        $hubspotUrl = $item['hubspot_url'] ?? null;
                        $safeHubspotUrl = is_string($hubspotUrl) && in_array(strtolower((string) parse_url($hubspotUrl, PHP_URL_SCHEME)), ['https','http'], true) ? $hubspotUrl : null;
                    @endphp
                    <tbody wire:key="prv63-item-{{ $itemId }}" x-data="{ expanded: false }" x-show="resultPage === {{ intdiv($loop->index, 6) + 1 }}" x-cloak>
                        <tr>
                            <td>
                                <strong>{{ $itemName }}</strong>
                                <small>{{ $cnpj !== '' ? \App\Support\Cnpj::format($cnpj) : 'CNPJ não informado' }}</small>
                                <button type="button" class="prv63-row-details" x-on:click="expanded=!expanded" x-bind:aria-expanded="expanded" aria-controls="pr-detail-{{ $itemId }}">Detalhes <span aria-hidden="true">⌄</span></button>
                            </td>
                            <td>{{ $item['icp_grade'] ?? '—' }}{{ ($item['icp_score'] ?? null) !== null ? ' · '.$item['icp_score'] : '' }}</td>
                            <td>{{ $item['crm_label'] ?? 'Não verificado' }}</td>
                            <td>
                                <span>{{ $item['export_label'] ?? 'Não pesquisada' }}</span>
                                @if ($researchStatus === 'queued')<small>Pesquisa pública na fila</small>@endif
                                @if ($researchStatus === 'processing')<small>Pesquisa pública em andamento</small>@endif
                            </td>
                            <td>{{ $item['sdr_score'] ?? 'Provisório' }}</td>
                            <td><span class="prv63-status {{ $outcomeTone }}">{{ $outcome }}</span>
                                @if ($item['sdr_eligible'] === true && in_array($researchStatus, ['queued','processing'], true))<small>Pesquisa pendente</small>@endif
                            </td>
                        </tr>
                        <tr id="pr-detail-{{ $itemId }}" x-show="expanded" x-cloak class="prv63-expanded-row"><td colspan="6">
                            <div class="prv63-expanded-content">
                                <div><strong>Processamento</strong><p>{{ $itemStatus }}</p></div>
                                <div><strong>Verificação CRM</strong><p>{{ $item['crm_label'] ?? 'Não verificado' }}</p></div>
                                <div><strong>Pesquisa pública</strong><p>{{ $item['export_label'] ?? 'Não pesquisada' }}</p></div>
                                <div><strong>Resultado</strong><p>{{ $outcome }}</p></div>
                            </div>
                            @if (! empty($item['blocked_reason']))<p class="prv63-detail-note"><strong>Motivo:</strong> {{ $item['blocked_reason'] }}</p>@endif
                            @if (! empty($item['error']))<p class="prv63-detail-error">{{ $item['error'] }}</p>@endif
                            <div class="prv63-inline-actions">
                                @if ($item['company_uuid'])<a class="prv63-button prv63-button-outline prv63-button-small" href="{{ route('companies.show', $item['company_uuid']) }}" wire:navigate>Abrir dossiê →</a>
                                @else <span>Dossiê indisponível</span> @endif
                                @if ($safeHubspotUrl)<a class="prv63-button prv63-button-outline prv63-button-small" href="{{ $safeHubspotUrl }}" target="_blank" rel="noopener noreferrer">Abrir HubSpot ↗</a>@endif
                            </div>
                        </td></tr>
                    </tbody>
                @endforeach
            </table></div>
            <div class="prv63-table-footer"><span>Exibindo até 6 por página · <span x-text="resultPage">1</span> de {{ $resultPages }} · {{ $number(count($items)) }} empresa(s)</span>
                <div class="prv63-inline-actions">
                    <button type="button" class="prv63-button prv63-button-outline prv63-button-small" x-on:click="resultPage=Math.max(1,resultPage-1)" x-bind:disabled="resultPage<=1">Anterior</button>
                    <button type="button" class="prv63-button prv63-button-outline prv63-button-small" x-on:click="resultPage=Math.min({{ $resultPages }},resultPage+1)" x-bind:disabled="resultPage>={{ $resultPages }}">Próxima</button>
                </div>
            </div>
        @else
            <div class="prv63-empty"><h3>Nenhuma empresa neste filtro</h3><p>Selecione outro grupo ou volte para todos os registros da rodada.</p>
                @if ($filter !== 'all')<button type="button" class="prv63-button prv63-button-outline" wire:click="$set('filter', 'all')" x-on:click="resultPage=1">Mostrar todos</button>@endif
            </div>
        @endif
    </section>

    @if ($firstFailure)
        <div class="prv63-notice prv63-notice-error" role="alert"><strong>Falha por item</strong><p>{{ $firstFailure['error'] ?? 'Consulte o detalhe da empresa para verificar o motivo registrado.' }}</p></div>
    @endif
    <footer class="prv63-page-footer">ExportControl Prospector · Atualização de rodada com dados reais do pipeline</footer>
</div>
