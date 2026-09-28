<?php

use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Services\CnpjImportService;
use App\Services\ImportQueueService;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public string $input = '';

    #[Url(as: 'batch')]
    public ?int $batchId = null;

    public function process(
        CnpjImportService $service
    ): void {
        $this->validate([
            'input' => [
                'required',
                'string',
                'max:1000000',
            ],
        ]);

        $values = $service->parseText(
            $this->input
        );

        if ($values === []) {
            $this->addError(
                'input',
                'Informe pelo menos um CNPJ.'
            );

            return;
        }

        $batch = $service->import(
            values: $values,
            userId: auth()->id(),
            sourceType: 'manual',
        );

        $this->batchId = $batch->id;

        $this->input = '';

        unset(
            $this->currentBatch,
            $this->currentItems,
            $this->recentBatches,
        );
    }

    public function openBatch(
        int $batchId
    ): void {
        $batch = ImportBatch::query()
            ->findOrFail($batchId);

        $this->batchId = $batch->id;

        unset(
            $this->currentBatch,
            $this->currentItems,
            $this->currentIntelligenceCounts,
        );
    }

    public function queueCurrentBatch(
        ImportQueueService $queue
    ): void {
        if (! $this->currentBatch) {
            return;
        }

        $count = $queue->dispatchReady(
            $this->currentBatch
        );

        unset(
            $this->currentBatch,
            $this->currentItems,
            $this->currentStatusCounts,
            $this->currentIntelligenceCounts,
            $this->currentBatchIsProcessing,
            $this->currentProcessedCount,
            $this->recentBatches,
        );

        session()->flash(
            'success',
            $count === 1
                ? '1 CNPJ enviado para enriquecimento.'
                : $count.' CNPJs enviados para enriquecimento.'
        );
    }

    public function refreshCurrentBatch(
        ImportQueueService $queue
    ): void {
        $batch =
            $this->currentBatch;

        if ($batch) {
            /*
             * Além de atualizar a tela,
             * verifica se algum job ficou
             * abandonado na fila.
             */
            $queue->recoverStale(
                $batch
            );
        }

        unset(
            $this->currentBatch,
            $this->currentItems,
            $this->currentStatusCounts,
            $this->currentIntelligenceCounts,
            $this->currentBatchIsProcessing,
            $this->currentProcessedCount,
            $this->recentBatches,
        );
    }

    #[Computed]
    public function currentBatchIsProcessing(): bool
    {
        return $this->statusCount([
            'queued',
            'processing',
        ]) > 0;
    }

    #[Computed]
    public function currentProcessedCount(): int
    {
        return $this->statusCount([
            'completed',
            'failed',
        ]);
    }

    #[Computed]
    public function currentStatusCounts(): array
    {
        if (! $this->batchId) {
            return [];
        }

        return ImportItem::query()
            ->where(
                'import_batch_id',
                $this->batchId
            )
            ->selectRaw(
                'status, COUNT(*) as total'
            )
            ->groupBy('status')
            ->pluck(
                'total',
                'status'
            )
            ->map(
                fn ($total) => (int) $total
            )
            ->all();
    }

    public function statusCount(
        string|array $statuses
    ): int {
        $statuses = (array) $statuses;

        return collect($statuses)
            ->sum(
                fn ($status) => $this->currentStatusCounts[
                        $status
                    ] ?? 0
            );
    }

    /**
     * @return array{
     *     clients: int,
     *     opportunities: int,
     *     prospected: int,
     *     known: int,
     *     new: int,
     *     icp_a: int,
     *     icp_b: int,
     *     icp_c: int,
     *     icp_d: int
     * }
     */
    #[Computed]
    public function currentIntelligenceCounts(): array
    {
        $empty = [
            'clients' => 0,
            'opportunities' => 0,
            'prospected' => 0,
            'known' => 0,
            'new' => 0,
            'icp_a' => 0,
            'icp_b' => 0,
            'icp_c' => 0,
            'icp_d' => 0,
        ];

        if (! $this->batchId) {
            return $empty;
        }

        $crmCounts = DB::table('import_items')
            ->join(
                'company_crm_checks',
                'company_crm_checks.company_id',
                '=',
                'import_items.company_id'
            )
            ->where(
                'import_items.import_batch_id',
                $this->batchId
            )
            ->selectRaw(
                'company_crm_checks.status, COUNT(*) as total'
            )
            ->groupBy(
                'company_crm_checks.status'
            )
            ->pluck(
                'total',
                'company_crm_checks.status'
            );

        $icpCounts = DB::table('import_items')
            ->join(
                'company_icp_scores',
                'company_icp_scores.company_id',
                '=',
                'import_items.company_id'
            )
            ->where(
                'import_items.import_batch_id',
                $this->batchId
            )
            ->selectRaw(
                'company_icp_scores.grade, COUNT(*) as total'
            )
            ->groupBy(
                'company_icp_scores.grade'
            )
            ->pluck(
                'total',
                'company_icp_scores.grade'
            );

        return [
            'clients' => (int) ($crmCounts['client'] ?? 0),

            'opportunities' => (int) ($crmCounts['opportunity'] ?? 0),

            'prospected' => (int) ($crmCounts['prospected'] ?? 0),

            'known' => (int) ($crmCounts['known'] ?? 0),

            'new' => (int) ($crmCounts['not_found'] ?? 0),

            'icp_a' => (int) ($icpCounts['A'] ?? 0),

            'icp_b' => (int) ($icpCounts['B'] ?? 0),

            'icp_c' => (int) ($icpCounts['C'] ?? 0),

            'icp_d' => (int) ($icpCounts['D'] ?? 0),
        ];
    }

    #[Computed]
    public function currentBatch(): ?ImportBatch
    {
        if (! $this->batchId) {
            return null;
        }

        return ImportBatch::query()
            ->find($this->batchId);
    }

    #[Computed]
    public function currentItems()
    {
        if (! $this->batchId) {
            return collect();
        }

        return ImportItem::query()
            ->where(
                'import_batch_id',
                $this->batchId
            )
            ->with([
                'company.establishments',
                'company.icpScore',
                'company.crmCheck',
            ])
            ->orderBy('row_number')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function recentBatches()
    {
        return ImportBatch::query()
            ->latest()
            ->limit(10)
            ->get();
    }

    public function statusLabel(
        string $status
    ): string {
        return match ($status) {
            'ready' => 'Pronto',
            'existing' => 'Já cadastrado',
            'duplicate' => 'Duplicado',
            'invalid' => 'Inválido',
            'queued' => 'Na fila',
            'processing' => 'Processando',
            'completed' => 'Concluído',
            'failed' => 'Falhou',
            'pending' => 'Pendente',
            default => ucfirst($status),
        };
    }

    public function crmStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'client' => 'Cliente',
            'opportunity' => 'Oportunidade',
            'prospected' => 'Prospectado',
            'known' => 'Conhecido',
            'not_found' => 'Novo / não encontrado',
            default => 'Não verificado',
        };
    }

    public function crmStatusClasses(
        ?string $status
    ): string {
        return match ($status) {
            'client' => 'bg-emerald-500/15 text-emerald-300',

            'opportunity' => 'bg-amber-500/15 text-amber-300',

            'prospected' => 'bg-sky-500/15 text-sky-300',

            'known' => 'bg-violet-500/15 text-violet-300',

            'not_found' => 'bg-white/5 text-[#a8afc8]',

            default => 'bg-white/5 text-[#7f87a7]',
        };
    }

    public function icpGradeClasses(
        ?string $grade
    ): string {
        return match ($grade) {
            'A' => 'bg-emerald-500/15 text-emerald-300',

            'B' => 'bg-sky-500/15 text-sky-300',

            'C' => 'bg-amber-500/15 text-amber-300',

            default => 'bg-rose-500/15 text-rose-300',
        };
    }

    /**
     * @return list<array{
     *     type: string,
     *     label: string,
     *     detail: string|null
     * }>
     */
    public function itemAlerts(
        ImportItem $item
    ): array {
        $alerts = [];

        /*
         * Falhas técnicas que impediram
         * o processamento.
         */
        if ($item->status === 'invalid') {
            $alerts[] = [
                'type' => 'danger',

                'label' => 'CNPJ inválido',

                'detail' => $item->error_message
                        ? mb_substr(
                            $item->error_message,
                            0,
                            180
                        )
                        : null,
            ];

            return $alerts;
        }

        if ($item->status === 'failed') {
            $alerts[] = [
                'type' => 'danger',

                'label' => 'Falha no enriquecimento',

                'detail' => $item->error_message
                        ? mb_substr(
                            $item->error_message,
                            0,
                            180
                        )
                        : 'O processamento não foi concluído.',
            ];
        }

        /*
         * Quando uma falha temporária fez
         * o job voltar para a fila.
         */
        if (
            $item->status === 'queued'
            && $item->error_message
        ) {
            $alerts[] = [
                'type' => 'warning',

                'label' => 'Nova tentativa pendente',

                'detail' => mb_substr(
                    $item->error_message,
                    0,
                    180
                ),
            ];
        }

        $crm = $item
            ->company
            ?->crmCheck;

        /*
         * O CRM é complementar.
         * Se ele falhar, o enriquecimento
         * fiscal continua válido, mas isso
         * precisa ficar visível.
         */
        $itemCrmChecked = data_get(
            $item->metadata,
            'crm.checked'
        );

        $crmInternalChecked = data_get(
            $crm?->metadata,
            'crm_checked'
        );

        if (
            $itemCrmChecked === false
            || $crmInternalChecked === false
        ) {
            $crmError =
                data_get(
                    $item->metadata,
                    'crm.error'
                )
                ?? data_get(
                    $crm?->metadata,
                    'crm_error'
                );

            $alerts[] = [
                'type' => 'warning',

                'label' => 'CRM não verificado',

                'detail' => is_string($crmError)
                    && $crmError !== ''
                        ? mb_substr(
                            $crmError,
                            0,
                            180
                        )
                        : (
                            'O HubSpot não pôde ser '
                            .'consultado nesta execução.'
                        ),
            ];
        }

        /*
         * Exemplo real: C.Vale.
         *
         * Base oficial ExportControl diz
         * CLIENTE, mas o HubSpot registra
         * outro estágio.
         */
        $crmConflict = (bool) data_get(
            $crm?->metadata,
            'crm_conflict',
            false
        );

        if ($crmConflict) {
            $reportedStatus = data_get(
                $crm?->metadata,
                'crm_reported_status'
            );

            if (
                ! is_string($reportedStatus)
                || $reportedStatus === ''
            ) {
                $reportedStatus =
                    $crm?->lifecycle_stage;
            }

            $prospectorStatus =
                $this->crmStatusLabel(
                    $crm?->status
                );

            $hubspotStatus =
                is_string($reportedStatus)
                && $reportedStatus !== ''
                    ? $this->crmStatusLabel(
                        $reportedStatus
                    )
                    : 'Não identificado';

            $alerts[] = [
                'type' => 'warning',

                'label' => 'Divergência CRM',

                'detail' => 'Prospector: '
                    .$prospectorStatus
                    .' · HubSpot: '
                    .$hubspotStatus,
            ];
        }

        /*
         * Empresa não estava ainda na
         * fotografia mensal da Receita e
         * precisou da consulta pontual.
         *
         * Não é erro, mas é informação de
         * qualidade/origem do dado.
         */
        $usedFallback =
            data_get(
                $item->metadata,
                'fallback_reason'
            )
                ===
                'not_found_in_local_receita'
            || data_get(
                $item->metadata,
                'group_enrichment'
            ) === false;

        if ($usedFallback) {
            $alerts[] = [
                'type' => 'info',

                'label' => 'Receita via fallback',

                'detail' => 'Não constava na base mensal; '
                    .'os dados foram obtidos por '
                    .'consulta pontual na BrasilAPI.',
            ];
        }

        /*
         * Peneira para a próxima etapa:
         * pesquisa pública de exportação.
         *
         * Isso NÃO dispara pesquisa.
         * Apenas mostra se a empresa
         * passou pelos filtros de ICP + CRM.
         */
        $researchEligibility =
            data_get(
                $item->metadata,
                'export_research_eligibility'
            );

        if (
            is_array(
                $researchEligibility
            )
        ) {
            $eligible =
                (bool) (
                    $researchEligibility[
                        'eligible'
                    ]
                    ?? false
                );

            $reason =
                $researchEligibility[
                    'reason'
                ]
                ?? null;

            $message =
                $researchEligibility[
                    'message'
                ]
                ?? null;

            if ($eligible) {
                $alerts[] = [
                    'type' => 'success',

                    'label' => 'Pesquisa de exportação elegível',

                    'detail' => 'Empresa aprovada nos filtros '
                        .'de ICP e CRM.',
                ];
            } else {
                $alerts[] = [
                    'type' => 'neutral',

                    'label' => 'Pesquisa de exportação bloqueada',

                    'detail' => is_string(
                        $message
                    )
                        && $message !== ''
                            ? $message
                            : (
                                is_string(
                                    $reason
                                )
                                && $reason !== ''
                                    ? $reason
                                    : 'Empresa não elegível.'
                            ),
                ];
            }
        }

        return $alerts;
    }

    public function alertClasses(
        string $type
    ): string {
        return match ($type) {
            'success' => 'border-emerald-400/15 '
                .'bg-emerald-400/[0.06] '
                .'text-emerald-300',

            'neutral' => 'border-white/[0.06] '
                .'bg-white/[0.03] '
                .'text-[#9ca5c5]',

            'danger' => 'border-rose-400/15 '
                .'bg-rose-400/[0.06] '
                .'text-rose-300',

            'warning' => 'border-amber-400/15 '
                .'bg-amber-400/[0.06] '
                .'text-amber-300',

            'info' => 'border-sky-400/15 '
                .'bg-sky-400/[0.06] '
                .'text-sky-300',

            default => 'border-white/[0.06] '
                .'bg-white/[0.03] '
                .'text-[#aab2cc]',
        };
    }
};
?>

