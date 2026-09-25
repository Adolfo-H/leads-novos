@php
    $hubSpotOnlyResults =
        $this->hubSpotOnlyLeads;
@endphp


@if (
    $hubSpotOnly
    || $hubSpotOnlyResults->total() > 0
)

    <style>
        .rf-crm-only-panel {
            overflow: hidden;
            border-color: rgba(245, 197, 75, .20);
        }

        .rf-crm-only-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 17px;
            border-bottom: 1px solid rgba(245, 197, 75, .14);
            background: rgba(245, 197, 75, .025);
        }

        .rf-crm-only-toolbar strong {
            display: block;
            color: #f4f8ff;
            font-size: 12px;
        }

        .rf-crm-only-toolbar span {
            display: block;
            margin-top: 3px;
            color: #8ca5bd;
            font-size: 10px;
            line-height: 1.4;
        }

        .rf-crm-only-count {
            flex: 0 0 auto;
            padding: 6px 9px;
            border: 1px solid rgba(245, 197, 75, .20);
            border-radius: 999px;
            background: rgba(245, 197, 75, .06);
            color: #ffd467;
            font-size: 9px;
            font-weight: 800;
        }

        .rf-crm-only-scroll {
            overflow-x: auto;
        }

        .rf-crm-only-head,
        .rf-crm-only-row {
            display: grid;
            grid-template-columns:
                minmax(260px, 1.5fr)
                165px
                minmax(260px, 1.3fr)
                190px
                120px;
            min-width: 1050px;
            gap: 15px;
            align-items: center;
        }

        .rf-crm-only-head {
            padding: 10px 17px;
            border-bottom: 1px solid rgba(111, 181, 226, .09);
            background: rgba(26, 77, 113, .12);
            color: #6e91b2;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .rf-crm-only-row {
            min-height: 100px;
            padding: 13px 17px;
            border-bottom: 1px solid rgba(111, 181, 226, .09);
            border-left: 3px solid rgba(245, 197, 75, .45);
        }

        .rf-crm-only-row:last-child {
            border-bottom: 0;
        }

        .rf-crm-only-tag {
            display: inline-flex;
            margin-top: 7px;
            padding: 4px 7px;
            border: 1px solid rgba(245, 197, 75, .20);
            border-radius: 999px;
            background: rgba(245, 197, 75, .055);
            color: #ffd467;
            font-size: 8px;
            font-weight: 800;
        }

        .rf-crm-only-name {
            color: #f5f9ff;
            font-size: 14px;
            font-weight: 800;
        }

        .rf-crm-only-meta {
            margin-top: 5px;
            color: #829db7;
            font-size: 9px;
            line-height: 1.4;
        }

        .rf-crm-only-warning {
            color: #ffd467;
            font-size: 10px;
            font-weight: 800;
        }

        .rf-crm-only-helper {
            margin-top: 5px;
            color: #738eaa;
            font-size: 9px;
            line-height: 1.4;
        }

        .rf-crm-only-stages {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 6px;
        }

        .rf-crm-only-stage {
            display: inline-flex;
            max-width: 180px;
            padding: 4px 7px;
            overflow: hidden;
            border: 1px solid rgba(46,221,210,.14);
            border-radius: 6px;
            background: rgba(46,221,210,.045);
            color: #5fe0d1;
            font-size: 9px;
            font-weight: 750;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>


    <section
        class="rf-panel rf-crm-only-panel"
    >

        <div class="rf-crm-only-toolbar">

            <div>

                <strong>
                    @if ($hubSpotOnly)
                        CRM sem vínculo fiscal
                    @else
                        Também encontramos no HubSpot
                    @endif
                </strong>

                <span>
                    Empresas preservadas no CRM,
                    mas ainda sem associação segura
                    com um CNPJ do Prospector.
                </span>

            </div>


            <div class="rf-crm-only-count">
                {{
                    number_format(
                        $hubSpotOnlyResults->total(),
                        0,
                        ',',
                        '.'
                    )
                }}
                registro(s)
            </div>

        </div>


        <div class="rf-crm-only-scroll">

            <div class="rf-crm-only-head">

                <div>
                    Empresa HubSpot
                </div>

                <div>
                    Identificação fiscal
                </div>

                <div>
                    Negócios / etapas
                </div>

                <div>
                    Comercial
                </div>

                <div>
                    Acesso
                </div>

            </div>


            @forelse (
                $hubSpotOnlyResults
                as $hubSpotCompany
            )

                @php
                    $allDeals =
                        $hubSpotCompany
                            ->deals;

                    $openDeals =
                        $allDeals
                            ->where(
                                'is_closed',
                                false
                            )
                            ->values();

                    $displayDeals =
                        $openDeals->isNotEmpty()
                            ? $openDeals
                            : $allDeals;

                    $stages =
                        $displayDeals
                            ->pluck(
                                'stage_label'
                            )
                            ->filter()
                            ->unique()
                            ->values();

                    $hubSpotUrl =
                        $this->hubSpotCompanyUrlById(
                            (string)
                            $hubSpotCompany
                                ->hubspot_id
                        );
                @endphp


                <article
                    wire:key="
                        hubspot-unmatched-{{
                            $hubSpotCompany->id
                        }}
                    "
                    class="rf-crm-only-row"
                >

                    <div>

                        <div class="rf-crm-only-name">
                            {{
                                $hubSpotCompany->name
                                ?: 'Empresa sem nome'
                            }}
                        </div>

                        <div class="rf-crm-only-meta">

                            @if ($hubSpotCompany->domain)

                                {{
                                    $hubSpotCompany->domain
                                }}

                            @endif


                            @if (
                                $hubSpotCompany->city
                                || $hubSpotCompany->state
                            )

                                @if ($hubSpotCompany->domain)
                                    ·
                                @endif

                                {{
                                    collect([
                                        $hubSpotCompany->city,
                                        $hubSpotCompany->state,
                                    ])
                                        ->filter()
                                        ->implode(' / ')
                                }}

                            @endif

                        </div>

                        <span class="rf-crm-only-tag">
                            HubSpot sem vínculo fiscal
                        </span>

                    </div>


                    <div>

                        <div class="rf-crm-only-warning">
                            CNPJ não identificado
                        </div>

                        <div class="rf-crm-only-helper">
                            Score e ICP ficam pendentes
                            até uma identificação segura.
                        </div>

                    </div>


                    <div>

                        <div class="rf-crm-only-meta">
                            {{
                                $allDeals->count()
                            }}
                            {{
                                $allDeals->count() === 1
                                    ? 'negócio'
                                    : 'negócios'
                            }}

                            @if ($openDeals->isNotEmpty())

                                ·
                                {{
                                    $openDeals->count()
                                }}
                                aberto(s)

                            @endif
                        </div>


                        @if ($stages->isNotEmpty())

                            <div class="rf-crm-only-stages">

                                @foreach (
                                    $stages->take(3)
                                    as $stage
                                )

                                    <span class="rf-crm-only-stage">
                                        {{ $stage }}
                                    </span>

                                @endforeach

                            </div>

                        @endif

                    </div>


                    <div>

                        <div class="rf-crm-only-meta">
                            Responsável
                        </div>

                        <div
                            style="
                                margin-top:4px;
                                color:#dbe9f7;
                                font-size:10px;
                                font-weight:700;
                            "
                        >
                            {{
                                $hubSpotCompany->owner_name
                                ?: 'Não informado'
                            }}
                        </div>

                        <div class="rf-crm-only-helper">
                            {{
                                $hubSpotCompany
                                    ->contacts
                                    ->count()
                            }}
                            contato(s) associado(s)
                        </div>

                    </div>


                    <div>

                        @if ($hubSpotUrl)

                            <a
                                href="{{ $hubSpotUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="
                                    rf-btn
                                    rf-btn-primary
                                "
                            >
                                HubSpot ↗
                            </a>

                        @endif

                    </div>

                </article>


            @empty

                <div class="rf-empty-state">

                    <strong>
                        Nenhuma empresa encontrada
                    </strong>

                    <span>
                        Não existem registros HubSpot
                        sem vínculo fiscal para os
                        critérios selecionados.
                    </span>

                </div>

            @endforelse

        </div>


        @if ($hubSpotOnlyResults->hasPages())

            <div class="rf-pagination">

                <div class="rf-pagination-info">

                    Exibindo

                    <strong>
                        {{
                            $hubSpotOnlyResults
                                ->firstItem()
                        }}
                    </strong>

                    a

                    <strong>
                        {{
                            $hubSpotOnlyResults
                                ->lastItem()
                        }}
                    </strong>

                    de

                    <strong>
                        {{
                            number_format(
                                $hubSpotOnlyResults
                                    ->total(),
                                0,
                                ',',
                                '.'
                            )
                        }}
                    </strong>

                </div>


                <div class="rf-pagination-buttons">

                    <button
                        type="button"
                        wire:click="
                            previousPage(
                                'hubspotPage'
                            )
                        "
                        @disabled(
                            $hubSpotOnlyResults
                                ->onFirstPage()
                        )
                        class="rf-page-btn"
                    >
                        ‹
                    </button>

                    <span class="rf-pagination-info">
                        Página
                        <strong>
                            {{
                                $hubSpotOnlyResults
                                    ->currentPage()
                            }}
                        </strong>
                        de
                        <strong>
                            {{
                                $hubSpotOnlyResults
                                    ->lastPage()
                            }}
                        </strong>
                    </span>

                    <button
                        type="button"
                        wire:click="
                            nextPage(
                                'hubspotPage'
                            )
                        "
                        @disabled(
                            ! $hubSpotOnlyResults
                                ->hasMorePages()
                        )
                        class="rf-page-btn"
                    >
                        ›
                    </button>

                </div>

            </div>

        @endif

    </section>

@endif
