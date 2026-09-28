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
                'text-[#cbd1e7]',
        };
    }
};
?>

<div
    class="ec-page-shell pr-workspace pr-run-workspace"
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
            ['label' => 'Enviados', 'value' => $total, 'help' => 'Empresas nesta rodada', 'tone' => '', 'filter' => 'all'],
            ['label' => 'Processados', 'value' => $processed, 'help' => 'Processamento cadastral', 'tone' => '', 'filter' => null],
            ['label' => 'Leads', 'value' => $detail['lead_count'] ?? 0, 'help' => 'Elegíveis pelo score SDR', 'tone' => 'success', 'filter' => 'leads'],
            ['label' => 'Bloqueados', 'value' => $detail['blocked_count'] ?? 0, 'help' => 'Não elegíveis pelo SDR', 'tone' => 'warning', 'filter' => 'blocked'],
            ['label' => 'Pesquisados', 'value' => $detail['researched_count'] ?? 0, 'help' => 'Pesquisa web concluída', 'tone' => '', 'filter' => null],
            ['label' => 'Exportadores', 'value' => $detail['export_identified_count'] ?? 0, 'help' => 'Sinais classificados pelo motor', 'tone' => 'info', 'filter' => 'exporters'],
            ['label' => 'Falhas', 'value' => $detail['failed_count'] ?? 0, 'help' => 'Falhas de processamento', 'tone' => 'danger', 'filter' => 'failed'],
        ];
    @endphp

    <nav class="pr-breadcrumb" aria-label="Navegação da rodada">
        <a href="{{ route('prospecting.index') }}" wire:navigate>@include('pages.prospecting.partials.workspace-icon', ['name' => 'back']) Motor de Prospecção</a>
        <span aria-hidden="true">/</span><span>Rodada</span>
    </nav>

    <header class="pr-page-header">
        <div>
            <span class="pr-eyebrow">RODADA DE PROSPECÇÃO</span>
            <h1>{{ $detail['created_label'] ?? 'Detalhe da rodada' }}</h1>
            <p class="pr-run-id">Lote <span class="pr-mono">{{ $detail['uuid'] }}</span></p>
        </div>
        <div class="pr-header-actions">
            <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="pr-btn pr-btn-secondary">
                @include('pages.prospecting.partials.workspace-icon', ['name' => 'refresh']) Atualizar
            </button>
            <button type="button" title="{{ $canExportCurrentFilter ? 'Exportar resultado com o filtro selecionado' : 'O exportador atual não suporta este filtro' }}" @disabled(! $canExportCurrentFilter) aria-describedby="pr-export-note" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel,filter" class="pr-btn pr-btn-primary">
                @include('pages.prospecting.partials.workspace-icon', ['name' => 'download'])
                <span wire:loading.remove wire:target="exportExcel">Exportar Excel</span>
                <span wire:loading wire:target="exportExcel">Gerando Excel…</span>
            </button>
        </div>
    </header>

    <section class="pr-panel pr-run-progress" aria-labelledby="pr-processing-title">
        <div class="pr-run-progress-main">
            <div class="pr-progress-top">
                <div><h2 id="pr-processing-title">Processamento da rodada</h2><p>{{ $number($processed) }} de {{ $number($total) }} enviados · {{ $number($pending) }} ainda não processados</p></div>
                <span class="pr-badge pr-badge-{{ $runTone }}">{{ $detail['status_label'] ?? 'Status não informado' }}</span>
            </div>
            <div class="pr-progress-line"><div class="pr-progress-track" role="progressbar" aria-label="Processamento cadastral da rodada" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><span style="width: {{ $percent }}%"></span></div><strong>{{ $percent }}%</strong></div>
            <p class="pr-help">A barra acompanha o processamento cadastral. A pesquisa pública tem andamento separado.</p>
        </div>
        <div class="pr-run-progress-aside">
            <span class="pr-small-label">PESQUISA DE EXPORTAÇÃO</span>
            <strong>{{ $number($researchPending) }} <small>na fila ou em pesquisa</small></strong>
            <p>{{ $number($detail['researched_count'] ?? 0) }} pesquisas concluídas</p>
            <span class="pr-live-indicator"><i class="{{ $isUpdating ? 'is-live' : '' }}" aria-hidden="true"></i>{{ $isUpdating ? 'Atualização automática a cada 10 s' : 'Sem processamento ativo identificado' }}</span>
        </div>
    </section>

    <section class="pr-run-metrics" aria-label="Indicadores da rodada">
        @foreach ($metrics as $metric)
            <div class="pr-run-metric {{ $metric['tone'] ? 'pr-tone-'.$metric['tone'] : '' }}">
                @if ($metric['filter'] !== null)
                    <button type="button" wire:click="$set('filter', '{{ $metric['filter'] }}')" wire:loading.attr="disabled" wire:target="filter,exportExcel" aria-label="Filtrar {{ $metric['label'] }}">
                        <span class="pr-small-label">{{ $metric['label'] }}</span><strong>{{ $number($metric['value']) }}</strong><small>{{ $metric['help'] }}</small>
                        <span class="pr-metric-arrow" aria-hidden="true">↗</span>
                    </button>
                @else
                    <div><span class="pr-small-label">{{ $metric['label'] }}</span><strong>{{ $number($metric['value']) }}</strong><small>{{ $metric['help'] }}</small></div>
                @endif
            </div>
        @endforeach
    </section>

    <details class="pr-panel pr-run-criteria">
        <summary>@include('pages.prospecting.partials.workspace-icon', ['name' => 'sliders']) Critérios desta rodada <span>{{ is_array($states) ? count($states) : 0 }} estados · {{ is_array($cnaes) ? count($cnaes) : 0 }} CNAEs</span></summary>
        <div class="pr-criteria-body">
            <div><h3>Estados</h3><div class="pr-process-tags">
                @forelse (is_array($states) ? $states : [] as $state)
                    <span>{{ $state }}</span>
                @empty
                    <span>Não registrados</span>
                @endforelse
            </div></div>
            <div><h3>CNAEs</h3><div class="pr-process-tags">
                @forelse (is_array($cnaes) ? $cnaes : [] as $cnae)
                    <span class="pr-mono">{{ $cnae }}</span>
                @empty
                    <span>Não registrados</span>
                @endforelse
            </div></div>
        </div>
    </details>

    <section class="pr-panel" id="pr-run-results" aria-labelledby="pr-run-results-title">
        <div class="pr-panel-heading">
            <div><h2 id="pr-run-results-title">Empresas da rodada</h2><p>Resultado individual do pipeline comercial. Expanda uma linha para ver os detalhes.</p></div>
            <span class="pr-tag">{{ $number(count($items)) }} registros · {{ $activeLabel }}</span>
        </div>
        <div class="pr-result-filters" role="group" aria-label="Filtrar empresas por resultado">
            @foreach ($tabs as $tab)
                <button type="button" class="pr-filter-chip {{ $filter === $tab['key'] ? 'is-active' : '' }}" wire:click="$set('filter', '{{ $tab['key'] }}')" wire:loading.attr="disabled" wire:target="filter,exportExcel" aria-pressed="{{ $filter === $tab['key'] ? 'true' : 'false' }}">
                    {{ $tab['label'] }} <span>{{ $number($tab['count']) }}</span>
                </button>
            @endforeach
        </div>
        <div class="pr-results-note">
            <span id="pr-export-note">
                @if ($canExportCurrentFilter)
                    O Excel respeita o filtro: <strong>{{ $activeLabel }}</strong>.
                @else
                    Excel disponível para Todos, Leads, Bloqueados, Exportadores e Falhas. O filtro atual não é suportado pelo exportador.
                @endif
            </span>
            <span>Os grupos podem se sobrepor; não some suas contagens.</span>
        </div>
        <div wire:loading.flex wire:target="filter,exportExcel" class="pr-table-loading" role="status"><span class="pr-spinner" aria-hidden="true"></span> Preparando os dados…</div>

        @if ($items !== [])
            <div class="pr-table-scroll pr-results-scroll" tabindex="0" role="region" aria-label="Tabela de empresas e resultados da rodada">
                <table class="pr-table pr-results-table">
                    <thead><tr><th scope="col">Empresa / CNPJ</th><th scope="col">ICP</th><th scope="col">CRM</th><th scope="col">Exportação</th><th scope="col">SDR</th><th scope="col">Resultado</th><th scope="col">Ações</th></tr></thead>
                    @foreach ($items as $item)
                        @php
                            $itemId = (string) $item['id'];
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
                        <tbody data-cnpj="{{ $cnpj }}" wire:key="pr-result-{{ $itemId }}" x-data="{ expanded: false }">
                            <tr>
                                <td class="pr-company-cell"><strong>{{ $companyName }}</strong><span class="pr-mono">{{ $cnpj !== '' ? \App\Support\Cnpj::format($cnpj) : 'CNPJ não informado' }}</span>
                                    <button type="button" class="pr-details-trigger" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded" aria-controls="pr-detail-{{ $itemId }}">
                                        <span x-text="expanded ? 'Ocultar detalhes' : 'Ver detalhes'">Ver detalhes</span> <span aria-hidden="true" x-text="expanded ? '−' : '+'">+</span>
                                    </button>
                                </td>
                                <td><strong class="pr-score-small">{{ $item['icp_grade'] ?? '—' }}</strong><small>{{ $item['icp_score'] !== null ? $item['icp_score'].' / 100' : 'Não calculado' }}</small></td>
                                <td><span class="pr-crm-label">{{ $item['crm_label'] ?? 'Não verificado' }}</span>
                                    @if ($safeHubspotUrl)
                                        <a href="{{ $safeHubspotUrl }}" target="_blank" rel="noopener noreferrer" class="pr-link pr-link-small">Abrir HubSpot ↗</a>
                                    @endif
                                </td>
                                <td><span class="pr-badge pr-badge-{{ $researchTone }}">{{ $item['export_label'] ?? 'Não pesquisada' }}</span></td>
                                <td><strong class="pr-score-small">{{ $item['sdr_score'] ?? '—' }}</strong></td>
                                <td><span class="pr-badge pr-badge-{{ $outcomeTone }}">{{ $outcome }}</span>
                                    @if ($item['sdr_eligible'] === true && in_array($researchStatus, ['queued', 'processing'], true))
                                        <small>Pesquisa ainda pendente</small>
                                    @endif
                                </td>
                                <td>
                                    @if ($item['company_uuid'])
                                        <a href="{{ route('companies.show', $item['company_uuid']) }}" wire:navigate class="pr-btn pr-btn-small pr-btn-secondary">Abrir dossiê →</a>
                                    @else
                                        <small>{{ ($item['item_status'] ?? '') === 'failed' ? 'Dossiê indisponível' : 'Aguardando cadastro' }}</small>
                                    @endif
                                </td>
                            </tr>
                            <tr class="pr-expanded-row" id="pr-detail-{{ $itemId }}" x-show="expanded" x-cloak>
                                <td colspan="7">
                                    <dl class="pr-detail-grid">
                                        <div><dt>CNPJ</dt><dd class="pr-mono">{{ $cnpj !== '' ? \App\Support\Cnpj::format($cnpj) : 'Não informado' }}</dd></div>
                                        <div><dt>Processamento</dt><dd>{{ $itemStatusLabel }}</dd></div>
                                        <div><dt>Verificação CRM</dt><dd>{{ $item['crm_label'] ?? 'Não verificado' }}</dd></div>
                                        <div><dt>Pesquisa pública</dt><dd>{{ $item['export_label'] ?? 'Não pesquisada' }}</dd></div>
                                    </dl>
                                    @if (! empty($item['blocked_reason']))
                                        <div class="pr-detail-message"><strong>Motivo da classificação</strong><p>{{ $item['blocked_reason'] }}</p></div>
                                    @endif
                                    @if (! empty($item['error']))
                                        <div class="pr-detail-message pr-value-danger"><strong>Erro registrado</strong><p>{{ $item['error'] }}</p></div>
                                    @endif
                                    <p class="pr-help">Resultado e score são os registrados no pipeline. A pesquisa pode continuar após o cadastro; evidências e informações complementares ficam no dossiê.</p>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @else
            <div class="pr-empty">
                <span class="pr-empty-icon">@include('pages.prospecting.partials.workspace-icon', ['name' => 'list'])</span>
                <h3>Nenhuma empresa neste filtro</h3><p>Selecione outro resultado ou volte para todos os registros da rodada.</p>
                @if ($filter !== 'all')
                    <button type="button" class="pr-btn pr-btn-secondary" wire:click="$set('filter', 'all')">Mostrar todos</button>
                @endif
            </div>
        @endif
    </section>
    <footer class="pr-footnote"><span>Contagens e classificações fornecidas pelo pipeline existente.</span><span>{{ $isUpdating ? 'Dados atualizados enquanto a tela está visível.' : 'Use Atualizar para consultar novamente.' }}</span></footer>
</div>
