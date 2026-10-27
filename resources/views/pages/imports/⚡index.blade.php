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

@include('partials.imports.figma-v65')