<div class="ecim-page" x-data="{}">

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

        $enrichmentTotal =
            $ready + $queued + $processing + $completed + $failed;

        $finished = $completed + $failed;

        $progress = $enrichmentTotal > 0
            ? min(100, (int) round($finished / $enrichmentTotal * 100))
            : 0;

        $format = static fn ($value): string =>
            number_format((int) $value, 0, ',', '.');

        $batchLabel = match (true) {
            $busy => 'Enriquecendo',
            $ready > 0 => 'Aguardando enriquecimento',
            $pending > 0 => 'Aguardando validação',
            $failed > 0 => 'Finalizado com falhas',
            $completed > 0 => 'Enriquecimento concluído',
            $batch !== null && (int) $batch->total_rows > 0 => 'Validação concluída',
            default => 'Lote sem itens',
        };

        $batchTone = match (true) {
            $busy => 'info',
            $ready > 0 || $pending > 0 || $failed > 0 => 'warning',
            $completed > 0 => 'success',
            default => 'neutral',
        };
    @endphp

    <header class="ecim-header">
        <div>
            <span class="ecim-eyebrow">ENTRADA DE DADOS</span>
            <h1>Importações</h1>
            <p>Valide os CNPJs, enriqueça os cadastros e acompanhe os resultados.</p>
        </div>

        <div class="ecim-header-actions">
            <a class="ecim-button ecim-secondary" href="#ecim-history">
                Histórico
            </a>

            <button
                type="button"
                class="ecim-button ecim-primary"
                x-on:click="$refs.composer.open = true; $nextTick(() => { $refs.cnpjs.focus(); })"
            >
                <span aria-hidden="true">+</span>
                Nova importação
            </button>
        </div>
    </header>

    @if (session('success'))
        <div class="ecim-notice" data-tone="success" role="status">
            {{ session('success') }}
        </div>
    @endif

    <div class="ecim-layout">

        <div class="ecim-main">

            <details
                class="ecim-panel ecim-composer"
                x-ref="composer"
                wire:ignore.self
                wire:key="ecim-composer-{{ $batchId ?? 'new' }}"
                @if (! $batch) open @endif
            >
                <summary class="ecim-composer-toggle">
                    <span>
                        <strong>Nova importação</strong>
                        <small>Cole uma lista para validar e criar um lote.</small>
                    </span>

                    <span class="ecim-chevron" aria-hidden="true">⌄</span>
                </summary>

                <form wire:submit="process" class="ecim-form" novalidate>
                    <label for="ecim-input">Lista de CNPJs</label>

                    <p class="ecim-help" id="ecim-input-help">
                        Separe por linha, vírgula ou ponto e vírgula.
                        Com ou sem pontuação.
                    </p>

                    <textarea
                        id="ecim-input"
                        x-ref="cnpjs"
                        wire:model="input"
                        rows="6"
                        maxlength="1000000"
                        spellcheck="false"
                        autocapitalize="off"
                        autocomplete="off"
                        aria-describedby="ecim-input-help{{ $errors->has('input') ? ' ecim-input-error' : '' }}"
                        aria-invalid="{{ $errors->has('input') ? 'true' : 'false' }}"
                        wire:loading.attr="disabled"
                        wire:target="process"
                        placeholder="11.222.333/0001-81"
                    ></textarea>

                    @error('input')
                        <p id="ecim-input-error" class="ecim-error" role="alert">
                            {{ $message }}
                        </p>
                    @enderror

                    <div class="ecim-form-actions">
                        <span>
                            Esta etapa valida a lista.
                            O enriquecimento é iniciado separadamente.
                        </span>

                        <button
                            type="submit"
                            class="ecim-button ecim-primary"
                            wire:loading.attr="disabled"
                            wire:target="process,openBatch,queueCurrentBatch,refreshCurrentBatch"
                        >
                            <span wire:loading.remove wire:target="process">
                                Processar CNPJs
                            </span>
                            <span wire:loading wire:target="process">
                                Validando...
                            </span>
                        </button>
                    </div>
                </form>
            </details>

            @if ($batch)

                <section
                    class="ecim-panel"
                    wire:key="ecim-result-{{ $batch->id }}"
                    aria-labelledby="ecim-result-title"
                >
                    <header class="ecim-panel-head">
                        <div>
                            <span class="ecim-eyebrow">LOTE SELECIONADO</span>

                            <h2 id="ecim-result-title">
                                Resultado do lote #{{ $batch->id }}
                            </h2>

                            <p>
                                {{ $batch->created_at?->format('d/m/Y H:i') ?? 'Data não informada' }}
                                ·
                                {{
                                    $batch->source_type === 'prospecting'
                                        ? 'Motor de prospecção'
                                        : (
                                            $batch->source_type === 'manual'
                                                ? 'Importação manual'
                                                : $batch->source_type
                                        )
                                }}
                            </p>
                        </div>

                        <span class="ecim-badge" data-tone="{{ $batchTone }}">
                            {{ $batchLabel }}
                        </span>
                    </header>

                    <dl class="ecim-totals">
                        @foreach ([
                            ['Total', $batch->total_rows, 'neutral'],
                            ['Válidos', $batch->valid_rows, 'success'],
                            ['Já cadastrados', $batch->existing_rows, 'info'],
                            ['Duplicados', $batch->duplicate_rows, 'warning'],
                            ['Inválidos', $batch->invalid_rows, 'danger'],
                        ] as [$label, $value, $tone])
                            <div data-tone="{{ $tone }}">
                                <dt>{{ $label }}</dt>
                                <dd>{{ $format($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div
                        class="ecim-processing"
                        @if ($busy) wire:poll.2s="refreshCurrentBatch" @endif
                    >
                        <div class="ecim-processing-head">
                            <div>
                                <h3>Enriquecimento cadastral</h3>
                                <p>
                                    Consulta dos dados empresariais,
                                    perfil ICP e verificação no CRM.
                                </p>
                            </div>

                            <div class="ecim-inline-actions">
                                <button
                                    type="button"
                                    class="ecim-button ecim-secondary"
                                    wire:click="refreshCurrentBatch"
                                    wire:loading.attr="disabled"
                                    wire:target="process,openBatch,queueCurrentBatch,refreshCurrentBatch"
                                >
                                    Atualizar
                                </button>

                                @if ($ready > 0)
                                    <button
                                        type="button"
                                        class="ecim-button ecim-primary"
                                        wire:click="queueCurrentBatch"
                                        wire:loading.attr="disabled"
                                        wire:target="process,openBatch,queueCurrentBatch,refreshCurrentBatch"
                                    >
                                        <span
                                            wire:loading.remove
                                            wire:target="queueCurrentBatch"
                                        >
                                            Iniciar enriquecimento ({{ $format($ready) }})
                                        </span>
                                        <span
                                            wire:loading
                                            wire:target="queueCurrentBatch"
                                        >
                                            Enfileirando...
                                        </span>
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($enrichmentTotal > 0)
                            <div class="ecim-progress-text">
                                <span>
                                    {{ $format($finished) }}
                                    de
                                    {{ $format($enrichmentTotal) }}
                                    finalizados
                                </span>
                                <strong>{{ $progress }}%</strong>
                            </div>

                            <div
                                class="ecim-progress"
                                role="progressbar"
                                aria-label="Progresso do enriquecimento cadastral"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ $progress }}"
                            >
                                <span style="width: {{ $progress }}%"></span>
                            </div>

                            <dl class="ecim-processing-counts">
                                <div>
                                    <dt>Aguardando início</dt>
                                    <dd>{{ $format($ready) }}</dd>
                                </div>
                                <div>
                                    <dt>Na fila</dt>
                                    <dd>{{ $format($queued) }}</dd>
                                </div>
                                <div>
                                    <dt>Processando</dt>
                                    <dd>{{ $format($processing) }}</dd>
                                </div>
                                <div>
                                    <dt>Concluídos</dt>
                                    <dd>{{ $format($completed) }}</dd>
                                </div>
                                <div>
                                    <dt>Falhas</dt>
                                    <dd class="{{ $failed > 0 ? 'ecim-error' : '' }}">
                                        {{ $format($failed) }}
                                    </dd>
                                </div>
                            </dl>

                            @if ($failed > 0)
                                <p class="ecim-warning">
                                    O progresso inclui {{ $format($failed) }}
                                    registro(s) finalizado(s) com falha.
                                    Confira os alertas abaixo.
                                </p>
                            @endif
                        @else
                            <p class="ecim-help">
                                Nenhum registro disponível para enriquecimento neste lote.
                            </p>
                        @endif

                        @if ($pending > 0)
                            <p class="ecim-warning">
                                {{ $format($pending) }}
                                registro(s) ainda com status pendente.
                            </p>
                        @endif
                    </div>
                </section>

                @php
                    $intelligence = $this->currentIntelligenceCounts;
                @endphp

                <section
                    class="ecim-panel"
                    aria-labelledby="ecim-intelligence-title"
                >
                    <header class="ecim-panel-head">
                        <div>
                            <h2 id="ecim-intelligence-title">
                                Inteligência comercial do lote
                            </h2>
                            <p>
                                {{
                                    $busy || $ready > 0 || $pending > 0
                                        ? 'Resultados parciais, conforme o processamento avança.'
                                        : 'Classificações disponíveis nos registros vinculados às empresas.'
                                }}
                            </p>
                        </div>
                    </header>

                    <div class="ecim-intelligence-grid">
                        <section aria-label="Situação no CRM">
                            <h3>Situação no CRM</h3>

                            <dl class="ecim-breakdown">
                                @foreach ([
                                    'clients' => 'Clientes',
                                    'opportunities' => 'Oportunidades',
                                    'prospected' => 'Prospectados',
                                    'known' => 'Conhecidos',
                                    'new' => 'Novos',
                                ] as $key => $label)
                                    <div>
                                        <dt>{{ $label }}</dt>
                                        <dd>{{ $format($intelligence[$key] ?? 0) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section aria-label="Perfil ICP">
                            <h3>Perfil ICP</h3>

                            <dl class="ecim-breakdown">
                                @foreach ([
                                    'icp_a' => 'ICP A',
                                    'icp_b' => 'ICP B',
                                    'icp_c' => 'ICP C',
                                    'icp_d' => 'ICP D',
                                ] as $key => $label)
                                    <div>
                                        <dt>{{ $label }}</dt>
                                        <dd>{{ $format($intelligence[$key] ?? 0) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    </div>

                    <p class="ecim-footnote">
                        Contagem por registro do lote.
                        Elegibilidade para pesquisa não significa exportação
                        comprovada nem pesquisa concluída.
                    </p>
                </section>

                <section
                    class="ecim-panel"
                    aria-labelledby="ecim-items-title"
                    wire:key="ecim-items-{{ $batch->id }}"
                >
                    <header class="ecim-panel-head">
                        <div>
                            <h2 id="ecim-items-title">CNPJs do lote</h2>
                            <p>
                                Exibindo {{ $format($items->count()) }}
                                de {{ $format($batch->total_rows) }} registros.
                                Limite atual: 100 registros.
                            </p>
                        </div>

                        <span
                            class="ecim-live-status"
                            wire:loading.delay
                            wire:target="openBatch,queueCurrentBatch"
                        >
                            Atualizando...
                        </span>
                    </header>

                    @if ((int) $batch->total_rows > 100)
                        <p class="ecim-limit-note">
                            A lista exibe os primeiros 100 registros.
                            Os indicadores acima consideram o lote inteiro.
                        </p>
                    @endif

                    <div class="ecim-table-scroll">
                        <table class="ecim-table">
                            <caption class="ecim-sr">
                                Empresas, classificação comercial e alertas do lote selecionado
                            </caption>

                            <thead>
                                <tr>
                                    <th scope="col">Empresa / CNPJ</th>
                                    <th scope="col">Unidades</th>
                                    <th scope="col">ICP</th>
                                    <th scope="col">CRM</th>
                                    <th scope="col">Processamento</th>
                                    <th scope="col">Alertas</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse ($items as $item)
                                    @php
                                        $company = $item->company;
                                        $icp = $company?->icpScore;
                                        $crm = $company?->crmCheck;
                                        $alerts = $this->itemAlerts($item);
                                        $cnpj = $item->normalized_cnpj;

                                        $displayCnpj =
                                            $cnpj && \App\Support\Cnpj::isWellFormed($cnpj)
                                                ? \App\Support\Cnpj::format($cnpj)
                                                : (string) $item->raw_cnpj;

                                        $itemTone = match ((string) $item->status) {
                                            'completed' => 'success',
                                            'processing', 'queued' => 'info',
                                            'failed', 'invalid' => 'danger',
                                            'ready', 'pending', 'duplicate' => 'warning',
                                            default => 'neutral',
                                        };

                                        $crmTone = match ($crm?->status) {
                                            'client' => 'success',
                                            'opportunity' => 'warning',
                                            'prospected' => 'info',
                                            default => 'neutral',
                                        };

                                        $icpTone = match ($icp?->grade) {
                                            'A' => 'success',
                                            'B' => 'info',
                                            'C' => 'warning',
                                            'D' => 'danger',
                                            default => 'neutral',
                                        };
                                    @endphp

                                    <tr wire:key="ecim-item-{{ $item->id }}">
                                        <td class="ecim-company-cell">
                                            <span class="ecim-row-number">
                                                Linha {{ $item->row_number }}
                                            </span>

                                            @if ($company)
                                                <a
                                                    class="ecim-company-name"
                                                    href="{{ route('companies.show', $company) }}"
                                                    wire:navigate
                                                >
                                                    {{ $company->corporate_name }}
                                                </a>

                                                <span class="ecim-cnpj">
                                                    {{ $displayCnpj }}
                                                </span>
                                            @else
                                                <strong class="ecim-cnpj">
                                                    {{ $displayCnpj }}
                                                </strong>

                                                <span class="ecim-muted">
                                                    Sem cadastro vinculado
                                                </span>
                                            @endif
                                        </td>

                                        <td data-label="Unidades">
                                            <strong>
                                                {{
                                                    $company
                                                        ? $format($company->establishments->count())
                                                        : '—'
                                                }}
                                            </strong>
                                        </td>

                                        <td data-label="ICP">
                                            @if ($icp)
                                                <span
                                                    class="ecim-badge"
                                                    data-tone="{{ $icpTone }}"
                                                >
                                                    {{ $icp->grade }}
                                                    · {{ $icp->score }}/100
                                                </span>
                                            @else
                                                <span class="ecim-muted">
                                                    Não calculado
                                                </span>
                                            @endif
                                        </td>

                                        <td data-label="CRM">
                                            <span
                                                class="ecim-badge"
                                                data-tone="{{ $crmTone }}"
                                            >
                                                {{ $this->crmStatusLabel($crm?->status) }}
                                            </span>

                                            @if ((bool) data_get($crm?->metadata, 'crm_conflict', false))
                                                <span class="ecim-warning">
                                                    Divergência CRM
                                                </span>
                                            @endif
                                        </td>

                                        <td data-label="Processamento">
                                            <span
                                                class="ecim-badge"
                                                data-tone="{{ $itemTone }}"
                                            >
                                                {{ $this->statusLabel((string) $item->status) }}
                                            </span>

                                            @if ($company)
                                                <a
                                                    class="ecim-dossier"
                                                    href="{{ route('companies.show', $company) }}"
                                                    wire:navigate
                                                    aria-label="Abrir dossiê de {{ $company->corporate_name }}"
                                                >
                                                    Abrir dossiê ↗
                                                </a>
                                            @endif
                                        </td>

                                        <td class="ecim-alerts-cell" data-label="Alertas">
                                            @forelse ($alerts as $alert)
                                                <details
                                                    wire:ignore.self
                                                    class="ecim-item-alert"
                                                    data-tone="{{ $alert['type'] }}"
                                                    wire:key="ecim-alert-{{ $item->id }}-{{ md5($alert['type'] . '|' . $alert['label']) }}"
                                                    @if (in_array($alert['type'], ['danger', 'warning'], true)) open @endif
                                                >
                                                    <summary>{{ $alert['label'] }}</summary>
                                                    <p>
                                                        {{ $alert['detail'] ?: 'Sem detalhes adicionais.' }}
                                                    </p>
                                                </details>
                                            @empty
                                                <span class="ecim-muted">
                                                    Sem alertas
                                                </span>
                                            @endforelse
                                        </td>
                                    </tr>

                                @empty
                                    <tr>
                                        <td colspan="6" class="ecim-empty">
                                            <strong>Nenhum registro neste lote.</strong>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <footer class="ecim-table-footer">
                        Os nomes das empresas abrem seus dossiês.
                        Clique em um alerta para ver os detalhes.
                    </footer>
                </section>

            @else

                <section class="ecim-panel ecim-welcome">
                    <span class="ecim-welcome-icon" aria-hidden="true">↓</span>

                    <h2>
                        {{ $batchId ? 'Lote não encontrado' : 'Tudo começa com uma lista de CNPJs' }}
                    </h2>

                    <p>
                        {{
                            $batchId
                                ? 'Selecione outro lote no histórico ou crie uma nova importação.'
                                : 'Cole a lista acima para validar os registros. Depois, inicie o enriquecimento dos CNPJs disponíveis.'
                        }}
                    </p>

                    <ol class="ecim-steps">
                        <li>
                            <span>01</span>
                            <strong>Validar lista</strong>
                            <small>
                                Identificar registros válidos, duplicados e inválidos.
                            </small>
                        </li>
                        <li>
                            <span>02</span>
                            <strong>Enriquecer dados</strong>
                            <small>
                                Iniciar o processamento dos registros prontos.
                            </small>
                        </li>
                        <li>
                            <span>03</span>
                            <strong>Conferir resultados</strong>
                            <small>
                                Abrir os dossiês e revisar os alertas.
                            </small>
                        </li>
                    </ol>
                </section>

            @endif
        </div>

        <aside
            class="ecim-history ecim-panel"
            id="ecim-history"
            aria-labelledby="ecim-history-title"
        >
            <header class="ecim-panel-head">
                <div>
                    <h2 id="ecim-history-title">Importações recentes</h2>
                    <p>Últimos {{ $recentBatches->count() }} lotes da base.</p>
                </div>
            </header>

            <div class="ecim-history-list">
                @forelse ($recentBatches as $recent)
                    @php
                        $recentTone = match ((string) $recent->status) {
                            'completed' => 'success',
                            'processing', 'queued' => 'info',
                            'failed' => 'danger',
                            default => 'neutral',
                        };
                    @endphp

                    <button
                        type="button"
                        wire:click="openBatch({{ $recent->id }})"
                        wire:loading.attr="disabled"
                        wire:target="process,openBatch,queueCurrentBatch,refreshCurrentBatch"
                        wire:key="ecim-history-{{ $recent->id }}"
                        class="ecim-history-item"
                        aria-current="{{ (int) $batchId === (int) $recent->id ? 'true' : 'false' }}"
                    >
                        <span class="ecim-history-top">
                            <strong>Lote #{{ $recent->id }}</strong>
                            <span>{{ $format($recent->total_rows) }} CNPJs</span>
                        </span>

                        <time datetime="{{ $recent->created_at?->toIso8601String() }}">
                            {{ $recent->created_at?->format('d/m/Y H:i') ?? 'Data não informada' }}
                        </time>

                        <span class="ecim-history-bottom">
                            <span class="ecim-badge" data-tone="{{ $recentTone }}">
                                {{ $this->statusLabel((string) $recent->status) }}
                            </span>

                            <span>
                                {{
                                    $recent->source_type === 'prospecting'
                                        ? 'Prospecção'
                                        : (
                                            $recent->source_type === 'manual'
                                                ? 'Manual'
                                                : $recent->source_type
                                        )
                                }}
                            </span>
                        </span>
                    </button>

                @empty
                    <p class="ecim-history-empty">
                        Nenhuma importação realizada.
                    </p>
                @endforelse
            </div>
        </aside>

    </div>
</div>
