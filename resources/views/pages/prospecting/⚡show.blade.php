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

{{-- Layout visual Figma figma-show-v63; backend Volt preservado. --}}
@include('pages.prospecting.partials.figma-show-v63')
