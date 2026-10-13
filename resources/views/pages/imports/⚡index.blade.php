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

    public string $search = '';

    public string $statusFilter = 'all';

    public int $historyLimit = 3;

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

        $this->search = '';
        $this->statusFilter = 'all';

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

        $this->search = '';
        $this->statusFilter = 'all';

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

    public function showMoreHistory(): void
    {
        $this->historyLimit = min(100, max(3, $this->historyLimit) + 10);
        unset($this->recentBatches);
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 120);
        unset($this->currentItems);
    }

    public function updatedStatusFilter(): void
    {
        unset($this->currentItems);
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

        $allowedStatuses = [
            'ready', 'queued', 'processing', 'completed',
            'existing', 'duplicate', 'invalid', 'failed', 'pending',
        ];

        return ImportItem::query()
            ->where('import_batch_id', $this->batchId)
            ->when(
                in_array($this->statusFilter, $allowedStatuses, true),
                fn ($query) => $query->where('status', $this->statusFilter)
            )
            ->when(trim($this->search) !== '', function ($query): void {
                $text = mb_strtolower(trim($this->search));
                $like = '%'.$text.'%';
                $normalized = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($this->search));

                $query->where(function ($inner) use ($like, $normalized): void {
                    $inner
                        ->whereRaw('LOWER(raw_cnpj) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(normalized_cnpj) LIKE ?', [$like])
                        ->orWhereHas('company', function ($companyQuery) use ($like): void {
                            $companyQuery->whereRaw('LOWER(corporate_name) LIKE ?', [$like]);
                        });

                    if (is_string($normalized) && $normalized !== '') {
                        $inner->orWhere('normalized_cnpj', 'like', '%'.$normalized.'%');
                    }
                });
            })
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
            ->limit(max(3, min(100, $this->historyLimit)))
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

            default => 'bg-white/5 text-[var(--ec-text-muted)]',
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
                .'text-[var(--ec-text-soft)]',

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
                .'text-[var(--ec-text-soft)]',
        };
    }
};
?>

