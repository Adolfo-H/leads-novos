<?php

use App\Models\ImportBatch;
use App\Services\ProspectingRunDetailService;
use App\Services\ProspectingRunExcelService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ImportBatch $batch;

    public string $filter = 'all';

    public function mount(
        ImportBatch $batch
    ): void {
        abort_unless(
            $batch->source_type
                === 'prospecting',
            404
        );

        $this->batch =
            $batch;
    }

    #[Computed]
    public function detail(): array
    {
        return app(
            ProspectingRunDetailService::class
        )->build(
            $this->batch
        );
    }

    #[Computed]
    public function filteredItems(): array
    {
        $items =
            $this->detail[
                'items'
            ];

        return array_values(
            array_filter(
                $items,
                function (
                    array $item
                ): bool {
                    return match (
                        $this->filter
                    ) {
                        'leads' =>
                            $item[
                                'sdr_eligible'
                            ] === true,

                        'blocked' =>
                            $item[
                                'sdr_eligible'
                            ] === false,

                        'new_crm' =>
                            (
                                $item[
                                    'crm_status'
                                ]
                                ?? null
                            ) === 'not_found',

                        'reprospecting' =>
                            (
                                $item[
                                    'crm_status'
                                ]
                                ?? null
                            ) === 'prospected',

                        'opportunities' =>
                            (
                                $item[
                                    'crm_status'
                                ]
                                ?? null
                            ) === 'opportunity',

                        'clients' =>
                            (
                                $item[
                                    'crm_status'
                                ]
                                ?? null
                            ) === 'client',

                        'exporters' =>
                            (
                                $item[
                                    'export_identified'
                                ]
                                ?? false
                            ) === true,

                        'failed' =>
                            $item[
                                'item_status'
                            ] === 'failed',

                        default =>
                            true,
                    };
                }
            )
        );
    }

    public function exportExcel(
        ProspectingRunExcelService $excel
    ): StreamedResponse {
        return $excel->download(
            batch: $this->batch,
            filter: $this->filter,
        );
    }

    public function crmStatusCount(
        string $status
    ): int {
        $items =
            $this->detail[
                'items'
            ]
            ?? [];

        if (! is_array($items)) {
            return 0;
        }

        return count(
            array_filter(
                $items,
                static fn (
                    array $item
                ): bool =>
                    (
                        $item[
                            'crm_status'
                        ]
                        ?? null
                    ) === $status
            )
        );
    }

    public function outcomeClass(
        string $outcome
    ): string {
        return match ($outcome) {
            'Lead' =>
                'text-emerald-300',

            'Cliente',
            'Oportunidade',
            'Bloqueado' =>
                'text-amber-300',

            'Falha' =>
                'text-red-300',

            'Processando' =>
                'text-cyan-300',

            default =>
                'text-[var(--ec-text-soft)]',
        };
    }
};
?>

{{--
    Detalhe de Rodada · ExportControl · Workspace V2
    Consome os dados reais de ProspectingRunDetailService e mantém os
    filtros e a exportação da implementação existente.
--}}
<div
    class="ec-page-shell pr-workspace pr-run-workspace prv2-page prv2-run-page"
    data-test="prospecting-run-workspace"
    @if (in_array($this->detail['status'] ?? '', ['ready', 'processing'], true) || collect($this->detail['items'] ?? [])->contains(static fn ($row) => in_array($row['export_research_status'] ?? '', ['queued', 'processing'], true)))
        wire:poll.visible.10s
    @endif
