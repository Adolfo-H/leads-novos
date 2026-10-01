@php
    $hubSpotOnlyResults =
        $this->hubSpotOnlyLeads;
@endphp


@if (
    $hubSpotOnly
    || $linkingHubSpotCompanyId !== null
    || $hubSpotOnlyResults->total() > 0
)


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


        @include(
            'partials.hubspot-company-linker'
        )


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

                    $hubSpotActivities =
                        $hubSpotCompany
                            ->activities
                            ->where(
                                'is_deleted',
                                false
                            )
                            ->values();

                    $lastHubSpotActivity =
                        $hubSpotActivities
                            ->sortByDesc(
                                'occurred_at'
                            )
                            ->first();

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
                                color:var(--ec-text);
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
                            contato(s)
                            ·
                            {{
                                $hubSpotActivities
                                    ->count()
                            }}
                            atividade(s)
                        </div>

                        @if ($lastHubSpotActivity)

                            <div class="rf-crm-only-helper">
                                Última atividade:

                                {{
                                    $lastHubSpotActivity
                                        ->occurred_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}
                            </div>

                        @endif

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


                        @if (
                            $this->isCommercialManager()
                        )

                            <button
                                type="button"
                                wire:click="
                                    openCompanyLink(
                                        {{
                                            $hubSpotCompany
                                                ->id
                                        }}
                                    )
                                "
                                class="
                                    rf-btn
                                    rf-btn-warning
                                "
                                style="
                                    margin-top:6px;
                                    width:100%;
                                "
                            >
                                Identificar empresa
                            </button>

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