<div class="ecimv2 ecim-page" x-data="{}">
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
        $busy = ($queued + $processing) > 0;
        $enrichmentTotal = $ready + $queued + $processing + $completed + $failed;
        $finished = $completed + $failed;
        $progress = $enrichmentTotal > 0
            ? min(100, (int) round($finished * 100 / $enrichmentTotal))
            : 0;

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
        $batchTone = match (true) {
            $busy => 'info',
            $ready > 0 || $pending > 0 => 'warning',
            $failed > 0 => 'danger',
            $completed > 0 => 'success',
            default => 'neutral',
        };
        $hasMoreHistory = $this->historyLimit < 100 && \App\Models\ImportBatch::query()->count() > $recentBatches->count();
    @endphp

    <header class="ecimv2-page-head">
        <div class="ecimv2-head-copy">
            <span class="ecimv2-kicker">ENTRADA DE DADOS</span>
            <h1>Importações</h1>
            <p>Valide os CNPJs, enriqueça os cadastros e acompanhe os resultados.</p>
        </div>
        <div class="ecimv2-head-actions">
            <a class="ecimv2-btn ecimv2-btn-outline" href="#ecimv2-history">
                <x-import-icon name="clock" /> Histórico
            </a>
            <button
                type="button"
                class="ecimv2-btn ecimv2-btn-primary"
                x-on:click="$refs.composer.open = true; $nextTick(() => { $refs.cnpjInput.focus(); $refs.composer.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); })"
            >
                <x-import-icon name="plus" /> Nova importação
            </button>
        </div>
    </header>

    @if (session('success'))
        <div class="ecimv2-notice" data-tone="success" role="status">
            <x-import-icon name="check-circle" /> {{ session('success') }}
        </div>
    @endif

    <div class="ecimv2-columns">
        <div class="ecimv2-main">
            <details class="ecimv2-card ecimv2-composer" x-ref="composer" wire:ignore.self open>
                <summary class="ecimv2-composer-summary">
                    <span class="ecimv2-heading-icon" data-tone="teal">
                        <x-import-icon name="file-plus" />
                    </span>
                    <span class="ecimv2-composer-heading">
                        <strong>Nova importação</strong>
                        <small>Cole uma lista para validar e criar um lote.</small>
                    </span>
                    <span class="ecimv2-summary-chevron"><x-import-icon name="chevron-down" /></span>
                </summary>

                <form wire:submit="process" class="ecimv2-entry-form" novalidate>
                    <label class="ecimv2-sr-only" for="ecimv2-cnpjs">Lista de CNPJs</label>
                    <div class="ecimv2-entry-zone" data-invalid="{{ $errors->has('input') ? 'true' : 'false' }}">
                        <div class="ecimv2-upload-icon"><x-import-icon name="cloud-upload" /></div>
                        <div class="ecimv2-input-column">
                            <textarea
                                id="ecimv2-cnpjs"
                                x-ref="cnpjInput"
                                wire:model="input"
                                rows="2"
                                maxlength="1000000"
                                spellcheck="false"
                                autocomplete="off"
                                autocapitalize="off"
                                aria-describedby="ecimv2-input-help{{ $errors->has('input') ? ' ecimv2-error' : '' }}"
                                aria-invalid="{{ $errors->has('input') ? 'true' : 'false' }}"
                                wire:loading.attr="disabled"
                                wire:target="process"
                                placeholder="Cole sua lista de CNPJs aqui"
                            ></textarea>
                            <p id="ecimv2-input-help">Separe por linha, vírgula ou ponto e vírgula. Com ou sem pontuação.</p>
                        </div>
                        <button
                            type="submit"
                            class="ecimv2-btn ecimv2-btn-primary ecimv2-submit"
                            wire:loading.attr="disabled"
                            wire:target="process,openBatch,queueCurrentBatch,refreshCurrentBatch"
                        >
                            <span wire:loading.remove wire:target="process">Processar CNPJs <x-import-icon name="arrow-right" /></span>
                            <span wire:loading wire:target="process">Validando...</span>
                        </button>
                    </div>
                    @error('input')
                        <p id="ecimv2-error" class="ecimv2-input-error" role="alert">{{ $message }}</p>
                    @enderror
                    <p class="ecimv2-entry-help">Primeiro os CNPJs são validados. Depois você pode iniciar o enriquecimento dos registros prontos.</p>
                </form>
            </details>

            @if ($batch)
                <section class="ecimv2-card ecimv2-batch" wire:key="ecimv2-batch-{{ $batch->id }}" aria-labelledby="ecimv2-batch-title">
                    <header class="ecimv2-batch-heading">
                        <span class="ecimv2-heading-icon" data-tone="sky"><x-import-icon name="file-text" /></span>
                        <div class="ecimv2-batch-title-group">
                            <div class="ecimv2-title-line">
                                <h2 id="ecimv2-batch-title">Resultado do lote #{{ $batch->id }}</h2>
                                <time datetime="{{ $batch->created_at?->toIso8601String() }}">{{ $batch->created_at?->format('d/m/Y H:i') ?? 'Sem data' }}</time>
                                <span class="ecimv2-source-tag">{{ $batch->source_type === 'prospecting' ? 'Prospecção' : ($batch->source_type === 'manual' ? 'Manual' : $batch->source_type) }}</span>
                            </div>
                            <span class="ecimv2-kicker ecimv2-result-kicker">LOTE SELECIONADO</span>
                        </div>
                        <span class="ecimv2-status" data-tone="{{ $batchTone }}">
                            @if ($batchTone === 'success') <x-import-icon name="check-circle" />
                            @elseif ($batchTone === 'danger') <x-import-icon name="alert-triangle" />
                            @else <x-import-icon name="clock" /> @endif
                            {{ $batchLabel }}
                        </span>
                    </header>

                    <dl class="ecimv2-stat-grid">
                        @foreach ([
                            ['Total', $batch->total_rows, 'sky', 'file-text'],
                            ['Válidos', $batch->valid_rows, 'teal', 'check-circle'],
                            ['Já cadastrados', $batch->existing_rows, 'blue', 'user-round'],
                            ['Duplicados', $batch->duplicate_rows, 'pink', 'copy'],
                            ['Inválidos', $batch->invalid_rows, 'rose', 'alert-triangle'],
                        ] as [$label, $value, $tone, $icon])
                            <div class="ecimv2-stat" data-tone="{{ $tone }}">
                                <span class="ecimv2-stat-icon"><x-import-icon :name="$icon" /></span>
                                <div>
                                    <dt>{{ $label }}</dt>
                                    <dd>{{ $format($value) }}</dd>
                                </div>
                            </div>
                        @endforeach
                    </dl>

                    <div class="ecimv2-processing" @if ($busy) wire:poll.2s="refreshCurrentBatch" @endif>
                        <div class="ecimv2-processing-head">
                            <div>
                                <h3>Enriquecimento cadastral</h3>
                                <p>Consulta dos dados empresariais, perfil ICP e verificação no CRM.</p>
                            </div>
                            <div class="ecimv2-action-row">
                                <button
                                    type="button"
                                    class="ecimv2-btn ecimv2-btn-outline ecimv2-btn-small"
                                    wire:click="refreshCurrentBatch"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshCurrentBatch,queueCurrentBatch,process,openBatch"
                                >
                                    <x-import-icon name="refresh-cw" /> Atualizar
                                </button>
                                @if ($ready > 0)
                                    <button
                                        type="button"
                                        class="ecimv2-btn ecimv2-btn-primary ecimv2-btn-small"
                                        wire:click="queueCurrentBatch"
                                        wire:loading.attr="disabled"
                                        wire:target="refreshCurrentBatch,queueCurrentBatch,process,openBatch"
                                    >
                                        <span wire:loading.remove wire:target="queueCurrentBatch">Iniciar enriquecimento ({{ $format($ready) }})</span>
                                        <span wire:loading wire:target="queueCurrentBatch">Enfileirando...</span>
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($enrichmentTotal > 0)
                            <div class="ecimv2-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}" aria-label="Progresso do enriquecimento">
                                <span style="width: {{ $progress }}%"></span>
                            </div>
                            <div class="ecimv2-progress-label"><span>{{ $format($finished) }} de {{ $format($enrichmentTotal) }} finalizados</span><strong>{{ $progress }}%</strong></div>
                            <dl class="ecimv2-stage-grid">
                                @foreach ([
                                    ['Aguardando início', $ready, 'clock', 'neutral'],
                                    ['Na fila', $queued, 'hourglass', 'neutral'],
                                    ['Processando', $processing, 'refresh-cw', 'info'],
                                    ['Concluídos', $completed, 'check-circle', 'success'],
                                    ['Falhas', $failed, 'alert-triangle', 'danger'],
                                ] as [$label, $value, $icon, $tone])
                                    <div class="ecimv2-stage" data-tone="{{ $tone }}">
                                        <span class="ecimv2-stage-icon"><x-import-icon :name="$icon" /></span>
                                        <div><dt>{{ $label }}</dt><dd>{{ $format($value) }}</dd></div>
                                    </div>
                                @endforeach
                            </dl>
                        @else
                            <p class="ecimv2-empty-small">Nenhum registro disponível para enriquecimento neste lote.</p>
                        @endif
                        @if ($failed > 0)
                            <p class="ecimv2-inline-warning"><x-import-icon name="alert-triangle" /> {{ $format($failed) }} registro(s) finalizaram com falha. Consulte os alertas na tabela.</p>
                        @endif
                        @if ($pending > 0)
                            <p class="ecimv2-inline-warning">{{ $format($pending) }} registro(s) ainda aguardam validação.</p>
                        @endif
                    </div>
                </section>

                <section class="ecimv2-card ecimv2-list-card" aria-labelledby="ecimv2-items-title" wire:key="ecimv2-items-{{ $batch->id }}">
                    <header class="ecimv2-list-head">
                        <span class="ecimv2-heading-icon" data-tone="teal"><x-import-icon name="list-checks" /></span>
                        <div class="ecimv2-list-copy">
                            <h2 id="ecimv2-items-title">CNPJs do lote</h2>
                            <p>Exibindo {{ $format($items->count()) }} registro(s). A busca consulta o lote inteiro; são exibidos até 100 resultados.</p>
                        </div>
                        <div class="ecimv2-filters">
                            <label class="ecimv2-search-wrap">
                                <x-import-icon name="search" />
                                <span class="ecimv2-sr-only">Buscar no lote</span>
                                <input type="search" wire:model.live.debounce.350ms="search" placeholder="Buscar no lote..." autocomplete="off" />
                            </label>
                            <label class="ecimv2-select-wrap">
                                <span class="ecimv2-sr-only">Filtrar por status de processamento</span>
                                <select wire:model.live="statusFilter">
                                    <option value="all">Todos os status</option>
                                    <option value="ready">Pronto</option>
                                    <option value="queued">Na fila</option>
                                    <option value="processing">Processando</option>
                                    <option value="completed">Concluído</option>
                                    <option value="existing">Já cadastrado</option>
                                    <option value="duplicate">Duplicado</option>
                                    <option value="invalid">Inválido</option>
                                    <option value="failed">Falhou</option>
                                    <option value="pending">Pendente</option>
                                </select>
                                <x-import-icon name="chevron-down" />
                            </label>
                        </div>
                    </header>

                    @if ((int) $batch->total_rows > 100)
                        <p class="ecimv2-limit-info">O lote tem {{ $format($batch->total_rows) }} registros. Os indicadores consideram todos; a tabela mostra até 100 por pesquisa.</p>
                    @endif
                    <div class="ecimv2-table-scroll">
                        <table class="ecimv2-table">
                            <caption class="ecimv2-sr-only">Empresas do lote, indicadores, estado de processamento e alertas</caption>
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Empresa</th>
                                    <th scope="col">CNPJ</th>
                                    <th scope="col">Status ICP</th>
                                    <th scope="col">CRM</th>
                                    <th scope="col">Processamento</th>
                                    <th scope="col">Ações e alertas</th>
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
                                        $displayCnpj = $cnpj && \App\Support\Cnpj::isWellFormed($cnpj)
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
                                    <tr wire:key="ecimv2-item-{{ $item->id }}">
                                        <td data-label="Linha" class="ecimv2-index">{{ $item->row_number }}</td>
                                        <td data-label="Empresa" class="ecimv2-company-name" title="{{ $company?->corporate_name ?: 'Sem empresa vinculada' }}">
                                            @if ($company)
                                                <a href="{{ route('companies.show', $company) }}" wire:navigate>{{ $company->corporate_name }}</a>
                                            @else
                                                <span>Sem cadastro vinculado</span>
                                            @endif
                                        </td>
                                        <td data-label="CNPJ" class="ecimv2-cnpj">{{ $displayCnpj }}</td>
                                        <td data-label="Status ICP">
                                            @if ($icp)
                                                <span class="ecimv2-pill" data-tone="{{ $icpTone }}"><strong>{{ $icp->grade }}</strong>&nbsp;{{ $icp->score }}/100</span>
                                            @else
                                                <span class="ecimv2-muted">Não calculado</span>
                                            @endif
                                        </td>
                                        <td data-label="CRM">
                                            <span class="ecimv2-pill" data-tone="{{ $crmTone }}">{{ $this->crmStatusLabel($crm?->status) }}</span>
                                        </td>
                                        <td data-label="Processamento"><span class="ecimv2-pill" data-tone="{{ $itemTone }}">{{ $this->statusLabel((string) $item->status) }}</span></td>
                                        <td data-label="Ações" class="ecimv2-actions-cell">
                                            @if ($company)
                                                <a class="ecimv2-dossier-link" href="{{ route('companies.show', $company) }}" wire:navigate title="Abrir dossiê e inteligência comercial da empresa">
                                                    <x-import-icon name="search" /> Abrir dossiê <x-import-icon name="arrow-right" />
                                                </a>
                                            @else
                                                <span class="ecimv2-muted">Aguardando cadastro</span>
                                            @endif
                                            @if (count($alerts) > 0)
                                                <details class="ecimv2-alerts" wire:ignore.self>
                                                    <summary>Alertas ({{ count($alerts) }}) <x-import-icon name="chevron-down" /></summary>
                                                    <div class="ecimv2-alert-list">
                                                        @foreach ($alerts as $alert)
                                                            <section class="ecimv2-alert" data-tone="{{ $alert['type'] }}">
                                                                <strong>{{ $alert['label'] }}</strong>
                                                                <p>{{ $alert['detail'] ?: 'Sem detalhes adicionais.' }}</p>
                                                            </section>
                                                        @endforeach
                                                    </div>
                                                </details>
                                            @else
                                                <span class="ecimv2-muted ecimv2-no-alerts">Sem alertas</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="ecimv2-empty-table">Nenhum CNPJ encontrado para os filtros selecionados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <footer class="ecimv2-table-footer">Acesse o dossiê para consultar os dados e as evidências disponíveis. Os alertas explicam falhas, bloqueios ou informações pendentes.</footer>
                </section>

                @php $intelligence = $this->currentIntelligenceCounts; @endphp
                <details class="ecimv2-card ecimv2-intelligence">
                    <summary>
                        <span class="ecimv2-heading-icon" data-tone="sky"><x-import-icon name="bar-chart" /></span>
                        <span><strong>Inteligência comercial do lote</strong><small>Detalhamento das classificações do CRM e do perfil ICP</small></span>
                        <x-import-icon name="chevron-down" />
                    </summary>
                    <div class="ecimv2-intelligence-columns">
                        <section>
                            <h3>Situação no CRM</h3>
                            <dl>
                                @foreach (['clients' => 'Clientes', 'opportunities' => 'Oportunidades', 'prospected' => 'Prospectados', 'known' => 'Conhecidos', 'new' => 'Novos'] as $key => $label)
                                    <div><dt>{{ $label }}</dt><dd>{{ $format($intelligence[$key] ?? 0) }}</dd></div>
                                @endforeach
                            </dl>
                        </section>
                        <section>
                            <h3>Perfil ICP</h3>
                            <dl>
                                @foreach (['icp_a' => 'ICP A', 'icp_b' => 'ICP B', 'icp_c' => 'ICP C', 'icp_d' => 'ICP D'] as $key => $label)
                                    <div><dt>{{ $label }}</dt><dd>{{ $format($intelligence[$key] ?? 0) }}</dd></div>
                                @endforeach
                            </dl>
                        </section>
                    </div>
                    <p class="ecimv2-note">São contagens por registro do lote. Elegibilidade para pesquisa de exportação não significa exportação comprovada.</p>
                </details>
            @else
                <section class="ecimv2-card ecimv2-empty-state">
                    <span class="ecimv2-heading-icon" data-tone="teal"><x-import-icon name="list-checks" /></span>
                    <h2>{{ $batchId ? 'Lote não encontrado' : 'Tudo começa com uma lista de CNPJs' }}</h2>
                    <p>{{ $batchId ? 'Escolha um lote no histórico ou crie uma nova importação.' : 'Cole seus CNPJs acima e valide a lista. Em seguida, inicie o enriquecimento dos registros disponíveis.' }}</p>
                    <div class="ecimv2-start-steps">
                        <div><strong>01 · Validar</strong><span>CNPJs válidos, duplicados e inválidos</span></div>
                        <div><strong>02 · Enriquecer</strong><span>Consultar dados e identificar empresas</span></div>
                        <div><strong>03 · Analisar</strong><span>ICP, CRM, alertas e dossiês</span></div>
                    </div>
                </section>
            @endif
        </div>

        <aside class="ecimv2-side">
            <section class="ecimv2-card ecimv2-history" id="ecimv2-history" aria-labelledby="ecimv2-history-title">
                <header>
                    <h2 id="ecimv2-history-title">Importações recentes</h2>
                    <p>Últimos lotes da base.</p>
                </header>
                <div class="ecimv2-history-items">
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
                            class="ecimv2-history-row"
                            type="button"
                            wire:click="openBatch({{ $recent->id }})"
                            wire:loading.attr="disabled"
                            wire:target="process,openBatch,queueCurrentBatch,refreshCurrentBatch"
                            wire:key="ecimv2-history-{{ $recent->id }}"
                            aria-current="{{ (int) $batchId === (int) $recent->id ? 'true' : 'false' }}"
                        >
                            <span class="ecimv2-history-top"><strong>Lote #{{ $recent->id }}</strong><span>{{ $format($recent->total_rows) }} CNPJs</span></span>
                            <time datetime="{{ $recent->created_at?->toIso8601String() }}">{{ $recent->created_at?->format('d/m/Y H:i') ?? 'Sem data' }}</time>
                            <span class="ecimv2-history-bottom"><span class="ecimv2-pill" data-tone="{{ $recentTone }}">{{ $this->statusLabel((string) $recent->status) }}</span><span>{{ $recent->source_type === 'prospecting' ? 'Prospecção' : ($recent->source_type === 'manual' ? 'Manual' : $recent->source_type) }}</span></span>
                            <x-import-icon name="chevron-right" />
                        </button>
                    @empty
                        <p class="ecimv2-history-empty">Ainda não há importações registradas.</p>
                    @endforelse
                </div>
                @if ($hasMoreHistory)
                    <button type="button" class="ecimv2-more-history" wire:click="showMoreHistory" wire:loading.attr="disabled" wire:target="showMoreHistory">Ver mais lotes <x-import-icon name="arrow-right" /></button>
                @endif
            </section>

            <section class="ecimv2-card ecimv2-help-card" aria-labelledby="ecimv2-tips-title">
                <h2 id="ecimv2-tips-title"><span class="ecimv2-bulb"><x-import-icon name="lightbulb" /></span> Dicas de importação</h2>
                <ul>
                    <li><x-import-icon name="check-circle" /><span>Aceita CNPJs com ou sem pontuação.</span></li>
                    <li><x-import-icon name="check-circle" /><span>Separe por linha, vírgula ou ponto e vírgula.</span></li>
                    <li><x-import-icon name="check-circle" /><span>O enriquecimento é iniciado depois da validação.</span></li>
                    <li><x-import-icon name="check-circle" /><span>Acompanhe os status enquanto a fila processa.</span></li>
                    <li><x-import-icon name="check-circle" /><span>Após a conclusão, acesse o dossiê da empresa.</span></li>
                </ul>
            </section>

            <section class="ecimv2-card ecimv2-help-card ecimv2-limit-card" aria-labelledby="ecimv2-limits-title">
                <h2 id="ecimv2-limits-title"><span class="ecimv2-info"><x-import-icon name="info" /></span> Limites e informações</h2>
                <ul>
                    <li><x-import-icon name="plus" /><span>A tabela exibe até 100 registros por busca.</span></li>
                    <li><x-import-icon name="plus" /><span>Listas grandes podem levar mais tempo para processar.</span></li>
                    <li><x-import-icon name="plus" /><span>Em caso de falha, confira os alertas e tente novamente.</span></li>
                </ul>
            </section>
        </aside>
    </div>
</div>
