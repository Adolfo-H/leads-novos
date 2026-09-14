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
    class="ec-page-shell"
    @if (
        $this->detail['status']
        === 'processing'
    )
        wire:poll.10s
    @endif
>

    {{-- VOLTAR --}}
    <a
        href="{{ route('prospecting.index') }}"
        wire:navigate
        class="
            inline-flex items-center gap-2
            text-xs font-semibold
            text-[#8790ae]
            hover:text-cyan-300
        "
    >
        ← Motor de Prospecção
    </a>


    {{-- CABEÇALHO --}}
    <div class="ec-page-header mt-4">

        <div>

            <div class="ec-page-kicker">
                Rodada de Prospecção
            </div>

            <h1 class="ec-page-title mt-1">
                {{
                    $this->detail[
                        'created_label'
                    ]
                    ?? 'Execução'
                }}
            </h1>

            <p class="ec-page-description">
                Lote
                {{ $this->detail['uuid'] }}
            </p>

        </div>

        <div
            class="
                rounded-xl border
                border-white/[0.07]
                bg-white/[0.03]
                px-4 py-2
                text-xs font-semibold
                text-[#d9ddef]
            "
        >
            {{
                $this->detail[
                    'status_label'
                ]
            }}
        </div>

    </div>


    {{-- MÉTRICAS --}}
    <div
        class="
            mt-5 grid gap-3
            sm:grid-cols-2
            lg:grid-cols-4
            xl:grid-cols-8
        "
    >

        @php
            $metrics = [
                [
                    'label' => 'Descobertos',
                    'value' => $this->detail[
                        'discovered_count'
                    ],
                ],
                [
                    'label' => 'Enviados',
                    'value' => $this->detail[
                        'total_rows'
                    ],
                ],
                [
                    'label' => 'Processados',
                    'value' => $this->detail[
                        'processed_rows'
                    ],
                ],
                [
                    'label' => 'Leads',
                    'value' => $this->detail[
                        'lead_count'
                    ],
                ],
                [
                    'label' => 'Bloqueados',
                    'value' => $this->detail[
                        'blocked_count'
                    ],
                ],
                [
                    'label' => 'Pesquisados',
                    'value' => $this->detail[
                        'researched_count'
                    ],
                ],
                [
                    'label' => 'Exportadores',
                    'value' => $this->detail[
                        'export_identified_count'
                    ],
                ],
                [
                    'label' => 'Falhas',
                    'value' => $this->detail[
                        'failed_count'
                    ],
                ],
            ];
        @endphp

        @foreach ($metrics as $metric)

            <div class="ec-intelligence-card">

                <div class="ec-intelligence-label">
                    {{ $metric['label'] }}
                </div>

                <div
                    class="
                        mt-2 text-xl
                        font-bold
                        text-[#eef1ff]
                    "
                >
                    {{ $metric['value'] }}
                </div>

            </div>

        @endforeach

    </div>


    {{-- FILTROS DA RODADA --}}
    @php
        $filters =
            $this->detail[
                'filters'
            ];

        $states =
            data_get(
                $filters,
                'states',
                []
            );

        $cnaes =
            data_get(
                $filters,
                'cnaes',
                []
            );
    @endphp

    @if (
        is_array($states)
        || is_array($cnaes)
    )

        <div
            class="
                mt-5 flex flex-wrap
                gap-3 rounded-2xl
                border border-white/[0.06]
                bg-white/[0.02]
                px-5 py-4
                text-xs text-[#8992af]
            "
        >

            @if (
                is_array($states)
                && $states !== []
            )
                <span>
                    <strong class="text-[#cbd1e7]">
                        UFs:
                    </strong>

                    {{ implode(', ', $states) }}
                </span>
            @endif

            @if (
                is_array($cnaes)
                && $cnaes !== []
            )
                <span>
                    <strong class="text-[#cbd1e7]">
                        CNAEs:
                    </strong>

                    {{ implode(', ', $cnaes) }}
                </span>
            @endif

        </div>

    @endif


    {{-- EXPORTAÇÃO --}}
    <div
        class="
            mt-5 flex flex-wrap
            items-center justify-between
            gap-3 rounded-2xl
            border border-white/[0.06]
            bg-white/[0.02]
            px-5 py-4
        "
    >

        <div>

            <div
                class="
                    text-sm font-semibold
                    text-[#eef1ff]
                "
            >
                Exportar resultado
            </div>

            <div
                class="
                    mt-1 text-xs
                    text-[#7781a2]
                "
            >
                O Excel respeita o filtro selecionado abaixo.
            </div>

        </div>

        <flux:button
            type="button"
            variant="primary"
            icon="arrow-down-tray"
            wire:click="exportExcel"
            wire:loading.attr="disabled"
            wire:target="exportExcel"
        >
            <span
                wire:loading.remove
                wire:target="exportExcel"
            >
                Exportar Excel
            </span>

            <span
                wire:loading
                wire:target="exportExcel"
            >
                Gerando...
            </span>
        </flux:button>

    </div>


    {{-- FILTROS --}}
    <div
        class="
            mt-5 flex flex-wrap
            items-center gap-2
        "
    >

        @php
            $filterOptions = [
                'all' => [
                    'label' => 'Todos',
                    'count' => count(
                        $this->detail[
                            'items'
                        ]
                    ),
                ],

                'leads' => [
                    'label' => 'Leads',
                    'count' => $this->detail[
                        'lead_count'
                    ],
                ],

                'new_crm' => [
                    'label' => 'Novos no CRM',
                    'count' => $this->crmStatusCount(
                        'not_found'
                    ),
                ],

                'reprospecting' => [
                    'label' => 'Reprospecção',
                    'count' => $this->crmStatusCount(
                        'prospected'
                    ),
                ],

                'opportunities' => [
                    'label' => 'Oportunidades',
                    'count' => $this->crmStatusCount(
                        'opportunity'
                    ),
                ],

                'clients' => [
                    'label' => 'Clientes',
                    'count' => $this->crmStatusCount(
                        'client'
                    ),
                ],

                'exporters' => [
                    'label' => 'Exportadores',
                    'count' => $this->detail[
                        'export_identified_count'
                    ],
                ],

                'failed' => [
                    'label' => 'Falhas',
                    'count' => $this->detail[
                        'failed_count'
                    ],
                ],
            ];
        @endphp

        @foreach (
            $filterOptions
            as $filterKey => $filterOption
        )

            <button
                type="button"
                wire:click="$set('filter', '{{ $filterKey }}')"
                class="
                    inline-flex items-center
                    gap-2 rounded-xl
                    border px-3 py-2
                    text-xs font-semibold
                    transition
                    {{
                        $filter === $filterKey
                            ? 'border-cyan-300/35 bg-cyan-300/10 text-cyan-300'
                            : 'border-white/[0.07] bg-white/[0.02] text-[#8992af] hover:bg-white/[0.05] hover:text-white'
                    }}
                "
            >
                {{ $filterOption['label'] }}

                <span
                    class="
                        rounded-md
                        bg-white/[0.06]
                        px-1.5 py-0.5
                        text-[10px]
                    "
                >
                    {{ $filterOption['count'] }}
                </span>
            </button>

        @endforeach

    </div>


    {{-- EMPRESAS --}}
    <section
        class="
            mt-5 overflow-hidden
            rounded-2xl
            border border-white/[0.06]
            bg-white/[0.02]
        "
    >

        <div
            class="
                border-b
                border-white/[0.06]
                px-5 py-4
            "
        >

            <div
                class="
                    text-sm font-semibold
                    text-[#eef1ff]
                "
            >
                Empresas da rodada
            </div>

            <div
                class="
                    mt-1 text-xs
                    text-[#7781a2]
                "
            >
                Resultado individual do pipeline comercial.
            </div>

        </div>


        @forelse (
            $this->filteredItems
            as $item
        )

            <div
                class="
                    grid gap-4
                    border-b
                    border-white/[0.05]
                    px-5 py-5
                    last:border-b-0
                    xl:grid-cols-[minmax(0,2fr)_90px_140px_170px_90px_130px_180px]
                    xl:items-center
                "
            >

                {{-- EMPRESA --}}
                <div class="min-w-0">

                    <div
                        class="
                            truncate text-sm
                            font-semibold
                            text-[#eef1ff]
                        "
                    >
                        {{
                            $item[
                                'company_name'
                            ]
                            ?? $item['cnpj']
                        }}
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            text-[#697394]
                        "
                    >
                        {{ $item['cnpj'] }}
                    </div>

                    @if ($item['error'])
                        <div
                            class="
                                mt-2 text-xs
                                text-red-300
                            "
                        >
                            {{ $item['error'] }}
                        </div>
                    @endif

                </div>


                {{-- ICP --}}
                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            text-[#697394]
                        "
                    >
                        ICP
                    </div>

                    <div
                        class="
                            mt-1 text-sm
                            font-bold
                            text-[#e5e9fa]
                        "
                    >
                        {{
                            $item[
                                'icp_grade'
                            ]
                            ?? '—'
                        }}

                        @if (
                            $item[
                                'icp_score'
                            ] !== null
                        )
                            <span
                                class="
                                    text-xs
                                    font-normal
                                    text-[#7781a2]
                                "
                            >
                                {{
                                    $item[
                                        'icp_score'
                                    ]
                                }}
                            </span>
                        @endif
                    </div>

                </div>


                {{-- CRM --}}
                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            text-[#697394]
                        "
                    >
                        CRM
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            font-semibold
                            text-[#cbd1e7]
                        "
                    >
                        {{
                            $item[
                                'crm_label'
                            ]
                        }}
                    </div>

                    @if ($item['hubspot_url'])

                        <a
                            href="{{
                                $item[
                                    'hubspot_url'
                                ]
                            }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="
                                mt-1 inline-block
                                text-[10px]
                                text-cyan-300
                                hover:text-cyan-200
                            "
                        >
                            Abrir HubSpot ↗
                        </a>

                    @endif

                </div>


                {{-- EXPORTAÇÃO --}}
                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            text-[#697394]
                        "
                    >
                        Exportação
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            font-semibold
                            text-[#cbd1e7]
                        "
                    >
                        {{
                            $item[
                                'export_label'
                            ]
                        }}
                    </div>

                </div>


                {{-- SDR --}}
                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            text-[#697394]
                        "
                    >
                        SDR
                    </div>

                    <div
                        class="
                            mt-1 text-sm
                            font-bold
                            text-cyan-300
                        "
                    >
                        {{
                            $item[
                                'sdr_score'
                            ]
                            ?? '—'
                        }}
                    </div>

                </div>


                {{-- RESULTADO --}}
                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            text-[#697394]
                        "
                    >
                        Resultado
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            font-bold
                            {{
                                $this->outcomeClass(
                                    $item[
                                        'outcome'
                                    ]
                                )
                            }}
                        "
                    >
                        {{ $item['outcome'] }}
                    </div>

                </div>


                {{-- AÇÃO --}}
                <div>

                    @if ($item['company_uuid'])

                        <a
                            href="{{
                                route(
                                    'companies.show',
                                    $item[
                                        'company_uuid'
                                    ]
                                )
                            }}"
                            wire:navigate
                            class="
                                inline-flex
                                rounded-lg
                                border
                                border-white/[0.09]
                                bg-white/[0.03]
                                px-3 py-2
                                text-xs font-semibold
                                text-[#d9ddef]
                                hover:bg-white/[0.06]
                            "
                        >
                            Abrir dossiê
                        </a>

                    @else

                        <span
                            class="
                                text-xs
                                text-[#697394]
                            "
                        >
                            Aguardando processamento
                        </span>

                    @endif

                    @if ($item['blocked_reason'])

                        <div
                            class="
                                mt-2 text-[10px]
                                leading-4
                                text-[#8992af]
                            "
                        >
                            {{
                                $item[
                                    'blocked_reason'
                                ]
                            }}
                        </div>

                    @endif

                </div>

            </div>

        @empty

            <div
                class="
                    px-5 py-12
                    text-center
                "
            >
                <div
                    class="
                        text-sm font-semibold
                        text-[#b7bfd9]
                    "
                >
                    Nenhuma empresa neste filtro
                </div>

                <div
                    class="
                        mt-2 text-xs
                        text-[#697394]
                    "
                >
                    Selecione outro resultado da rodada.
                </div>
            </div>

        @endforelse

    </section>

</div>
