<?php

use App\Models\ImportBatch;
use App\Services\CnpjImportService;
use App\Services\ImportQueueService;
use App\Support\Cnpj;
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
            $this->recentBatches,
        );

        session()->flash(
            'success',
            $count === 1
                ? '1 CNPJ enviado para enriquecimento.'
                : $count.' CNPJs enviados para enriquecimento.'
        );
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


            @if ($this->statusCount('ready') > 0)

                <div class="ec-import-queue-bar">

                    <div>

                        <strong>
                            {{ $this->statusCount('ready') }}
                            CNPJ(s) aguardando enriquecimento
                        </strong>

                        <span>
                            Os dados serão consultados e processados em segundo plano.
                        </span>

                    </div>

                    <div class="flex items-center gap-3">

                        <span
                            class="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300"
                        >
                            BrasilAPI pública
                        </span>

                        <button
                            type="button"
                            wire:click="queueCurrentBatch"
                            wire:loading.attr="disabled"
                            wire:target="queueCurrentBatch"
                            class="ec-button-primary"
                        >
                            <span
                                wire:loading.remove
                                wire:target="queueCurrentBatch"
                            >
                                Enriquecer
                                {{ $this->statusCount('ready') }}
                                CNPJ(s)
                            </span>

                            <span
                                wire:loading
                                wire:target="queueCurrentBatch"
                            >
                                Enviando...
                            </span>
                        </button>

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
                        Prontos
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
                                CNPJ informado
                            </th>

                            <th>
                                CNPJ normalizado
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Observação
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        @foreach ($this->currentItems as $item)

                            <tr
                                wire:key="import-item-{{ $item->id }}"
                            >

                                <td>
                                    {{ $item->row_number }}
                                </td>

                                <td class="font-mono">
                                    {{ $item->raw_cnpj }}
                                </td>

                                <td class="font-mono">

                                    @if (
                                        $item->normalized_cnpj
                                        && Cnpj::isWellFormed(
                                            $item->normalized_cnpj
                                        )
                                    )

                                        {{ Cnpj::format(
                                            $item->normalized_cnpj
                                        ) }}

                                    @elseif ($item->normalized_cnpj)

                                        {{ $item->normalized_cnpj }}

                                    @else
                                        —
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

                                <td class="ec-table-muted">
                                    {{ $item->error_message ?: '—' }}
                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

        </section>

    @endif

</div>
