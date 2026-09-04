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

    public function refreshCurrentBatch(): void
    {
        unset(
            $this->currentBatch,
            $this->currentItems,
            $this->currentStatusCounts,
            $this->currentIntelligenceCounts,
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

        return \App\Models\ImportItem::query()
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
                fn ($status) =>
                    $this->currentStatusCounts[
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
            'clients' =>
                (int) ($crmCounts['client'] ?? 0),

            'opportunities' =>
                (int) ($crmCounts['opportunity'] ?? 0),

            'prospected' =>
                (int) ($crmCounts['prospected'] ?? 0),

            'known' =>
                (int) ($crmCounts['known'] ?? 0),

            'new' =>
                (int) ($crmCounts['not_found'] ?? 0),

            'icp_a' =>
                (int) ($icpCounts['A'] ?? 0),

            'icp_b' =>
                (int) ($icpCounts['B'] ?? 0),

            'icp_c' =>
                (int) ($icpCounts['C'] ?? 0),

            'icp_d' =>
                (int) ($icpCounts['D'] ?? 0),
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

        return \App\Models\ImportItem::query()
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
            'client' =>
                'bg-emerald-500/15 text-emerald-300',

            'opportunity' =>
                'bg-amber-500/15 text-amber-300',

            'prospected' =>
                'bg-sky-500/15 text-sky-300',

            'known' =>
                'bg-violet-500/15 text-violet-300',

            'not_found' =>
                'bg-white/5 text-[#a8afc8]',

            default =>
                'bg-white/5 text-[#7f87a7]',
        };
    }

    public function icpGradeClasses(
        ?string $grade
    ): string {
        return match ($grade) {
            'A' =>
                'bg-emerald-500/15 text-emerald-300',

            'B' =>
                'bg-sky-500/15 text-sky-300',

            'C' =>
                'bg-amber-500/15 text-amber-300',

            default =>
                'bg-rose-500/15 text-rose-300',
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

                'detail' =>
                    $item->error_message
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

                'label' =>
                    'Falha no enriquecimento',

                'detail' =>
                    $item->error_message
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

                'label' =>
                    'Nova tentativa pendente',

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

                'label' =>
                    'CRM não verificado',

                'detail' =>
                    is_string($crmError)
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

                'label' =>
                    'Divergência CRM',

                'detail' =>
                    'Prospector: '
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

                'label' =>
                    'Receita via fallback',

                'detail' =>
                    'Não constava na base mensal; '
                    .'os dados foram obtidos por '
                    .'consulta pontual na BrasilAPI.',
            ];
        }

        return $alerts;
    }

    public function alertClasses(
        string $type
    ): string {
        return match ($type) {
            'danger' =>
                'border-rose-400/15 '
                .'bg-rose-400/[0.06] '
                .'text-rose-300',

            'warning' =>
                'border-amber-400/15 '
                .'bg-amber-400/[0.06] '
                .'text-amber-300',

            'info' =>
                'border-sky-400/15 '
                .'bg-sky-400/[0.06] '
                .'text-sky-300',

            default =>
                'border-white/[0.06] '
                .'bg-white/[0.03] '
                .'text-[#aab2cc]',
        };
    }

};
?>