>
    @php
        $detail = $this->detail;
        $items = $this->filteredItems;
        $allItems = $detail['items'] ?? [];
        $total = max(0, (int) ($detail['total_rows'] ?? 0));
        $processed = max(0, (int) ($detail['processed_rows'] ?? 0));
        $percent = $total > 0 ? min(100, (int) round($processed * 100 / $total)) : 0;
        $pending = max(0, $total - $processed);
        $researchPending = count(array_filter($allItems, static fn ($row) => in_array($row['export_research_status'] ?? '', ['queued', 'processing'], true)));
        $runStatus = (string) ($detail['status'] ?? '');
        $runTone = match ($runStatus) { 'completed' => 'success', 'failed' => 'danger', 'processing' => 'info', default => 'neutral' };
        $isUpdating = in_array($runStatus, ['ready', 'processing'], true) || $researchPending > 0;
        $number = static fn ($value) => number_format((int) $value, 0, ',', '.');
        $states = data_get($detail, 'filters.states', []);
        $cnaes = data_get($detail, 'filters.cnaes', []);
        $tabs = [
            ['key' => 'all', 'label' => 'Todos', 'count' => count($allItems)],
            ['key' => 'leads', 'label' => 'Leads', 'count' => $detail['lead_count'] ?? 0],
            ['key' => 'blocked', 'label' => 'Bloqueados', 'count' => $detail['blocked_count'] ?? 0],
            ['key' => 'new_crm', 'label' => 'Novos no CRM', 'count' => $this->crmStatusCount('not_found')],
            ['key' => 'reprospecting', 'label' => 'Reprospecção', 'count' => $this->crmStatusCount('prospected')],
            ['key' => 'opportunities', 'label' => 'Oportunidades', 'count' => $this->crmStatusCount('opportunity')],
            ['key' => 'clients', 'label' => 'Clientes', 'count' => $this->crmStatusCount('client')],
            ['key' => 'exporters', 'label' => 'Exportadores', 'count' => $detail['export_identified_count'] ?? 0],
            ['key' => 'failed', 'label' => 'Falhas', 'count' => $detail['failed_count'] ?? 0],
        ];
        $activeLabel = collect($tabs)->firstWhere('key', $filter)['label'] ?? 'Todos';
        $canExportCurrentFilter = in_array($filter, ['all', 'leads', 'blocked', 'exporters', 'failed'], true);
        $metrics = [
            ['label' => 'Descobertos', 'value' => $detail['discovered_count'] ?? 0, 'help' => 'Candidatos examinados', 'tone' => '', 'filter' => null],
            ['label' => 'Enviados', 'value' => $total, 'help' => 'Empresas nesta rodada', 'tone' => 'info', 'filter' => 'all'],
            ['label' => 'Processados', 'value' => $processed, 'help' => 'Enriquecimento cadastral', 'tone' => '', 'filter' => null],
            ['label' => 'Leads', 'value' => $detail['lead_count'] ?? 0, 'help' => 'Elegíveis pelo score SDR atual', 'tone' => 'success', 'filter' => 'leads'],
            ['label' => 'Bloqueados', 'value' => $detail['blocked_count'] ?? 0, 'help' => 'Não elegíveis pelo SDR atual', 'tone' => 'warning', 'filter' => 'blocked'],
            ['label' => 'Pesquisados', 'value' => $detail['researched_count'] ?? 0, 'help' => 'Pesquisa pública concluída', 'tone' => '', 'filter' => null],
            ['label' => 'Exportadores', 'value' => $detail['export_identified_count'] ?? 0, 'help' => 'Indícios classificados pelo motor', 'tone' => 'success', 'filter' => 'exporters'],
            ['label' => 'Falhas', 'value' => $detail['failed_count'] ?? 0, 'help' => 'Falhas de processamento', 'tone' => 'danger', 'filter' => 'failed'],
        ];
    @endphp

    <nav class="prv2-breadcrumb" aria-label="Navegação da rodada">
        <a href="{{ route('prospecting.index') }}" wire:navigate>@include('pages.prospecting.partials.workspace-icon', ['name' => 'back']) Motor de Prospecção</a>
        <span aria-hidden="true">›</span>
        <span>Detalhe da rodada</span>
    </nav>

    <header class="prv2-run-header">
        <div>
            <span class="prv2-overline">ACOMPANHAMENTO DE PROSPECÇÃO</span>
            <h1>Rodada de {{ $detail['created_label'] ?? 'prospecção' }}</h1>
            <p>Progresso de enriquecimento, análise comercial e resultados das empresas enviadas.</p>
            <small class="prv2-run-uuid">Lote {{ $detail['uuid'] ?? '—' }}</small>
        </div>
        <div class="prv2-actions">
            <button type="button" class="prv2-button prv2-button-outline" wire:click="$refresh" wire:loading.attr="disabled">
                @include('pages.prospecting.partials.workspace-icon', ['name' => 'refresh']) Atualizar
            </button>
            <button type="button" class="prv2-button prv2-button-primary"
                title="{{ $canExportCurrentFilter ? 'Exportar resultado com o filtro selecionado' : 'O exportador atual não suporta este filtro' }}"
                @disabled(! $canExportCurrentFilter) aria-describedby="prv2-export-note"
                wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel,filter">
                @include('pages.prospecting.partials.workspace-icon', ['name' => 'download'])
                <span wire:loading.remove wire:target="exportExcel">Exportar Excel</span>
                <span wire:loading wire:target="exportExcel">Gerando Excel...</span>
            </button>
        </div>
    </header>

    <section class="prv2-panel prv2-run-progress" aria-labelledby="prv2-run-progress-title">
        <div class="prv2-run-progress-main">
            <div class="prv2-panel-heading prv2-progress-heading">
                <div><h2 id="prv2-run-progress-title">Processamento da rodada</h2><p>{{ $number($processed) }} de {{ $number($total) }} processados · {{ $number($pending) }} pendentes</p></div>
                <span class="prv2-badge prv2-badge-{{ $runTone }}">{{ $detail['status_label'] ?? 'Não informado' }}</span>
            </div>
            <div class="prv2-progress-line">
                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="Progresso do processamento cadastral"><span style="width: {{ $percent }}%"></span></div>
                <strong>{{ $percent }}%</strong>
            </div>
            <p class="prv2-run-explainer">O percentual considera o processamento cadastral; a pesquisa pública é acompanhada separadamente.</p>
        </div>
        <aside class="prv2-run-progress-side">
            <span class="prv2-overline">PESQUISA DE EXPORTAÇÃO</span>
            <strong>{{ $number($researchPending) }} <small>na fila ou em pesquisa</small></strong>
            <p>{{ $number($detail['researched_count'] ?? 0) }} pesquisas concluídas</p>
            <span class="prv2-live-status"><i class="{{ $isUpdating ? 'is-live' : '' }}" aria-hidden="true"></i>{{ $isUpdating ? 'Atualização automática a cada 10 s' : 'Sem processamento ativo identificado' }}</span>
        </aside>
    </section>

    <section class="prv2-run-metrics" aria-label="Indicadores da rodada">
        @foreach ($metrics as $metric)
            <div class="prv2-run-metric {{ $metric['tone'] ? 'prv2-tone-'.$metric['tone'] : '' }}">
                @if ($metric['filter'] !== null)
                    <button type="button" wire:click="$set('filter', '{{ $metric['filter'] }}')" wire:loading.attr="disabled" wire:target="filter,exportExcel" aria-label="Filtrar {{ $metric['label'] }}">
                        <span>{{ $metric['label'] }}</span><strong>{{ $number($metric['value']) }}</strong><small>{{ $metric['help'] }}</small><b aria-hidden="true">↗</b>
                    </button>
                @else
                    <div><span>{{ $metric['label'] }}</span><strong>{{ $number($metric['value']) }}</strong><small>{{ $metric['help'] }}</small></div>
                @endif
            </div>
        @endforeach
    </section>

    <details class="prv2-panel prv2-run-criteria">
        <summary>@include('pages.prospecting.partials.workspace-icon', ['name' => 'sliders']) Critérios desta rodada <span>{{ is_array($states) ? count($states) : 0 }} estados · {{ is_array($cnaes) ? count($cnaes) : 0 }} CNAEs</span></summary>
        <div class="prv2-criteria-body">
            <div><h3>Estados</h3><div class="prv2-process-tags">
                @forelse (is_array($states) ? $states : [] as $state)
                    <span>{{ $state }}</span>
                @empty
                    <span>Não registrados</span>
                @endforelse
            </div></div>
            <div><h3>CNAEs</h3><div class="prv2-process-tags">
                @forelse (is_array($cnaes) ? $cnaes : [] as $cnae)
                    <span>{{ $cnae }}</span>
                @empty
                    <span>Não registrados</span>
                @endforelse
            </div></div>
        </div>
    </details>

    <section class="prv2-panel prv2-run-results" id="pr-run-results" aria-labelledby="prv2-run-results-title">
        <div class="prv2-panel-heading">
            <div class="prv2-heading-group">
                <span class="prv2-heading-icon" aria-hidden="true">@include('pages.prospecting.partials.workspace-icon', ['name' => 'list'])</span>
                <div><h2 id="prv2-run-results-title">Empresas da rodada</h2><p>Resultados individuais do pipeline comercial, com informações e ações disponíveis.</p></div>
            </div>
            <span class="prv2-source-pill">{{ $number(count($items)) }} registros · {{ $activeLabel }}</span>
        </div>
        <div class="prv2-filters" role="group" aria-label="Filtrar empresas por resultado">
            @foreach ($tabs as $tab)
                <button type="button" class="prv2-filter-chip {{ $filter === $tab['key'] ? 'is-active' : '' }}"
                    wire:click="$set('filter', '{{ $tab['key'] }}')" wire:loading.attr="disabled" wire:target="filter,exportExcel"
                    aria-pressed="{{ $filter === $tab['key'] ? 'true' : 'false' }}">
                    {{ $tab['label'] }} <span>{{ $number($tab['count']) }}</span>
                </button>
            @endforeach
        </div>
        <div class="prv2-results-note" id="prv2-export-note">
            <p>
                @if ($canExportCurrentFilter)
                    O Excel respeita o filtro <strong>{{ $activeLabel }}</strong>.
                @else
                    Excel disponível para Todos, Leads, Bloqueados, Exportadores e Falhas. O filtro atual não é suportado pelo exportador.
                @endif
            </p>
            <p>ICP, CRM, pesquisa e SDR refletem os dados atuais da empresa; descobertos e enviados pertencem à execução original. Alguns grupos se sobrepõem.</p>
        </div>
        <div wire:loading.flex wire:target="filter,exportExcel" class="prv2-notice prv2-table-loading" role="status"><span class="prv2-spinner" aria-hidden="true"></span>Atualizando resultados...</div>

        @if ($items !== [])
            <div class="prv2-table-scroll prv2-run-table-scroll" tabindex="0" role="region" aria-label="Tabela de resultados da rodada">
                <table class="prv2-table prv2-results-table">
                    <thead><tr><th scope="col">Empresa / CNPJ</th><th scope="col">ICP</th><th scope="col">CRM</th><th scope="col">Exportação</th><th scope="col">SDR</th><th scope="col">Resultado</th><th scope="col">Ações</th></tr></thead>
                    @foreach ($items as $item)
                        @php
                            $itemId = (string) ($item['id'] ?? '');
                            $cnpj = (string) ($item['cnpj'] ?? '');
                            $companyName = $item['company_name'] ?: (($item['item_status'] ?? '') === 'failed' ? 'Cadastro não concluído' : 'Cadastro ainda não disponível');
                            $outcome = (string) ($item['outcome'] ?? 'Pendente');
                            $outcomeTone = match ($outcome) { 'Lead' => 'success', 'Cliente', 'Oportunidade', 'Bloqueado' => 'warning', 'Falha' => 'danger', 'Processando' => 'info', default => 'neutral' };
                            $researchStatus = (string) ($item['export_research_status'] ?? '');
                            $researchTone = match ($researchStatus) { 'queued', 'processing' => 'info', 'failed' => 'danger', 'completed' => 'success', default => 'neutral' };
                            $itemStatusLabel = match ($item['item_status'] ?? '') { 'ready' => 'Pronto para processar', 'queued' => 'Na fila de processamento', 'processing' => 'Em processamento', 'completed', 'enriched' => 'Processamento concluído', 'failed' => 'Falha no processamento', default => (string) ($item['item_status'] ?? 'Não informado') };
                            $hubspotUrl = $item['hubspot_url'] ?? null;
                            $safeHubspotUrl = is_string($hubspotUrl) && in_array(strtolower((string) parse_url($hubspotUrl, PHP_URL_SCHEME)), ['https', 'http'], true) ? $hubspotUrl : null;
                        @endphp
                        <tbody data-cnpj="{{ $cnpj }}" wire:key="prv2-result-{{ $itemId }}" x-data="{ expanded: false }">
                            <tr>
                                <td class="prv2-company-cell">
                                    <strong>{{ $companyName }}</strong>
                                    <small>{{ $cnpj !== '' ? \App\Support\Cnpj::format($cnpj) : 'CNPJ não informado' }}</small>
                                    <button type="button" class="prv2-detail-toggle" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded" aria-controls="pr-detail-{{ $itemId }}">
                                        <span x-text="expanded ? 'Ocultar detalhes' : 'Ver detalhes'">Ver detalhes</span><span aria-hidden="true" x-text="expanded ? '−' : '+'">+</span>
                                    </button>
                                </td>
                                <td><strong class="prv2-icp-grade">{{ $item['icp_grade'] ?? '—' }}</strong><small>{{ $item['icp_score'] !== null ? $item['icp_score'].' / 100' : 'Não calculado' }}</small></td>
                                <td><span>{{ $item['crm_label'] ?? 'Não verificado' }}</span>
                                    @if ($safeHubspotUrl)
                                        <a href="{{ $safeHubspotUrl }}" target="_blank" rel="noopener noreferrer" class="prv2-link">Abrir HubSpot ↗</a>
                                    @endif
                                </td>
                                <td><span class="prv2-badge prv2-badge-{{ $researchTone }}">{{ $item['export_label'] ?? 'Não pesquisada' }}</span>
                                    @if ($researchStatus === 'queued') <small>Na fila</small> @endif
                                    @if ($researchStatus === 'processing') <small>Pesquisa pública em andamento</small> @endif
                                </td>
                                <td><strong class="prv2-score">{{ $item['sdr_score'] ?? '—' }}</strong></td>
                                <td><span class="prv2-badge prv2-badge-{{ $outcomeTone }}">{{ $outcome }}</span>
                                    @if ($item['sdr_eligible'] === true && in_array($researchStatus, ['queued', 'processing'], true))
                                        <small>Pesquisa ainda pendente</small>
                                    @endif
                                </td>
                                <td>
                                    @if ($item['company_uuid'])
                                        <a href="{{ route('companies.show', $item['company_uuid']) }}" wire:navigate class="prv2-button prv2-button-outline prv2-button-small">Abrir dossiê →</a>
                                    @else
                                        <small>{{ ($item['item_status'] ?? '') === 'failed' ? 'Dossiê indisponível' : 'Aguardando cadastro' }}</small>
                                    @endif
                                </td>
                            </tr>
                            <tr id="pr-detail-{{ $itemId }}" class="prv2-expanded-row" x-show="expanded" x-cloak>
                                <td colspan="7">
                                    <dl class="prv2-detail-grid">
                                        <div><dt>CNPJ</dt><dd>{{ $cnpj !== '' ? \App\Support\Cnpj::format($cnpj) : 'Não informado' }}</dd></div>
                                        <div><dt>Processamento</dt><dd>{{ $itemStatusLabel }}</dd></div>
                                        <div><dt>Verificação CRM</dt><dd>{{ $item['crm_label'] ?? 'Não verificado' }}</dd></div>
                                        <div><dt>Pesquisa pública</dt><dd>{{ $item['export_label'] ?? 'Não pesquisada' }}</dd></div>
                                    </dl>
                                    @if (! empty($item['blocked_reason']))
                                        <div class="prv2-detail-message"><strong>Motivo da classificação</strong><p>{{ $item['blocked_reason'] }}</p></div>
                                    @endif
                                    @if (! empty($item['error']))
                                        <div class="prv2-detail-message prv2-detail-error"><strong>Erro registrado</strong><p>{{ $item['error'] }}</p></div>
                                    @endif
                                    <p class="prv2-run-explainer">O dossiê contém evidências e informações complementares, quando disponíveis.</p>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @else
            <div class="prv2-empty">
                <h3>Nenhuma empresa neste filtro</h3>
                <p>Selecione outro grupo ou volte para todos os registros da rodada.</p>
                @if ($filter !== 'all')
                    <button type="button" class="prv2-button prv2-button-outline" wire:click="$set('filter', 'all')">Mostrar todos</button>
                @endif
            </div>
        @endif
    </section>
    <footer class="prv2-run-footer">
        <span>Contagens fornecidas pelos serviços já existentes do Prospector.</span>
        <span>{{ $isUpdating ? 'A tela atualiza enquanto estiver visível.' : 'Use Atualizar para consultar os dados novamente.' }}</span>
    </footer>
</div>
