    {{-- INTELIGÊNCIA COMERCIAL --}}
    <section
        x-show="
            dossierTab
            === 'commercial'
        "
        x-cloak
        x-transition.opacity.duration.120ms
        class="ec-dossier-tab-panel ec-commercial-v2"
    >

        <div class="ec-commercial-intro-layout">
        <div class="ec-section-heading">

            <div>

                <h2 class="ec-section-title">
                    Inteligência comercial
                </h2>

                <p class="ec-section-description">
                    Situação atual da empresa dentro do processo de prospecção.
                </p>

            </div>

            <span class="ec-section-hint">
                Enriquecimento automático nas próximas etapas
            </span>

        </div>


        @php
            $researchStatus =
                $export?->research_status
                ?? 'idle';

            $researchRunning =
                in_array(
                    $researchStatus,
                    [
                        'queued',
                        'processing',
                    ],
                    true
                );

            $researchConfigured =
                $this
                    ->exportResearchConfigured();

            $researchEligibility =
                $this
                    ->exportResearchEligibility;

            $evidenceCount =
                $company
                    ->exportEvidence
                    ->count();
        @endphp


        {{-- PESQUISA DE EXPORTAÇÃO --}}
        <div
            @if ($researchRunning)
                wire:poll.2s="refreshExportResearch"
            @endif
            class="
                mb-5 overflow-hidden
                rounded-2xl
                border border-white/[0.07]
                bg-[var(--ec-surface-soft)]
            "
        >

            <div
                class="
                    flex flex-col gap-4
                    px-5 py-4
                    lg:flex-row
                    lg:items-center
                    lg:justify-between
                "
            >

                <div
                    class="
                        flex min-w-0
                        items-center gap-4
                    "
                >

                    <div
                        class="
                            flex size-11
                            shrink-0
                            items-center
                            justify-center
                            rounded-xl
                            border
                            border-cyan-300/10
                            bg-cyan-300/[0.06]
                            text-cyan-300
                        "
                    >

                        @if ($researchRunning)

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                class="
                                    size-5
                                    animate-spin
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

                        @elseif (
                            $researchStatus
                            === 'completed'
                        )

                            <svg
                                xmlns="
                                    http://www.w3.org/2000/svg
                                "
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                class="
                                    size-5
                                    text-emerald-300
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

                        @else

                            <svg
                                xmlns="
                                    http://www.w3.org/2000/svg
                                "
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                class="size-5"
                            >
                                <circle
                                    cx="11"
                                    cy="11"
                                    r="7"
                                />

                                <path
                                    d="m20 20-4-4"
                                />
                            </svg>

                        @endif

                    </div>


                    <div class="min-w-0">

                        <div
                            class="
                                text-[11px]
                                font-semibold
                                uppercase
                                tracking-[0.16em]
                                text-[var(--ec-text-muted)]
                            "
                        >
                            Pesquisa de exportação
                        </div>


                        @if ($researchRunning)

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                Pesquisando fontes públicas...
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                O processamento está sendo
                                executado em segundo plano.
                            </div>

                        @elseif (
                            $researchStatus
                            === 'completed'
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-emerald-300
                                "
                            >
                                Pesquisa concluída
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                {{ $evidenceCount }}
                                evidência(s) pública(s)
                                registrada(s).
                            </div>

                        @elseif (
                            $researchStatus
                            === 'failed'
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-rose-300
                                "
                            >
                                Falha na pesquisa
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                {{
                                    $export
                                        ?->research_error
                                    ?: 'Não foi possível concluir.'
                                }}
                            </div>

                        @elseif (! $researchConfigured)

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                Pesquisa pública desativada
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                Nenhuma API externa será
                                utilizada até você ativar
                                um provider.
                            </div>

                        @elseif (
                            ! $researchEligibility[
                                'eligible'
                            ]
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                {{
                                    in_array(
                                        $researchEligibility[
                                            'reason'
                                        ],
                                        [
                                            'crm_client',
                                            'crm_opportunity',
                                        ],
                                        true
                                    )
                                        ? 'Pesquisa de exportação disponível'
                                        : 'Pesquisa automática indisponível'
                                }}
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                {{
                                    in_array(
                                        $researchEligibility[
                                            'reason'
                                        ],
                                        [
                                            'crm_client',
                                            'crm_opportunity',
                                        ],
                                        true
                                    )
                                        ? 'Não necessária nesta etapa porque a empresa já está sendo trabalhada no CRM.'
                                        : $researchEligibility[
                                            'message'
                                        ]
                                }}
                            </div>

                        @else

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                Empresa elegível para pesquisa
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                O Prospector pesquisará
                                exportação direta, indireta
                                e relação com tradings.
                            </div>

                        @endif

                    </div>

                </div>


                <div
                    class="
                        flex shrink-0
                        items-center gap-3
                    "
                >

                    @if ($researchConfigured)

                        <span
                            class="
                                rounded-full
                                border border-emerald-400/15
                                bg-emerald-400/[0.06]
                                px-3 py-1.5
                                text-xs
                                font-medium
                                text-emerald-300
                            "
                        >
                            {{
                                $this
                                    ->exportResearchProviderLabel()
                            }}
                            ativo
                        </span>

                    @else

                        <span
                            class="
                                rounded-full
                                border border-[var(--ec-border-soft)]
                                bg-[var(--ec-surface-soft)]
                                px-3 py-1.5
                                text-xs
                                font-medium
                                text-[var(--ec-text-muted)]
                            "
                        >
                            Provider desativado
                        </span>

                    @endif


                    @if (
                        $researchConfigured
                        && ! $researchRunning
                    )

                        @if (
                            $researchStatus
                            === 'completed'
                        )

                            <button
                                type="button"
                                wire:click="
                                    researchExportsForce
                                "
                                wire:loading.attr="
                                    disabled
                                "
                                wire:target="
                                    researchExportsForce
                                "
                                class="
                                    ec-button-secondary
                                "
                                title="
                                    Executa novamente
                                    3 buscas no Tavily
                                "
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Pesquisar novamente
                                </span>

                                <span
                                    wire:loading
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Enviando...
                                </span>
                            </button>

                        @elseif (
                            ! $researchEligibility[
                                'eligible'
                            ]
                        )

                            <button
                                type="button"
                                wire:click="
                                    researchExportsForce
                                "
                                wire:loading.attr="
                                    disabled
                                "
                                wire:target="
                                    researchExportsForce
                                "
                                class="
                                    ec-button-secondary
                                "
                                title="
                                    Ignora a peneira automática
                                    e executa 3 buscas no Tavily
                                "
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Pesquisar mesmo assim
                                </span>

                                <span
                                    wire:loading
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Enviando...
                                </span>
                            </button>

                        @else

                            <button
                                type="button"
                                wire:click="
                                    researchExports
                                "
                                wire:loading.attr="
                                    disabled
                                "
                                wire:target="
                                    researchExports
                                "
                                class="
                                    ec-button-primary
                                "
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="
                                        researchExports
                                    "
                                >
                                    Pesquisar exportações
                                </span>

                                <span
                                    wire:loading
                                    wire:target="
                                        researchExports
                                    "
                                >
                                    Enviando...
                                </span>
                            </button>

                        @endif

                    @endif

                </div>

            </div>

        </div>


        </div> {{-- fecha faixa inteligência/pesquisa --}}

        @error('exportResearch')

            <div
                class="
                    mb-5 rounded-xl
                    border border-amber-400/15
                    bg-amber-400/[0.06]
                    px-4 py-3
                    text-sm
                    text-amber-200
                "
            >
                {{ $message }}
            </div>

        @enderror


        <div class="ec-intelligence-grid">

            {{-- CRM --}}

            @php
                $crm = $company->crmCheck;

                $crmStatusLabel = match ($crm?->status) {
                    'client' => 'Cliente',
                    'opportunity' => 'Oportunidade',
                    'prospected' => 'Prospectado',
                    'known' => 'Conhecido',
                    'not_found' => 'Não encontrado',
                    default => 'Não verificado',
                };

                $crmStatusClasses = match ($crm?->status) {
                    'client' =>
                        'bg-emerald-500/15 text-emerald-300',

                    'opportunity' =>
                        'bg-amber-500/15 text-amber-300',

                    'prospected' =>
                        'bg-sky-500/15 text-sky-300',

                    'known' =>
                        'bg-violet-500/15 text-violet-300',

                    'not_found' =>
                        'bg-[var(--ec-surface-soft)] text-[var(--ec-text-soft)]',

                    default =>
                        'bg-[var(--ec-surface-soft)] text-[var(--ec-text-muted)]',
                };

                $crmCaption = match ($crm?->status) {
                    'client' =>
                        'Já é cliente no CRM',

                    'opportunity' =>
                        'Já possui oportunidade comercial',

                    'prospected' =>
                        $crmReprospecting
                            ? (
                                $crmReprospecting[
                                    'eligible'
                                ]
                                    ? 'Reprospecção liberada'
                                    : 'Aguardando reprospecção'
                            )
                            : 'Já houve contato comercial',

                    'known' =>
                        'Registro localizado no CRM',

                    'not_found' =>
                        'Não localizado no HubSpot',

                    default =>
                        'Base comercial',
                };
            @endphp

            @php
                $hubSpotOpportunity =
                    $this
                        ->hubSpotManualOpportunityState;
            @endphp

            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        CRM
                    </span>

                    @if ($crm)

                        <span
                            class="
                                rounded-full px-2 py-1
                                text-[10px] font-bold
                                uppercase tracking-wide
                                {{ $crmStatusClasses }}
                            "
                        >
                            {{ $crmStatusLabel }}
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                <div class="ec-intelligence-value">
                    {{ $crmStatusLabel }}
                </div>

                <div class="ec-intelligence-caption">
                    {{ $crmCaption }}
                </div>

                @if (
                    $crm?->status
                        === 'prospected'
                    && $crmReprospecting
                )

                    <div
                        class="
                            mt-2 text-[11px]
                            font-medium
                            {{
                                $crmReprospecting[
                                    'eligible'
                                ]
                                    ? 'text-emerald-300'
                                    : 'text-amber-300'
                            }}
                        "
                    >

                        @if (
                            $crmReprospecting[
                                'eligible'
                            ]
                        )

                            Reprospecção liberada

                        @elseif (
                            $crmReprospecting[
                                'next_allowed_at'
                            ]
                        )

                            Reprospecção a partir de

                            {{
                                $this
                                    ->formatReprospectingDate(
                                        $crmReprospecting[
                                            'next_allowed_at'
                                        ]
                                    )
                            }}

                        @else

                            Reprospecção depende
                            de revisão manual

                        @endif

                    </div>

                @endif

                @if (
                    $crm?->matched_value
                    || $crm?->external_domain
                )

                    <div
                        class="
                            mt-2 truncate text-[11px]
                            text-[var(--ec-text-muted)]
                        "
                        title="{{
                            $crm->matched_value
                            ?? $crm->external_domain
                        }}"
                    >
                        {{
                            $crm->matched_value
                            ?? $crm->external_domain
                        }}
                    </div>

                @endif


                @if (
                    $hubSpotOpportunity[
                        'visible'
                    ]
                )

                    {{--
                        Este polling existe antes mesmo
                        do clique.

                        Assim, depois que a criacao entra
                        na fila em background, a tela
                        percebe queued/processing sem F5.

                        Ele desaparece automaticamente
                        depois da conclusao.
                    --}}
                    @if (
                        $hubSpotOpportunity[
                            'can_queue'
                        ]
                        || $hubSpotOpportunity[
                            'active'
                        ]
                    )

                        <span
                            class="hidden"
                            aria-hidden="true"
                            wire:poll.1s="
                                refreshHubSpotOpportunity
                            "
                        ></span>

                    @endif

                    <div
                        @if (
                            $hubSpotOpportunity[
                                'active'
                            ]
                        )
                            wire:poll.1s="
                                refreshHubSpotOpportunity
                            "
                        @endif
                        class="
                            mt-4 rounded-xl
                            border border-[var(--ec-border-soft)]
                            bg-[var(--ec-surface-soft)]
                            p-3
                        "
                    >

                        <div
                            class="
                                flex items-start
                                justify-between
                                gap-3
                            "
                        >

                            <div class="min-w-0">

                                <div
                                    class="
                                        text-[11px]
                                        font-semibold
                                        uppercase
                                        tracking-[0.08em]
                                        text-[var(--ec-text-muted)]
                                    "
                                >
                                    Integração HubSpot
                                </div>

                                <div
                                    class="
                                        mt-1 text-sm
                                        font-semibold
                                        text-[var(--ec-text)]
                                    "
                                >
                                    {{
                                        $hubSpotOpportunity[
                                            'label'
                                        ]
                                    }}
                                </div>

                            </div>


                            @if (
                                $hubSpotOpportunity[
                                    'active'
                                ]
                            )

                                <span
                                    class="
                                        inline-flex
                                        shrink-0
                                        items-center
                                        gap-1.5
                                        rounded-full
                                        bg-cyan-500/10
                                        px-2 py-1
                                        text-[10px]
                                        font-bold
                                        uppercase
                                        text-cyan-300
                                    "
                                >
                                    <span
                                        class="
                                            size-1.5
                                            animate-pulse
                                            rounded-full
                                            bg-current
                                        "
                                    ></span>

                                    Processando
                                </span>

                            @elseif (
                                $hubSpotOpportunity[
                                    'status'
                                ]
                                === 'completed'
                            )

                                <span
                                    class="
                                        shrink-0
                                        rounded-full
                                        bg-emerald-500/10
                                        px-2 py-1
                                        text-[10px]
                                        font-bold
                                        uppercase
                                        text-emerald-300
                                    "
                                >
                                    Concluído
                                </span>

                            @elseif (
                                $hubSpotOpportunity[
                                    'status'
                                ]
                                === 'failed'
                            )

                                <span
                                    class="
                                        shrink-0
                                        rounded-full
                                        bg-rose-500/10
                                        px-2 py-1
                                        text-[10px]
                                        font-bold
                                        uppercase
                                        text-rose-300
                                    "
                                >
                                    Erro
                                </span>

                            @elseif (
                                $hubSpotOpportunity[
                                    'status'
                                ]
                                === 'partial'
                            )

                                <span
                                    class="
                                        shrink-0
                                        rounded-full
                                        bg-amber-500/10
                                        px-2 py-1
                                        text-[10px]
                                        font-bold
                                        uppercase
                                        text-amber-300
                                    "
                                >
                                    Incompleto
                                </span>

                            @endif

                        </div>


                        <p
                            class="
                                mt-2 text-xs
                                leading-5
                                text-[var(--ec-text-muted)]
                            "
                        >
                            {{
                                $hubSpotOpportunity[
                                    'message'
                                ]
                            }}
                        </p>



                        @if (
                            $hubSpotOpportunity[
                                'active'
                            ]
                        )

                            <div
                                data-testid="hubspot-inline-progress"
                                class="
                                    mt-3 rounded-lg
                                    border
                                    border-cyan-400/10
                                    bg-cyan-400/[0.04]
                                    p-3
                                "
                            >

                                <div
                                    class="
                                        flex items-center
                                        justify-between
                                        gap-3
                                        text-[11px]
                                    "
                                >
                                    <span
                                        class="
                                            font-medium
                                            text-cyan-200
                                        "
                                    >
                                        {{
                                            $hubSpotOpportunity[
                                                'progress_message'
                                            ]
                                            ?? 'Processando...'
                                        }}
                                    </span>

                                    <strong
                                        class="
                                            tabular-nums
                                            text-cyan-300
                                        "
                                    >
                                        {{
                                            $hubSpotOpportunity[
                                                'progress'
                                            ]
                                        }}%
                                    </strong>
                                </div>

                                <div
                                    class="
                                        mt-2 h-1.5
                                        overflow-hidden
                                        rounded-full
                                        bg-white/5
                                    "
                                >
                                    <div
                                        class="
                                            h-full
                                            rounded-full
                                            bg-cyan-400
                                            transition-all
                                            duration-300
                                        "
                                        style="
                                            width:
                                            {{
                                                $hubSpotOpportunity[
                                                    'progress'
                                                ]
                                            }}%;
                                        "
                                    ></div>
                                </div>

                            </div>

                        @endif


                        @if (
                            $hubSpotOpportunity[
                                'owner_email'
                            ]
                        )

                            <div
                                class="
                                    mt-2 truncate
                                    text-[11px]
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                Responsável:
                                {{
                                    $hubSpotOpportunity[
                                        'owner_email'
                                    ]
                                }}
                            </div>

                        @endif


                        @if (
                            $hubSpotOpportunity[
                                'error'
                            ]
                        )

                            <div
                                class="
                                    mt-3 rounded-lg
                                    border border-rose-400/15
                                    bg-rose-400/[0.06]
                                    px-3 py-2
                                    text-xs
                                    leading-5
                                    text-rose-300
                                "
                            >
                                {{
                                    $hubSpotOpportunity[
                                        'error'
                                    ]
                                }}
                            </div>

                        @endif


                        @error(
                            'hubSpotOpportunity'
                        )

                            <div
                                class="
                                    mt-3 rounded-lg
                                    border border-rose-400/15
                                    bg-rose-400/[0.06]
                                    px-3 py-2
                                    text-xs
                                    leading-5
                                    text-rose-300
                                "
                            >
                                {{ $message }}
                            </div>

                        @enderror


                        @if (
                            $hubSpotOpportunity[
                                'status'
                            ]
                            === 'completed'
                        )

                            <div
                                class="
                                    mt-3 flex
                                    flex-wrap gap-2
                                "
                            >

                                @if (
                                    $this
                                        ->hubSpotCompanyUrl()
                                )

                                    <a
                                        href="{{
                                            $this
                                                ->hubSpotCompanyUrl()
                                        }}"
                                        target="_blank"
                                        rel="
                                            noopener noreferrer
                                        "
                                        class="
                                            ec-button-secondary
                                        "
                                    >
                                        Abrir empresa

                                        <span
                                            aria-hidden="true"
                                        >
                                            ↗
                                        </span>
                                    </a>

                                @endif


                                @if (
                                    $this
                                        ->hubSpotDealUrl()
                                )

                                    <a
                                        href="{{
                                            $this
                                                ->hubSpotDealUrl()
                                        }}"
                                        target="_blank"
                                        rel="
                                            noopener noreferrer
                                        "
                                        class="
                                            ec-button-primary
                                        "
                                    >
                                        Abrir negócio

                                        <span
                                            aria-hidden="true"
                                        >
                                            ↗
                                        </span>
                                    </a>

                                @endif

                            </div>


                        @elseif (
                            $hubSpotOpportunity[
                                'can_queue'
                            ]
                        )

                            @can(
                                'createHubSpotOpportunity',
                                $company
                            )

                                <div class="mt-3">

                                    <button
                                        type="button"
                                        wire:click="
                                            createHubSpotOpportunity
                                        "
                                        wire:loading.attr="
                                            disabled
                                        "
                                        wire:target="
                                            createHubSpotOpportunity
                                        "
                                        wire:confirm="
                                            Deseja criar a empresa,
                                            contatos, negócio e a
                                            tarefa de primeiro contato
                                            no HubSpot?
                                        "
                                        class="
                                            ec-button-primary
                                        "
                                    >

                                        <span
                                            wire:loading.remove
                                            wire:target="
                                                createHubSpotOpportunity
                                            "
                                        >
                                            {{
                                                in_array(
                                                    $hubSpotOpportunity[
                                                        'status'
                                                    ],
                                                    [
                                                        'failed',
                                                        'partial',
                                                    ],
                                                    true
                                                )
                                                    ? 'Tentar novamente'
                                                    : 'Criar oportunidade no HubSpot'
                                            }}
                                        </span>

                                        <span
                                            wire:loading
                                            wire:target="
                                                createHubSpotOpportunity
                                            "
                                        >
                                            Enviando...
                                        </span>

                                    </button>

                                </div>

                            @endcan

                        @endif

                    </div>

                @endif

            </div>




            <div
                wire:loading.flex
                wire:target="createHubSpotOpportunity"
                data-testid="hubspot-starting-overlay"
                class="
                    fixed inset-0
                    z-[110]
                    items-center
                    justify-center
                    bg-black/65
                    px-4
                    backdrop-blur-sm
                "
            >
                <div
                    class="
                        w-full max-w-md
                        rounded-2xl
                        border
                        border-[var(--ec-border)]
                        bg-[var(--ec-surface)]
                        p-6
                        text-center
                        shadow-2xl
                    "
                >
                    <div
                        class="
                            mx-auto size-8
                            animate-spin
                            rounded-full
                            border-2
                            border-cyan-400/20
                            border-t-cyan-300
                        "
                    ></div>

                    <div
                        class="
                            mt-4 text-base
                            font-semibold
                            text-[var(--ec-text)]
                        "
                    >
                        Preparando oportunidade no HubSpot
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            text-[var(--ec-text-muted)]
                        "
                    >
                        Enviando para a fila de processamento...
                    </div>
                </div>
            </div>


            @if (
                $this
                    ->showHubSpotOpportunityProgress
            )

                @php
                    $hubSpotProgress =
                        $hubSpotOpportunity[
                            'progress'
                        ];

                    $hubSpotProgressSteps = [
                        5 =>
                            'Enviado para fila',

                        15 =>
                            'Responsável validado',

                        30 =>
                            'Empresa criada',

                        50 =>
                            'Contatos sincronizados',

                        70 =>
                            'Negócio criado',

                        85 =>
                            'Tarefa criada',

                        95 =>
                            'Atualizando CRM',

                        100 =>
                            'Concluído',
                    ];
                @endphp

                <div
                    @if (
                        $hubSpotOpportunity[
                            'active'
                        ]
                    )
                        wire:poll.1s="
                            refreshHubSpotOpportunity
                        "
                    @endif
                    data-testid="hubspot-progress-modal"
                    class="
                        fixed inset-0
                        z-[100]
                        flex items-center
                        justify-center
                        bg-black/65
                        px-4
                        backdrop-blur-sm
                    "
                >

                    <div
                        class="
                            w-full max-w-xl
                            rounded-2xl
                            border
                            border-[var(--ec-border)]
                            bg-[var(--ec-surface)]
                            p-6
                            shadow-2xl
                        "
                    >

                        <div
                            class="
                                flex
                                items-start
                                justify-between
                                gap-4
                            "
                        >

                            <div>

                                <div
                                    class="
                                        text-xs
                                        font-semibold
                                        uppercase
                                        tracking-[0.12em]
                                        text-[var(--ec-primary)]
                                    "
                                >
                                    Integração HubSpot
                                </div>

                                <h3
                                    class="
                                        mt-1
                                        text-lg
                                        font-bold
                                        text-[var(--ec-text)]
                                    "
                                >
                                    Criando oportunidade no HubSpot
                                </h3>

                            </div>

                            <div
                                class="
                                    text-3xl
                                    font-bold
                                    tabular-nums
                                    text-[var(--ec-primary)]
                                "
                            >
                                {{
                                    $hubSpotProgress
                                }}%
                            </div>

                        </div>


                        <div
                            class="
                                mt-5 h-3
                                overflow-hidden
                                rounded-full
                                bg-[var(--ec-surface-soft)]
                            "
                        >
                            <div
                                class="
                                    h-full
                                    rounded-full
                                    bg-[var(--ec-primary)]
                                    transition-all
                                    duration-500
                                "
                                style="
                                    width:
                                    {{ $hubSpotProgress }}%;
                                "
                            ></div>
                        </div>


                        <div
                            class="
                                mt-3
                                min-h-5
                                text-sm
                                font-medium
                                text-[var(--ec-text-soft)]
                            "
                        >
                            {{
                                $hubSpotOpportunity[
                                    'progress_message'
                                ]
                                ?? $hubSpotOpportunity[
                                    'message'
                                ]
                            }}
                        </div>


                        <div
                            class="
                                mt-5 grid
                                gap-2
                                sm:grid-cols-2
                            "
                        >

                            @foreach (
                                $hubSpotProgressSteps
                                as $requiredProgress
                                    => $stepLabel
                            )

                                @php
                                    $stepDone =
                                        $hubSpotProgress
                                        >=
                                        $requiredProgress;
                                @endphp

                                <div
                                    class="
                                        flex
                                        items-center
                                        gap-2
                                        rounded-lg
                                        border
                                        px-3 py-2
                                        text-xs
                                        {{
                                            $stepDone
                                                ? 'border-emerald-400/15 bg-emerald-400/[0.06] text-emerald-300'
                                                : 'border-[var(--ec-border-soft)] bg-[var(--ec-surface-soft)] text-[var(--ec-text-muted)]'
                                        }}
                                    "
                                >

                                    <span
                                        class="
                                            inline-flex
                                            size-5
                                            shrink-0
                                            items-center
                                            justify-center
                                            rounded-full
                                        "
                                    >
                                        {{
                                            $stepDone
                                                ? '✓'
                                                : '•'
                                        }}
                                    </span>

                                    <span>
                                        {{ $stepLabel }}
                                    </span>

                                </div>

                            @endforeach

                        </div>


                        @if (
                            $hubSpotOpportunity[
                                'error'
                            ]
                        )

                            <div
                                class="
                                    mt-5
                                    rounded-xl
                                    border
                                    border-rose-400/20
                                    bg-rose-400/[0.07]
                                    px-4 py-3
                                    text-sm
                                    text-rose-300
                                "
                            >
                                {{
                                    $hubSpotOpportunity[
                                        'error'
                                    ]
                                }}
                            </div>

                        @endif


                        <div
                            class="
                                mt-6 flex
                                flex-wrap
                                justify-end
                                gap-2
                            "
                        >

                            @if (
                                $hubSpotOpportunity[
                                    'status'
                                ]
                                === 'completed'
                            )

                                @if (
                                    $this
                                        ->hubSpotCompanyUrl()
                                )

                                    <a
                                        href="{{
                                            $this
                                                ->hubSpotCompanyUrl()
                                        }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="ec-button-secondary"
                                    >
                                        Abrir empresa ↗
                                    </a>

                                @endif


                                @if (
                                    $this
                                        ->hubSpotDealUrl()
                                )

                                    <a
                                        href="{{
                                            $this
                                                ->hubSpotDealUrl()
                                        }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="ec-button-secondary"
                                    >
                                        Abrir negócio ↗
                                    </a>

                                @endif


                                <button
                                    type="button"
                                    wire:click="
                                        closeHubSpotOpportunityProgress
                                    "
                                    class="ec-button-primary"
                                >
                                    Concluir
                                </button>

                            @elseif (
                                in_array(
                                    $hubSpotOpportunity[
                                        'status'
                                    ],
                                    [
                                        'failed',
                                        'partial',
                                    ],
                                    true
                                )
                            )

                                <button
                                    type="button"
                                    wire:click="
                                        closeHubSpotOpportunityProgress
                                    "
                                    class="ec-button-secondary"
                                >
                                    Fechar
                                </button>

                            @else

                                <div
                                    class="
                                        inline-flex
                                        items-center
                                        gap-2
                                        text-xs
                                        text-[var(--ec-text-muted)]
                                    "
                                >
                                    <span
                                        class="
                                            size-2
                                            animate-pulse
                                            rounded-full
                                            bg-[var(--ec-primary)]
                                        "
                                    ></span>

                                    Aguarde a conclusão
                                    do processo
                                </div>

                            @endif

                        </div>

                    </div>

                </div>

            @endif


            {{-- ICP --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        ICP
                    </span>

                    @if ($company->icpScore)

                        <span
                            class="
                                inline-flex size-7 items-center
                                justify-center rounded-full
                                text-xs font-bold
                                {{ match ($company->icpScore->grade) {
                                    'A' => 'bg-emerald-500/15 text-emerald-300',
                                    'B' => 'bg-sky-500/15 text-sky-300',
                                    'C' => 'bg-amber-500/15 text-amber-300',
                                    default => 'bg-rose-500/15 text-rose-300',
                                } }}
                            "
                        >
                            {{ $company->icpScore->grade }}
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                @if ($company->icpScore)

                    <div class="ec-intelligence-value">
                        {{ $company->icpScore->score }}/100
                    </div>

                    <div class="ec-intelligence-caption">
                        {{ $company->icpScore->label }}
                    </div>

                @else

                    <div class="ec-intelligence-value">
                        Não calculado
                    </div>

                    <div class="ec-intelligence-caption">
                        Perfil ideal
                    </div>

                @endif

            </div>


            {{-- EXPORTAÇÃO --}}
            @php
                $exportResearchSummary =
                    $this->exportResearchSummary;

                $exportAssessment =
                    is_array(
                        $exportResearchSummary
                    )
                        ? data_get(
                            $exportResearchSummary,
                            'assessment'
                        )
                        : null;

                $exportAssessmentStatus =
                    is_array(
                        $exportAssessment
                    )
                        ? (
                            $exportAssessment[
                                'status'
                            ]
                            ?? null
                        )
                        : null;

                $exportCardLabel =
                    is_array(
                        $exportAssessment
                    )
                        ? (
                            $exportAssessment[
                                'label'
                            ]
                            ?? 'Pesquisa concluída'
                        )
                        : match (
                            $export
                                ?->research_status
                        ) {
                            'completed' =>
                                'Pesquisa concluída',

                            'queued' =>
                                'Pesquisa na fila',

                            'processing' =>
                                'Pesquisa em andamento',

                            'failed' =>
                                'Pesquisa com erro',

                            default =>
                                'Não pesquisada',
                        };

                $exportCardClasses =
                    match (
                        $exportAssessmentStatus
                    ) {
                        'identified' =>
                            'bg-emerald-500/15 text-emerald-300',

                        'indications' =>
                            'bg-cyan-500/15 text-cyan-300',

                        'inconclusive' =>
                            'bg-amber-500/15 text-amber-300',

                        'not_supported' =>
                            'bg-rose-500/15 text-rose-300',

                        default =>
                            'bg-[var(--ec-surface-soft)] text-[var(--ec-text-soft)]',
                    };

                $exportModalities =
                    is_array(
                        $exportAssessment
                    )
                    && is_array(
                        $exportAssessment[
                            'modalities'
                        ]
                        ?? null
                    )
                        ? $exportAssessment[
                            'modalities'
                        ]
                        : [];

                $exportEvidenceCount =
                    $company
                        ->exportEvidence
                        ->count();

                $exportCardCaption =
                    $exportModalities !== []
                        ? implode(
                            ' · ',
                            $exportModalities
                        )
                        : (
                            $exportEvidenceCount > 0
                                ? $exportEvidenceCount
                                    .' fonte(s) analisada(s)'
                                : 'Pesquisa pública de exportação'
                        );
            @endphp

            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Exportação
                    </span>

                    <span
                        class="
                            rounded-full
                            px-2 py-1
                            text-[10px]
                            font-bold uppercase
                            {{ $exportCardClasses }}
                        "
                    >
                        {{
                            $export
                                ?->research_status
                                === 'completed'
                                    ? 'Analisada'
                                    : (
                                        $export
                                            ?->research_status
                                            === 'processing'
                                            ? 'Analisando'
                                            : (
                                                $export
                                                    ?->research_status
                                                    === 'queued'
                                                    ? 'Na fila'
                                                    : 'Pendente'
                                            )
                                    )
                        }}
                    </span>

                </div>

                <div class="ec-intelligence-value">
                    {{ $exportCardLabel }}
                </div>

                <div class="ec-intelligence-caption">
                    {{ $exportCardCaption }}
                </div>

            </div>


            {{-- SCORE --}}
            @php
                $sdr =
                    $company->sdrScore;
            @endphp

            <div class="ec-intelligence-card ec-intelligence-score">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Score
                    </span>

                    @if ($sdr)

                        <span
                            class="
                                rounded-full
                                px-2 py-1
                                text-[10px]
                                font-bold
                                uppercase
                                {{
                                    match ($sdr->priority) {
                                        'very_high' =>
                                            'bg-emerald-500/15 text-emerald-300',

                                        'high' =>
                                            'bg-cyan-500/15 text-cyan-300',

                                        'medium' =>
                                            'bg-amber-500/15 text-amber-300',

                                        'blocked' =>
                                            'bg-rose-500/15 text-rose-300',

                                        default =>
                                            'bg-[var(--ec-surface-soft)] text-[#8e97b8]',
                                    }
                                }}
                            "
                        >
                            @if (! $sdr->is_eligible)
                                Bloqueado
                            @elseif ($sdr->is_provisional)
                                Provisório
                            @else
                                SDR
                            @endif
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                @if (
                    $sdr
                    && ! $sdr->is_eligible
                )

                    <div
                        class="
                            mt-3
                            text-base
                            font-bold
                            text-rose-300
                        "
                    >
                        Não priorizar
                    </div>

                    <div class="ec-intelligence-caption">
                        {{
                            $sdr->blocked_reason
                            ?: 'Bloqueio comercial'
                        }}
                    </div>

                @elseif ($sdr)

                    <div class="ec-score-value">
                        {{ $sdr->score }}/100
                    </div>

                    <div class="ec-intelligence-caption">
                        {{ $sdr->label }}

                        @if ($sdr->is_provisional)
                            · Provisório
                        @endif
                    </div>

                @else

                    <div class="ec-score-value">
                        —
                    </div>

                    <div class="ec-intelligence-caption">
                        Prioridade SDR
                    </div>

                @endif

            </div>

        </div>

        <div class="ec-commercial-workspace">
            <div class="ec-commercial-history">
        @include(
            'partials.company-commercial-timeline',
            [
                'company' => $company,
            ]
        )
            </div>
            <div class="ec-commercial-extras">


        {{-- RESUMO DA PESQUISA DE EXPORTAÇÃO --}}
        @php
            $researchSummary =
                $this->exportResearchSummary;
        @endphp

        @if ($researchSummary)

            <div
                class="
                    mb-5 overflow-hidden
                    rounded-2xl
                    border border-cyan-300/10
                    bg-cyan-300/[0.025]
                "
            >

                <div
                    class="
                        border-b border-[var(--ec-border-soft)]
                        px-5 py-4
                    "
                >
                    <div
                        class="
                            text-[11px]
                            font-semibold uppercase
                            tracking-[0.16em]
                            text-cyan-300
                        "
                    >
                        Leitura comercial
                    </div>

                    <div
                        class="
                            mt-1 text-base
                            font-semibold
                            text-[var(--ec-text)]
                        "
                    >
                        Resumo da pesquisa de exportação
                    </div>

                    <p
                        class="
                            mt-2 max-w-4xl
                            text-sm leading-6
                            text-[var(--ec-text-soft)]
                        "
                    >
                        {{
                            $researchSummary[
                                'headline'
                            ]
                        }}
                    </p>
                </div>


                @php
                    $assessment =
                        data_get(
                            $researchSummary,
                            'assessment',
                            []
                        );

                    $assessmentLabel =
                        is_array(
                            $assessment
                        )
                            ? (
                                $assessment[
                                    'label'
                                ]
                                ?? 'Pesquisa concluída'
                            )
                            : 'Pesquisa concluída';

                    $modalities =
                        is_array(
                            $assessment
                        )
                        && is_array(
                            $assessment[
                                'modalities'
                            ]
                            ?? null
                        )
                            ? $assessment[
                                'modalities'
                            ]
                            : [];
                @endphp

                <div
                    class="
                        grid gap-3
                        px-5 py-4
                        md:grid-cols-3
                    "
                >

                    <div
                        class="
                            rounded-xl
                            border border-[var(--ec-border-soft)]
                            bg-[var(--ec-surface-soft)]
                            p-4
                        "
                    >
                        <div
                            class="
                                text-[10px]
                                font-semibold uppercase
                                tracking-[0.12em]
                                text-[var(--ec-text-muted)]
                            "
                        >
                            Resultado
                        </div>

                        <div
                            class="
                                mt-2 text-sm
                                font-semibold
                                text-[var(--ec-text)]
                            "
                        >
                            {{ $assessmentLabel }}
                        </div>
                    </div>


                    <div
                        class="
                            rounded-xl
                            border border-[var(--ec-border-soft)]
                            bg-[var(--ec-surface-soft)]
                            p-4
                        "
                    >
                        <div
                            class="
                                text-[10px]
                                font-semibold uppercase
                                tracking-[0.12em]
                                text-[var(--ec-text-muted)]
                            "
                        >
                            Modalidade identificada
                        </div>

                        <div
                            class="
                                mt-2 text-sm
                                font-semibold
                                text-[var(--ec-text)]
                            "
                        >
                            {{
                                $modalities !== []
                                    ? implode(
                                        ' · ',
                                        $modalities
                                    )
                                    : 'Não identificada'
                            }}
                        </div>
                    </div>


                    <div
                        class="
                            rounded-xl
                            border border-[var(--ec-border-soft)]
                            bg-[var(--ec-surface-soft)]
                            p-4
                        "
                    >
                        <div
                            class="
                                text-[10px]
                                font-semibold uppercase
                                tracking-[0.12em]
                                text-[var(--ec-text-muted)]
                            "
                        >
                            Fontes analisadas
                        </div>

                        <div
                            class="
                                mt-2 text-sm
                                font-semibold
                                text-[var(--ec-text)]
                            "
                        >
                            {{
                                $researchSummary[
                                    'evidence_count'
                                ]
                            }}
                        </div>
                    </div>

                </div>


                @if (
                    $researchSummary[
                        'products'
                    ] !== []
                    || $researchSummary[
                        'markets'
                    ] !== []
                )

                    <div
                        class="
                            grid gap-4
                            border-t border-[var(--ec-border-soft)]
                            px-5 py-4
                            md:grid-cols-2
                        "
                    >

                        @if (
                            $researchSummary[
                                'products'
                            ] !== []
                        )

                            <div>
                                <div
                                    class="
                                        text-[10px]
                                        font-semibold uppercase
                                        tracking-[0.12em]
                                        text-[var(--ec-text-muted)]
                                    "
                                >
                                    Produtos citados
                                </div>

                                <div
                                    class="
                                        mt-2 flex flex-wrap
                                        gap-2
                                    "
                                >
                                    @foreach (
                                        $researchSummary[
                                            'products'
                                        ] as $product
                                    )
                                        <span
                                            class="
                                                rounded-full
                                                border border-white/[0.07]
                                                bg-white/[0.035]
                                                px-2.5 py-1
                                                text-xs
                                                text-[var(--ec-text-soft)]
                                            "
                                        >
                                            {{ $product }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>

                        @endif


                        @if (
                            $researchSummary[
                                'markets'
                            ] !== []
                        )

                            <div>
                                <div
                                    class="
                                        text-[10px]
                                        font-semibold uppercase
                                        tracking-[0.12em]
                                        text-[var(--ec-text-muted)]
                                    "
                                >
                                    Mercados citados
                                </div>

                                <div
                                    class="
                                        mt-2 flex flex-wrap
                                        gap-2
                                    "
                                >
                                    @foreach (
                                        $researchSummary[
                                            'markets'
                                        ] as $market
                                    )
                                        <span
                                            class="
                                                rounded-full
                                                border border-white/[0.07]
                                                bg-white/[0.035]
                                                px-2.5 py-1
                                                text-xs
                                                text-[var(--ec-text-soft)]
                                            "
                                        >
                                            {{ $market }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>

                        @endif

                    </div>

                @endif


                <div
                    class="
                        flex flex-wrap gap-x-5 gap-y-2
                        border-t border-[var(--ec-border-soft)]
                        px-5 py-3
                        text-[11px]
                        text-[var(--ec-text-muted)]
                    "
                >
                    <span>
                        {{
                            $researchSummary[
                                'evidence_count'
                            ]
                        }} evidência(s)
                    </span>

                    <span>
                        {{
                            $researchSummary[
                                'positive_count'
                            ]
                        }} sinal(is) de exportação
                    </span>

                    <span>
                        {{
                            $researchSummary[
                                'neutral_count'
                            ]
                        }} fonte(s) contextual(is)
                    </span>

                    <span>
                        {{
                            $researchSummary[
                                'negative_count'
                            ]
                        }} sinal(is) contrário(s)
                    </span>
                </div>

            </div>

        @endif


        {{-- EVIDÊNCIAS DE EXPORTAÇÃO --}}
        @if (
            $company
                ->exportEvidence
                ->isNotEmpty()
        )

            <details
                class="
                    mt-4 overflow-hidden
                    rounded-xl
                    border border-white/5
                    bg-[var(--ec-surface-soft)]
                "
            >

                <summary
                    class="
                        flex cursor-pointer
                        list-none
                        items-center
                        justify-between
                        px-5 py-4
                        text-sm
                        font-semibold
                        text-[var(--ec-text-soft)]
                        transition
                        hover:bg-[var(--ec-surface-soft)]
                    "
                >

                    <span>
                        Evidências de exportação
                    </span>

                    <span
                        class="
                            text-xs
                            font-medium
                            text-[var(--ec-text-muted)]
                        "
                    >
                        {{
                            $company
                                ->exportEvidence
                                ->count()
                        }}
                        fonte(s)
                    </span>

                </summary>


                <div
                    class="
                        border-t border-white/5
                        p-5
                    "
                >

                    <div class="space-y-3">

                        @foreach (
                            $company
                                ->exportEvidence
                                ->sortByDesc(
                                    'created_at'
                                )
                            as $evidence
                        )

                            <div
                                class="
                                    rounded-xl
                                    border
                                    border-[var(--ec-border-soft)]
                                    bg-[var(--ec-surface-soft)]
                                    p-4
                                "
                            >

                                <div
                                    class="
                                        flex flex-col
                                        gap-3
                                        sm:flex-row
                                        sm:items-start
                                        sm:justify-between
                                    "
                                >

                                    <div class="min-w-0">

                                        <div
                                            class="
                                                flex flex-wrap
                                                items-center
                                                gap-2
                                            "
                                        >

                                            <span
                                                class="
                                                    rounded-full
                                                    bg-cyan-300/10
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-bold
                                                    uppercase
                                                    text-cyan-300
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->exportDimensionLabel(
                                                            $evidence
                                                                ->dimension
                                                        )
                                                }}
                                            </span>

                                            <span
                                                class="
                                                    text-xs
                                                    font-semibold
                                                    text-[var(--ec-text-soft)]
                                                "
                                            >
                                                {{
                                                    match (
                                                        $evidence
                                                            ->signal
                                                    ) {
                                                        'positive' =>
                                                            'Sinal relevante',

                                                        'negative' =>
                                                            'Sinal contrário',

                                                        default =>
                                                            'Fonte contextual',
                                                    }
                                                }}
                                            </span>

                                        </div>


                                        <div
                                            class="
                                                mt-2
                                                text-sm
                                                font-semibold
                                                text-[var(--ec-text)]
                                            "
                                        >
                                            {{
                                                $evidence
                                                    ->title
                                                ?: (
                                                    $evidence
                                                        ->source_name
                                                    ?: 'Evidência pública'
                                                )
                                            }}
                                        </div>


                                        <div
                                            class="
                                                mt-1
                                                text-xs
                                                leading-5
                                                text-[var(--ec-text-muted)]
                                            "
                                        >
                                            {{
                                                $evidence
                                                    ->evidence_text
                                            }}
                                        </div>


                                        <div
                                            class="
                                                mt-2
                                                text-[11px]
                                                text-[var(--ec-text-muted)]
                                            "
                                        >
                                            Fonte:

                                            {{
                                                $evidence
                                                    ->source_name
                                                ?: $evidence
                                                    ->source_type
                                            }}
                                        </div>

                                    </div>


                                    @if (
                                        $evidence
                                            ->source_url
                                    )

                                        <a
                                            href="{{
                                                $evidence
                                                    ->source_url
                                            }}"
                                            target="_blank"
                                            rel="
                                                noopener
                                                noreferrer
                                            "
                                            class="
                                                inline-flex
                                                shrink-0
                                                items-center
                                                gap-1
                                                text-xs
                                                font-semibold
                                                text-cyan-300
                                                hover:text-cyan-200
                                            "
                                        >
                                            Abrir fonte ↗
                                        </a>

                                    @endif

                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            </details>

        @endif


            </div> {{-- fim dos extras de exportação --}}
            <div class="ec-commercial-crm">
        @if ($company->crmCheck)

            @php
                $crm =
                    $company->crmCheck;

                $crmReprospecting =
                    $this->crmReprospecting;
            @endphp

            <details open
                class="
                    mt-4 overflow-hidden rounded-xl
                    border border-white/5
                    bg-[var(--ec-surface-soft)]
                "
            >

                <summary
                    class="
                        flex cursor-pointer list-none
                        items-center justify-between
                        px-5 py-4
                        text-sm font-semibold
                        text-[var(--ec-text-soft)]
                        transition
                        hover:bg-[var(--ec-surface-soft)]
                    "
                >
                    <span>
                        Detalhamento do CRM
                    </span>

                    <span
                        class="
                            text-xs font-medium
                            text-[var(--ec-text-muted)]
                        "
                    >
                        {{ $crmStatusLabel }}
                        •
                        {{ strtoupper($crm->provider) }}
                    </span>
                </summary>

                <div
                    class="
                        border-t border-white/5
                        px-5 py-5
                    "
                >

                    <div
                        class="
                            grid gap-4
                            sm:grid-cols-2
                            xl:grid-cols-4
                        "
                    >

                        <div>
                            <div class="ec-field-label">
                                Status comercial
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                {{ $crmStatusLabel }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Empresa no CRM
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                {{
                                    $crm->external_name
                                    ?: '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Encontrado por
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[var(--ec-text-soft)]
                                "
                            >
                                @if ($crm->matched_by)

                                    {{
                                        match (
                                            $crm->matched_by
                                        ) {
                                            'domain' =>
                                                'Domínio',

                                            'name' =>
                                                'Nome',

                                            default =>
                                                ucfirst(
                                                    $crm->matched_by
                                                ),
                                        }
                                    }}

                                    @if ($crm->matched_value)
                                        ·
                                        {{ $crm->matched_value }}
                                    @endif

                                @else
                                    —
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Lifecycle HubSpot (informativo)
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[var(--ec-text-soft)]
                                "
                            >
                                {{
                                    $crm->lifecycle_stage
                                    ?: '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Interações registradas
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                {{ $crm->contacted_count }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Negócios associados
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[var(--ec-text)]
                                "
                            >
                                {{
                                    $crm
                                        ->associated_deals_count
                                }}
                            </div>
                        </div>

                        @if (
                            $crm->status
                                === 'prospected'
                            && $crmReprospecting
                        )

                            <div>

                                <div class="ec-field-label">
                                    Última atividade considerada
                                </div>

                                <div
                                    class="
                                        mt-1 text-sm
                                        text-[var(--ec-text-soft)]
                                    "
                                >
                                    {{
                                        $this
                                            ->formatReprospectingDate(
                                                $crmReprospecting[
                                                    'last_activity_at'
                                                ]
                                            )
                                    }}
                                </div>

                            </div>


                            <div>

                                <div class="ec-field-label">
                                    Reprospecção
                                </div>

                                <div
                                    class="
                                        mt-1 text-sm
                                        font-semibold
                                        {{
                                            $crmReprospecting[
                                                'eligible'
                                            ]
                                                ? 'text-emerald-300'
                                                : 'text-amber-300'
                                        }}
                                    "
                                >

                                    @if (
                                        $crmReprospecting[
                                            'eligible'
                                        ]
                                    )

                                        Liberada agora

                                    @elseif (
                                        $crmReprospecting[
                                            'next_allowed_at'
                                        ]
                                    )

                                        A partir de

                                        {{
                                            $this
                                                ->formatReprospectingDate(
                                                    $crmReprospecting[
                                                        'next_allowed_at'
                                                    ]
                                                )
                                        }}

                                    @else

                                        Revisão manual necessária

                                    @endif

                                </div>

                            </div>

                        @endif


                        <div>
                            <div class="ec-field-label">
                                Último contato
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[var(--ec-text-soft)]
                                "
                            >
                                {{
                                    $crm->last_contacted_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Verificado em
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[var(--ec-text-soft)]
                                "
                            >
                                {{
                                    $crm->checked_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}
                            </div>
                        </div>

                    </div>

                    {{-- NEGÓCIOS HUBSPOT --}}
                    @php
                        $crmDeals =
                            data_get(
                                $crm->metadata,
                                'deals',
                                []
                            );

                        $dealSummary =
                            data_get(
                                $crm->metadata,
                                'deal_summary',
                                []
                            );

                        $crmDeals =
                            is_array($crmDeals)
                                ? $crmDeals
                                : [];
                    @endphp

                    @if ($crmDeals !== [])

                        <div
                            class="
                                mt-5 border-t
                                border-white/5
                                pt-5
                            "
                        >

                            <div
                                class="
                                    flex flex-col gap-2
                                    sm:flex-row
                                    sm:items-center
                                    sm:justify-between
                                "
                            >

                                <div>

                                    <div
                                        class="
                                            text-sm
                                            font-semibold
                                            text-[var(--ec-text)]
                                        "
                                    >
                                        Negócios HubSpot
                                    </div>

                                    <div
                                        class="
                                            mt-0.5 text-xs
                                            text-[var(--ec-text-muted)]
                                        "
                                    >
                                        Negócios associados
                                        usados para classificar
                                        o status comercial.
                                    </div>

                                </div>

                                <div
                                    class="
                                        text-xs
                                        font-medium
                                        text-[var(--ec-text-muted)]
                                    "
                                >
                                    {{
                                        data_get(
                                            $dealSummary,
                                            'active',
                                            0
                                        )
                                    }}
                                    ativo(s)

                                    ·

                                    {{
                                        data_get(
                                            $dealSummary,
                                            'won',
                                            0
                                        )
                                    }}
                                    ganho(s)

                                    ·

                                    {{
                                        data_get(
                                            $dealSummary,
                                            'closed_lost',
                                            0
                                        )
                                    }}
                                    encerrado(s)
                                </div>

                            </div>


                            <div
                                class="
                                    mt-4 grid gap-3
                                    lg:grid-cols-2
                                "
                            >

                                @foreach (
                                    $crmDeals
                                    as $deal
                                )

                                    @php
                                        $dealWon =
                                            (bool) (
                                                $deal[
                                                    'is_closed_won'
                                                ]
                                                ?? false
                                            );

                                        $dealClosed =
                                            (bool) (
                                                $deal[
                                                    'is_closed'
                                                ]
                                                ?? false
                                            );

                                        $dealState =
                                            $dealWon
                                                ? 'Ganho'
                                                : (
                                                    $dealClosed
                                                        ? 'Encerrado'
                                                        : 'Ativo'
                                                );

                                        $dealStateClasses =
                                            $dealWon
                                                ? 'bg-emerald-500/15 text-emerald-300'
                                                : (
                                                    $dealClosed
                                                        ? 'bg-rose-500/15 text-rose-300'
                                                        : 'bg-amber-500/15 text-amber-300'
                                                );
                                    @endphp

                                    <div
                                        class="
                                            rounded-xl
                                            border
                                            border-[var(--ec-border-soft)]
                                            bg-[var(--ec-surface-soft)]
                                            p-4
                                        "
                                    >

                                        <div
                                            class="
                                                flex items-start
                                                justify-between
                                                gap-3
                                            "
                                        >

                                            <div
                                                class="
                                                    min-w-0
                                                "
                                            >

                                                <div
                                                    class="
                                                        truncate
                                                        text-sm
                                                        font-semibold
                                                        text-[var(--ec-text)]
                                                    "
                                                    title="{{
                                                        $deal[
                                                            'name'
                                                        ]
                                                        ?? 'Negócio sem nome'
                                                    }}"
                                                >
                                                    {{
                                                        $deal[
                                                            'name'
                                                        ]
                                                        ?? 'Negócio sem nome'
                                                    }}
                                                </div>

                                                <div
                                                    class="
                                                        mt-1
                                                        text-xs
                                                        text-[var(--ec-text-muted)]
                                                    "
                                                >
                                                    Etapa:

                                                    <span
                                                        class="
                                                            text-[var(--ec-text-soft)]
                                                        "
                                                    >
                                                        {{
                                                            $deal[
                                                                'stage_label'
                                                            ]
                                                            ?? $deal[
                                                                'stage_id'
                                                            ]
                                                            ?? '—'
                                                        }}
                                                    </span>
                                                </div>

                                            </div>


                                            <span
                                                class="
                                                    shrink-0
                                                    rounded-full
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-bold
                                                    uppercase
                                                    {{
                                                        $dealStateClasses
                                                    }}
                                                "
                                            >
                                                {{
                                                    $dealState
                                                }}
                                            </span>

                                        </div>

                                    </div>

                                @endforeach

                            </div>

                        </div>

                    @endif


                    @if ($this->hubSpotCompanyUrl())

                        <div class="mt-5">

                            <a
                                href="{{ $this->hubSpotCompanyUrl() }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ec-button-secondary"
                            >
                                Abrir no HubSpot

                                <span aria-hidden="true">
                                    ↗
                                </span>
                            </a>

                        </div>

                    @endif

                </div>

            </details>

        @endif


            </div> {{-- fim do CRM --}}
        </div> {{-- fim do workspace --}}

        <div class="ec-commercial-icp">
        @if ($company->icpScore)

            <details open class="mt-4 overflow-hidden rounded-xl border border-white/5 bg-[var(--ec-surface-soft)]">

                <summary
                    class="
                        flex cursor-pointer list-none
                        items-center justify-between
                        px-5 py-4
                        text-sm font-semibold
                        text-[var(--ec-text-soft)]
                        transition
                        hover:bg-[var(--ec-surface-soft)]
                    "
                >
                    <span>
                        Detalhamento do ICP
                    </span>

                    <span class="text-xs font-medium text-[var(--ec-text-muted)]">
                        {{ $company->icpScore->grade }}
                        •
                        {{ $company->icpScore->score }}/100
                    </span>
                </summary>

                <div class="border-t border-white/5 px-5 py-4">

                    <div class="space-y-3">

                        @foreach ([
                            'cnae' => 'CNAE prioritário',
                            'state' => 'Estado prioritário',
                            'size' => 'Porte da empresa',
                            'capital' => 'Capital social',
                            'legal_nature' => 'Natureza jurídica',
                            'regional_relevance' => 'Relevância regional',
                        ] as $factorKey => $factorLabel)

                            @php
                                $factor = data_get(
                                    $company->icpScore->factors,
                                    $factorKey,
                                    []
                                );

                                $points = (int) (
                                    $factor['points']
                                    ?? 0
                                );

                                $max = (int) (
                                    $factor['max']
                                    ?? 0
                                );

                                $reason =
                                    $factor['reason']
                                    ?? 'Sem informação.';
                            @endphp

                            <div
                                class="
                                    grid gap-3
                                    rounded-lg
                                    border border-white/5
                                    bg-black/5
                                    px-4 py-3
                                    md:grid-cols-[180px_1fr_80px]
                                    md:items-center
                                "
                            >

                                <div class="text-sm font-medium text-[var(--ec-text-soft)]">
                                    {{ $factorLabel }}
                                </div>

                                <div class="text-xs text-[var(--ec-text-muted)]">
                                    {{ $reason }}
                                </div>

                                <div class="text-right">

                                    <span
                                        class="
                                            inline-flex rounded-full
                                            px-2.5 py-1
                                            text-xs font-bold
                                            {{ $points > 0
                                                ? 'bg-emerald-500/10 text-emerald-300'
                                                : 'bg-[var(--ec-surface-soft)] text-[var(--ec-text-muted)]'
                                            }}
                                        "
                                    >
                                        +{{ $points }}/{{ $max }}
                                    </span>

                                </div>

                            </div>

                        @endforeach

                    </div>

                    <div
                        class="
                            mt-4 flex items-center
                            justify-between
                            border-t border-white/5
                            pt-4
                        "
                    >

                        <div>

                            <div class="text-xs uppercase tracking-[0.12em] text-[var(--ec-text-muted)]">
                                Classificação
                            </div>

                            <div class="mt-1 text-sm font-semibold text-white">
                                {{ $company->icpScore->label }}
                            </div>

                        </div>

                        <div class="text-right">

                            <div class="text-xs text-[var(--ec-text-muted)]">
                                Score cadastral
                            </div>

                            <div class="mt-1 text-2xl font-bold text-[var(--ec-primary)]">
                                {{ $company->icpScore->score }}
                            </div>

                        </div>

                    </div>

                    <p class="mt-4 text-xs leading-5 text-[var(--ec-text-muted)]">
                        Este score considera somente dados cadastrais e estruturais.
                        Exportação, relação com tradings, CRM e contatos serão avaliados
                        em etapas posteriores do Prospector.
                    </p>

                </div>

            </details>

        @endif

        </div> {{-- fecha painel ICP --}}
    </section>