<div class="ec-page-shell">

    <div class="ec-page-header">

        <div>

            <div class="ec-page-kicker">
                Entrada de dados
            </div>

            <h1 class="ec-page-title mt-1">
                Importações
            </h1>

            <p class="ec-page-description">
                Insira CNPJs para validação, deduplicação e processamento pelo Prospector.
            </p>

        </div>

    </div>


    @if (session('success'))

        <div class="ec-alert-success ec-import-queue-alert">
            <span class="ec-alert-dot"></span>
            {{ session('success') }}
        </div>

    @endif

    @error('queue')

        <div class="ec-import-queue-error">
            {{ $message }}
        </div>

    @enderror


    <div class="ec-import-grid">

        {{-- NOVA IMPORTAÇÃO --}}
        <section class="ec-detail-panel">

            <div class="ec-detail-header">

                <div>

                    <h2 class="ec-detail-title">
                        Nova importação
                    </h2>

                    <p class="ec-detail-description">
                        Cole um ou vários CNPJs separados por linha, vírgula ou ponto e vírgula.
                    </p>

                </div>

            </div>


            <form
                wire:submit="process"
                class="ec-import-form"
            >

                <div>

                    <label
                        for="input"
                        class="ec-field-label"
                    >
                        Lista de CNPJs
                    </label>

                    <textarea
                        id="input"
                        wire:model="input"
                        rows="12"
                        placeholder="11.222.333/0001-81&#10;22.333.444/0001-00&#10;33.444.555/0001-00"
                        class="ec-input ec-import-textarea"
                    ></textarea>

                    @error('input')

                        <p class="ec-field-error">
                            {{ $message }}
                        </p>

                    @enderror

                </div>


                <div class="ec-import-actions">

                    <span>
                        O sistema valida e remove duplicidades antes do enriquecimento.
                    </span>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="process"
                        class="ec-button-primary"
                    >

                        <span
                            wire:loading.remove
                            wire:target="process"
                        >
                            Processar CNPJs
                        </span>

                        <span
                            wire:loading
                            wire:target="process"
                        >
                            Processando...
                        </span>

                    </button>

                </div>

            </form>

        </section>


        {{-- HISTÓRICO --}}
        <section class="ec-detail-panel">

            <div class="ec-detail-header">

                <div>

                    <h2 class="ec-detail-title">
                        Importações recentes
                    </h2>

                    <p class="ec-detail-description">
                        Últimos lotes processados.
                    </p>

                </div>

            </div>


            <div class="ec-import-history">

                @forelse ($this->recentBatches as $batch)

                    <button
                        type="button"
                        wire:click="openBatch({{ $batch->id }})"
                        class="ec-import-history-item {{
                            $batchId === $batch->id
                                ? 'is-active'
                                : ''
                        }}"
                    >

                        <div>

                            <strong>
                                Lote #{{ $batch->id }}
                            </strong>

                            <span>
                                {{ $batch->created_at->format('d/m/Y H:i') }}
                            </span>

                        </div>

                        <span>
                            {{ $batch->total_rows }}
                        </span>

                    </button>

                @empty

                    <div class="ec-import-empty">
                        Nenhuma importação realizada.
                    </div>

                @endforelse

            </div>

        </section>

    </div>


    @if ($this->currentBatch)

        {{-- RESUMO --}}
        <section>

            <div class="ec-section-heading">

                <div>

                    <h2 class="ec-section-title">
                        Resultado do lote #{{ $this->currentBatch->id }}
                    </h2>

                    <p class="ec-section-description">
                        Classificação dos CNPJs recebidos.
                    </p>

                </div>

            </div>


                        @php
                $readyCount =
                    $this->statusCount('ready');

                $queuedCount =
                    $this->statusCount('queued');

                $processingCount =
                    $this->statusCount('processing');

                $completedCount =
                    $this->statusCount('completed');

                $failedCount =
                    $this->statusCount('failed');

                $enrichmentTotal =
                    $readyCount
                    + $queuedCount
                    + $processingCount
                    + $completedCount
                    + $failedCount;

                $finishedCount =
                    $completedCount
                    + $failedCount;

                $isEnriching =
                    $queuedCount > 0
                    || $processingCount > 0;

                $hasPendingEnrichment =
                    $readyCount > 0;

                $enrichmentProgress =
                    $enrichmentTotal > 0
                        ? min(
                            100,
                            (int) round(
                                (
                                    $finishedCount
                                    / $enrichmentTotal
                                ) * 100
                            )
                        )
                        : 0;

                $showCommercialIntelligence =
                    ! $hasPendingEnrichment
                    && ! $isEnriching;
            @endphp


            {{-- AGUARDANDO ENRIQUECIMENTO --}}
            @if ($hasPendingEnrichment)

                <div
                    class="
                        mb-6 overflow-hidden
                        rounded-2xl
                        border border-cyan-400/20
                        bg-gradient-to-r
                        from-cyan-400/[0.10]
                        via-sky-400/[0.05]
                        to-transparent
                    "
                >

                    <div
                        class="
                            flex flex-col gap-5
                            px-5 py-5
                            lg:flex-row
                            lg:items-center
                            lg:justify-between
                        "
                    >

                        <div
                            class="
                                flex min-w-0
                                items-start gap-4
                            "
                        >

                            <div
                                class="
                                    flex size-12
                                    shrink-0
                                    items-center
                                    justify-center
                                    rounded-2xl
                                    border
                                    border-cyan-300/15
                                    bg-cyan-300/10
                                    text-cyan-300
                                "
                            >

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    class="size-6"
                                >
                                    <path
                                        d="
                                            M12 3v12
                                            m0 0 4-4
                                            m-4 4-4-4
                                        "
                                    />

                                    <path
                                        d="
                                            M5 17v2
                                            a2 2 0 0 0
                                            2 2h10
                                            a2 2 0 0 0
                                            2-2v-2
                                        "
                                    />
                                </svg>

                            </div>


                            <div class="min-w-0">

                                <div
                                    class="
                                        text-[11px]
                                        font-semibold
                                        uppercase
                                        tracking-[0.18em]
                                        text-cyan-300/80
                                    "
                                >
                                    Pronto para processar
                                </div>

                                <h3
                                    class="
                                        mt-1 text-base
                                        font-semibold
                                        text-[#f3f5ff]
                                    "
                                >
                                    {{ $readyCount }}
                                    CNPJ(s) aguardando
                                    enriquecimento
                                </h3>

                                <p
                                    class="
                                        mt-1 max-w-2xl
                                        text-sm
                                        text-[#929bbb]
                                    "
                                >
                                    O Prospector vai consultar
                                    a Receita Federal, montar o
                                    grupo empresarial, calcular
                                    o ICP e verificar o CRM.
                                </p>


                                <div
                                    class="
                                        mt-3 flex
                                        flex-wrap gap-2
                                    "
                                >

                                    <span
                                        class="
                                            inline-flex
                                            items-center gap-1.5
                                            rounded-full
                                            border
                                            border-white/10
                                            bg-white/[0.04]
                                            px-2.5 py-1
                                            text-[11px]
                                            font-medium
                                            text-[#aeb6d1]
                                        "
                                    >
                                        <span
                                            class="
                                                size-1.5
                                                rounded-full
                                                bg-emerald-300
                                            "
                                        ></span>

                                        Receita local
                                    </span>


                                    <span
                                        class="
                                            inline-flex
                                            items-center gap-1.5
                                            rounded-full
                                            border
                                            border-white/10
                                            bg-white/[0.04]
                                            px-2.5 py-1
                                            text-[11px]
                                            font-medium
                                            text-[#aeb6d1]
                                        "
                                    >
                                        <span
                                            class="
                                                size-1.5
                                                rounded-full
                                                bg-orange-300
                                            "
                                        ></span>

                                        HubSpot
                                    </span>


                                    <span
                                        class="
                                            inline-flex
                                            items-center gap-1.5
                                            rounded-full
                                            border
                                            border-white/10
                                            bg-white/[0.04]
                                            px-2.5 py-1
                                            text-[11px]
                                            font-medium
                                            text-[#aeb6d1]
                                        "
                                    >
                                        BrasilAPI fallback
                                    </span>

                                </div>

                            </div>

                        </div>


                        <button
                            type="button"
                            wire:click="queueCurrentBatch"
                            wire:loading.attr="disabled"
                            wire:target="queueCurrentBatch"
                            class="
                                inline-flex
                                min-h-11
                                shrink-0
                                items-center
                                justify-center
                                gap-2
                                rounded-xl
                                bg-[#45c5b8]
                                px-5 py-2.5
                                text-sm
                                font-semibold
                                text-[#081b1f]
                                shadow-lg
                                shadow-cyan-950/20
                                transition
                                hover:bg-[#59d4c7]
                                disabled:cursor-wait
                                disabled:opacity-70
                            "
                        >

                            <span
                                wire:loading.remove
                                wire:target="queueCurrentBatch"
                                class="
                                    inline-flex
                                    items-center gap-2
                                "
                            >
                                Iniciar enriquecimento

                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    class="size-4"
                                >
                                    <path
                                        d="m9 18 6-6-6-6"
                                    />
                                </svg>
                            </span>


                            <span
                                wire:loading
                                wire:target="queueCurrentBatch"
                                class="
                                    inline-flex
                                    items-center gap-2
                                "
                            >

                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    class="
                                        size-4
                                        animate-spin
                                    "
                                >
                                    <circle
                                        cx="12"
                                        cy="12"
                                        r="9"
                                        stroke="currentColor"
                                        stroke-opacity=".25"
                                        stroke-width="3"
                                    />

                                    <path
                                        d="
                                            M21 12
                                            a9 9 0 0 0-9-9
                                        "
                                        stroke="currentColor"
                                        stroke-width="3"
                                        stroke-linecap="round"
                                    />
                                </svg>

                                Enfileirando...

                            </span>

                        </button>

                    </div>

                </div>

            @endif


            {{-- PROCESSANDO --}}
            @if ($isEnriching)

                <div
                    wire:poll.2s="refreshCurrentBatch"
                    class="
                        mb-6 overflow-hidden
                        rounded-2xl
                        border border-cyan-400/20
                        bg-gradient-to-br
                        from-[#17334d]
                        via-[#182747]
                        to-[#171d3e]
                    "
                >

                    <div class="px-5 py-5">

                        <div
                            class="
                                flex flex-col gap-5
                                lg:flex-row
                                lg:items-center
                                lg:justify-between
                            "
                        >

                            <div
                                class="
                                    flex items-center
                                    gap-4
                                "
                            >

                                <div
                                    class="
                                        flex size-12
                                        shrink-0
                                        items-center
                                        justify-center
                                        rounded-2xl
                                        border
                                        border-cyan-300/15
                                        bg-cyan-300/10
                                    "
                                >

                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        class="
                                            size-6
                                            animate-spin
                                            text-cyan-300
                                        "
                                    >
                                        <circle
                                            cx="12"
                                            cy="12"
                                            r="9"
                                            stroke="currentColor"
                                            stroke-opacity=".20"
                                            stroke-width="3"
                                        />

                                        <path
                                            d="
                                                M21 12
                                                a9 9 0 0 0-9-9
                                            "
                                            stroke="currentColor"
                                            stroke-width="3"
                                            stroke-linecap="round"
                                        />
                                    </svg>

                                </div>


                                <div>

                                    <div
                                        class="
                                            text-[11px]
                                            font-semibold
                                            uppercase
                                            tracking-[0.18em]
                                            text-cyan-300/80
                                        "
                                    >
                                        Processamento automático
                                    </div>

                                    <h3
                                        class="
                                            mt-1 text-base
                                            font-semibold
                                            text-[#f3f5ff]
                                        "
                                    >
                                        Enriquecendo empresas...
                                    </h3>

                                    <p
                                        class="
                                            mt-1 text-sm
                                            text-[#929bbb]
                                        "
                                    >
                                        Consultando Receita,
                                        calculando ICP e
                                        verificando o CRM.
                                    </p>

                                </div>

                            </div>


                            <div
                                class="
                                    text-left
                                    lg:text-right
                                "
                            >

                                <div
                                    class="
                                        text-2xl
                                        font-bold
                                        text-[#f3f5ff]
                                    "
                                >
                                    {{ $enrichmentProgress }}%
                                </div>

                                <div
                                    class="
                                        mt-0.5 text-xs
                                        text-[#8993b3]
                                    "
                                >
                                    {{ $finishedCount }}
                                    de
                                    {{ $enrichmentTotal }}
                                    finalizados
                                </div>

                            </div>

                        </div>


                        <div class="mt-5">

                            <div
                                class="
                                    h-2 overflow-hidden
                                    rounded-full
                                    bg-white/[0.08]
                                "
                            >

                                <div
                                    class="
                                        h-full
                                        rounded-full
                                        bg-gradient-to-r
                                        from-[#45c5b8]
                                        via-cyan-300
                                        to-emerald-300
                                        transition-all
                                        duration-700
                                        ease-out
                                    "
                                    style="
                                        width:
                                        {{ $enrichmentProgress }}%;
                                    "
                                ></div>

                            </div>

                        </div>


                        <div
                            class="
                                mt-4 grid
                                grid-cols-3
                                gap-2
                            "
                        >

                            <div
                                class="
                                    rounded-xl
                                    border
                                    border-white/[0.06]
                                    bg-black/10
                                    px-3 py-2.5
                                "
                            >

                                <div
                                    class="
                                        text-[10px]
                                        font-semibold
                                        uppercase
                                        tracking-wide
                                        text-[#737e9f]
                                    "
                                >
                                    Na fila
                                </div>

                                <div
                                    class="
                                        mt-1 text-lg
                                        font-bold
                                        text-[#f0f2ff]
                                    "
                                >
                                    {{ $queuedCount }}
                                </div>

                            </div>


                            <div
                                class="
                                    rounded-xl
                                    border
                                    border-cyan-300/10
                                    bg-cyan-300/[0.05]
                                    px-3 py-2.5
                                "
                            >

                                <div
                                    class="
                                        text-[10px]
                                        font-semibold
                                        uppercase
                                        tracking-wide
                                        text-cyan-300/70
                                    "
                                >
                                    Processando
                                </div>

                                <div
                                    class="
                                        mt-1 text-lg
                                        font-bold
                                        text-cyan-300
                                    "
                                >
                                    {{ $processingCount }}
                                </div>

                            </div>


                            <div
                                class="
                                    rounded-xl
                                    border
                                    border-emerald-300/10
                                    bg-emerald-300/[0.05]
                                    px-3 py-2.5
                                "
                            >

                                <div
                                    class="
                                        text-[10px]
                                        font-semibold
                                        uppercase
                                        tracking-wide
                                        text-emerald-300/70
                                    "
                                >
                                    Finalizados
                                </div>

                                <div
                                    class="
                                        mt-1 text-lg
                                        font-bold
                                        text-emerald-300
                                    "
                                >
                                    {{ $finishedCount }}
                                </div>

                            </div>

                        </div>


                        @if ($failedCount > 0)

                            <div
                                class="
                                    mt-3 text-xs
                                    font-medium
                                    text-rose-300
                                "
                            >
                                {{ $failedCount }}
                                processamento(s)
                                finalizaram com erro.
                            </div>

                        @endif

                    </div>

                </div>

            @endif


            <div class="ec-import-summary">

                <div class="ec-import-stat">

                    <span>
                        Total
                    </span>

                    <strong>
                        {{ $this->currentBatch->total_rows }}
                    </strong>

                </div>


                <div class="ec-import-stat is-ready">

                    <span>
                        Válidos
                    </span>

                    <strong>
                        {{ $this->currentBatch->valid_rows }}
                    </strong>

                </div>


                <div class="ec-import-stat is-existing">

                    <span>
                        Já cadastrados
                    </span>

                    <strong>
                        {{ $this->currentBatch->existing_rows }}
                    </strong>

                </div>


                <div class="ec-import-stat is-duplicate">

                    <span>
                        Duplicados
                    </span>

                    <strong>
                        {{ $this->currentBatch->duplicate_rows }}
                    </strong>

                </div>


                <div class="ec-import-stat is-invalid">

                    <span>
                        Inválidos
                    </span>

                    <strong>
                        {{ $this->currentBatch->invalid_rows }}
                    </strong>

                </div>

            </div>


                        {{-- INTELIGÊNCIA DO LOTE --}}
            @if ($showCommercialIntelligence)

                <div
                    class="
                        mt-6 rounded-2xl
                        border border-white/[0.07]
                        bg-white/[0.025]
                        p-5
                    "
                >

                    <div
                        class="
                            flex flex-col gap-2
                            sm:flex-row
                            sm:items-end
                            sm:justify-between
                        "
                    >

                        <div>

                            <div
                                class="
                                    text-[11px]
                                    font-semibold
                                    uppercase
                                    tracking-[0.18em]
                                    text-[#697598]
                                "
                            >
                                Resultado comercial
                            </div>

                            <h3
                                class="
                                    mt-1 text-base
                                    font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Inteligência comercial do lote
                            </h3>

                            <p
                                class="
                                    mt-1 text-xs
                                    text-[#8089a9]
                                "
                            >
                                Classificação das empresas
                                processadas no CRM e no ICP.
                            </p>

                        </div>


                        <div
                            class="
                                inline-flex
                                w-fit
                                items-center gap-2
                                rounded-full
                                border
                                border-emerald-400/15
                                bg-emerald-400/[0.06]
                                px-3 py-1.5
                                text-xs
                                font-medium
                                text-emerald-300
                            "
                        >
                            <span
                                class="
                                    size-1.5
                                    rounded-full
                                    bg-emerald-300
                                "
                            ></span>

                            Processamento concluído
                        </div>

                    </div>


                    {{-- CRM --}}
                    <div class="mt-5">

                        <div
                            class="
                                mb-2.5 flex
                                items-center gap-2
                            "
                        >

                            <span
                                class="
                                    flex size-7
                                    items-center
                                    justify-center
                                    rounded-lg
                                    bg-[#ff7a59]/10
                                    text-[#ff9d83]
                                "
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    class="size-4"
                                >
                                    <circle
                                        cx="12"
                                        cy="12"
                                        r="3"
                                    />

                                    <path
                                        d="
                                            M19 12
                                            a7 7 0 0 1-7 7
                                            M12 5
                                            a7 7 0 0 1 7 7
                                        "
                                    />
                                </svg>
                            </span>

                            <span
                                class="
                                    text-xs
                                    font-semibold
                                    text-[#c8cee2]
                                "
                            >
                                Situação no CRM
                            </span>

                        </div>


                        <div
                            class="
                                grid grid-cols-2
                                gap-2.5
                                md:grid-cols-5
                            "
                        >

                            @foreach ([
                                [
                                    'key' => 'clients',
                                    'label' => 'Clientes',
                                    'class' =>
                                        'text-emerald-300',
                                ],
                                [
                                    'key' => 'opportunities',
                                    'label' => 'Oportunidades',
                                    'class' =>
                                        'text-amber-300',
                                ],
                                [
                                    'key' => 'prospected',
                                    'label' => 'Prospectados',
                                    'class' =>
                                        'text-sky-300',
                                ],
                                [
                                    'key' => 'known',
                                    'label' => 'Conhecidos',
                                    'class' =>
                                        'text-violet-300',
                                ],
                                [
                                    'key' => 'new',
                                    'label' => 'Novos',
                                    'class' =>
                                        'text-[#d8dced]',
                                ],
                            ] as $crmItem)

                                <div
                                    class="
                                        rounded-xl
                                        border
                                        border-white/[0.06]
                                        bg-[#171d3c]/65
                                        px-4 py-3.5
                                    "
                                >

                                    <div
                                        class="
                                            text-[10px]
                                            font-semibold
                                            uppercase
                                            tracking-wide
                                            text-[#737e9f]
                                        "
                                    >
                                        {{ $crmItem['label'] }}
                                    </div>

                                    <div
                                        class="
                                            mt-1.5 text-2xl
                                            font-bold
                                            {{
                                                $crmItem[
                                                    'class'
                                                ]
                                            }}
                                        "
                                    >
                                        {{
                                            $this
                                                ->currentIntelligenceCounts[
                                                    $crmItem[
                                                        'key'
                                                    ]
                                                ]
                                        }}
                                    </div>

                                </div>

                            @endforeach

                        </div>

                    </div>


                    {{-- ICP --}}
                    <div
                        class="
                            mt-5 border-t
                            border-white/[0.06]
                            pt-5
                        "
                    >

                        <div
                            class="
                                mb-2.5 flex
                                items-center gap-2
                            "
                        >

                            <span
                                class="
                                    flex size-7
                                    items-center
                                    justify-center
                                    rounded-lg
                                    bg-cyan-300/10
                                    text-cyan-300
                                "
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    class="size-4"
                                >
                                    <path
                                        d="
                                            M4 19V9
                                            m5 10V5
                                            m5 14v-7
                                            m5 7V3
                                        "
                                    />
                                </svg>
                            </span>

                            <span
                                class="
                                    text-xs
                                    font-semibold
                                    text-[#c8cee2]
                                "
                            >
                                Perfil ICP
                            </span>

                        </div>


                        <div
                            class="
                                grid grid-cols-2
                                gap-2.5
                                md:grid-cols-4
                            "
                        >

                            @foreach ([
                                [
                                    'key' => 'icp_a',
                                    'label' => 'ICP A',
                                    'class' =>
                                        'text-emerald-300',
                                ],
                                [
                                    'key' => 'icp_b',
                                    'label' => 'ICP B',
                                    'class' =>
                                        'text-sky-300',
                                ],
                                [
                                    'key' => 'icp_c',
                                    'label' => 'ICP C',
                                    'class' =>
                                        'text-amber-300',
                                ],
                                [
                                    'key' => 'icp_d',
                                    'label' => 'ICP D',
                                    'class' =>
                                        'text-rose-300',
                                ],
                            ] as $icpItem)

                                <div
                                    class="
                                        rounded-xl
                                        border
                                        border-white/[0.06]
                                        bg-[#171d3c]/65
                                        px-4 py-3.5
                                    "
                                >

                                    <div
                                        class="
                                            text-[10px]
                                            font-semibold
                                            uppercase
                                            tracking-wide
                                            text-[#737e9f]
                                        "
                                    >
                                        {{ $icpItem['label'] }}
                                    </div>

                                    <div
                                        class="
                                            mt-1.5 text-2xl
                                            font-bold
                                            {{
                                                $icpItem[
                                                    'class'
                                                ]
                                            }}
                                        "
                                    >
                                        {{
                                            $this
                                                ->currentIntelligenceCounts[
                                                    $icpItem[
                                                        'key'
                                                    ]
                                                ]
                                        }}
                                    </div>

                                </div>

                            @endforeach

                        </div>

                    </div>

                </div>

            @endif


        </section>


        {{-- ITENS --}}
        <section class="ec-table-panel">

            <div class="ec-table-toolbar">

                <div>

                    <h2 class="ec-table-title">
                        CNPJs do lote
                    </h2>

                    <p class="ec-table-description">
                        Exibindo até 100 registros.
                    </p>

                </div>

            </div>


            <div class="overflow-x-auto">

                <table class="ec-table">

                    <thead>

                        <tr>

                            <th>
                                Linha
                            </th>

                            <th>
                                Empresa
                            </th>

                            <th>
                                Unidades
                            </th>

                            <th>
                                ICP
                            </th>

                            <th>
                                CRM
                            </th>

                            <th>
                                Processamento
                            </th>

                            <th>
                                Alertas
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        @foreach ($this->currentItems as $item)

                            @php
                                $company =
                                    $item->company;

                                $icp =
                                    $company?->icpScore;

                                $crm =
                                    $company?->crmCheck;

                                $crmConflict =
                                    (bool) data_get(
                                        $crm?->metadata,
                                        'crm_conflict',
                                        false
                                    );
                            @endphp

                            <tr
                                wire:key="import-item-{{ $item->id }}"
                            >

                                <td>
                                    {{ $item->row_number }}
                                </td>


                                <td>

                                    @if ($company)

                                        <a
                                            href="{{
                                                route(
                                                    'companies.show',
                                                    $company
                                                )
                                            }}"
                                            wire:navigate
                                            class="
                                                font-semibold
                                                text-[#eef1ff]
                                                transition
                                                hover:text-white
                                            "
                                        >
                                            {{
                                                $company
                                                    ->corporate_name
                                            }}
                                        </a>

                                        <div
                                            class="
                                                mt-1 font-mono
                                                text-xs
                                                text-[#7f87a7]
                                            "
                                        >
                                            @if (
                                                $item
                                                    ->normalized_cnpj
                                                && Cnpj::isWellFormed(
                                                    $item
                                                        ->normalized_cnpj
                                                )
                                            )
                                                {{
                                                    Cnpj::format(
                                                        $item
                                                            ->normalized_cnpj
                                                    )
                                                }}
                                            @else
                                                {{
                                                    $item->raw_cnpj
                                                }}
                                            @endif
                                        </div>

                                    @else

                                        <div
                                            class="
                                                font-mono
                                                text-sm
                                                text-[#d9ddef]
                                            "
                                        >
                                            {{
                                                $item->raw_cnpj
                                            }}
                                        </div>

                                    @endif

                                </td>


                                <td>

                                    @if ($company)

                                        <span
                                            class="
                                                font-semibold
                                                text-[#eef1ff]
                                            "
                                        >
                                            {{
                                                $company
                                                    ->establishments
                                                    ->count()
                                            }}
                                        </span>

                                    @else
                                        —
                                    @endif

                                </td>


                                <td>

                                    @if ($icp)

                                        <span
                                            class="
                                                inline-flex
                                                rounded-full
                                                px-2.5 py-1
                                                text-xs
                                                font-semibold
                                                {{
                                                    $this
                                                        ->icpGradeClasses(
                                                            $icp->grade
                                                        )
                                                }}
                                            "
                                        >
                                            {{ $icp->grade }}
                                            ·
                                            {{ $icp->score }}/100
                                        </span>

                                    @else
                                        —
                                    @endif

                                </td>


                                <td>

                                    @if ($crm)

                                        <div
                                            class="
                                                flex
                                                flex-wrap
                                                items-center
                                                gap-2
                                            "
                                        >

                                            <span
                                                class="
                                                    inline-flex
                                                    rounded-full
                                                    px-2.5 py-1
                                                    text-xs
                                                    font-semibold
                                                    {{
                                                        $this
                                                            ->crmStatusClasses(
                                                                $crm->status
                                                            )
                                                    }}
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->crmStatusLabel(
                                                            $crm->status
                                                        )
                                                }}
                                            </span>

                                            @if ($crmConflict)

                                                <span
                                                    title="
                                                        Divergência entre
                                                        a base ExportControl
                                                        e o HubSpot
                                                    "
                                                    class="
                                                        text-xs
                                                        font-semibold
                                                        text-amber-300
                                                    "
                                                >
                                                    ⚠
                                                </span>

                                            @endif

                                        </div>

                                    @else

                                        <span
                                            class="
                                                text-xs
                                                text-[#7f87a7]
                                            "
                                        >
                                            Não verificado
                                        </span>

                                    @endif

                                </td>


                                <td>

                                    <span
                                        class="
                                            ec-import-status
                                            ec-import-status-{{ $item->status }}
                                        "
                                    >
                                        {{
                                            $this->statusLabel(
                                                $item->status
                                            )
                                        }}
                                    </span>

                                </td>


                                                                <td>

                                    @php
                                        $alerts =
                                            $this
                                                ->itemAlerts(
                                                    $item
                                                );
                                    @endphp


                                    @if ($alerts === [])

                                        <span
                                            class="
                                                inline-flex
                                                items-center
                                                gap-1.5
                                                text-xs
                                                font-medium
                                                text-[#7883a5]
                                            "
                                        >

                                            <svg
                                                xmlns="
                                                    http://www.w3.org/2000/svg
                                                "
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                class="
                                                    size-3.5
                                                    text-emerald-300/70
                                                "
                                            >
                                                <path
                                                    d="
                                                        m5 12
                                                        4 4
                                                        L19 6
                                                    "
                                                />
                                            </svg>

                                            Sem alertas

                                        </span>

                                    @else

                                        <div
                                            class="
                                                flex
                                                min-w-[190px]
                                                max-w-[280px]
                                                flex-col
                                                gap-1.5
                                            "
                                        >

                                            @foreach (
                                                $alerts
                                                as $alert
                                            )

                                                <div
                                                    class="
                                                        rounded-lg
                                                        border
                                                        px-2.5
                                                        py-2
                                                        {{
                                                            $this
                                                                ->alertClasses(
                                                                    $alert[
                                                                        'type'
                                                                    ]
                                                                )
                                                        }}
                                                    "
                                                >

                                                    <div
                                                        class="
                                                            flex
                                                            items-start
                                                            gap-2
                                                        "
                                                    >

                                                        <span
                                                            class="
                                                                mt-[5px]
                                                                size-1.5
                                                                shrink-0
                                                                rounded-full
                                                                bg-current
                                                            "
                                                        ></span>


                                                        <div
                                                            class="
                                                                min-w-0
                                                            "
                                                        >

                                                            <div
                                                                class="
                                                                    text-xs
                                                                    font-semibold
                                                                    leading-4
                                                                "
                                                            >
                                                                {{
                                                                    $alert[
                                                                        'label'
                                                                    ]
                                                                }}
                                                            </div>


                                                            @if (
                                                                $alert[
                                                                    'detail'
                                                                ]
                                                            )

                                                                <div
                                                                    class="
                                                                        mt-0.5
                                                                        break-words
                                                                        text-[11px]
                                                                        leading-4
                                                                        text-[#a9b1ca]
                                                                    "
                                                                >
                                                                    {{
                                                                        $alert[
                                                                            'detail'
                                                                        ]
                                                                    }}
                                                                </div>

                                                            @endif

                                                        </div>

                                                    </div>

                                                </div>

                                            @endforeach

                                        </div>

                                    @endif

                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

        </section>

    @endif

</div>
