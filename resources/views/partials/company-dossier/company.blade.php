    {{-- PRESENÇA OPERACIONAL DO GRUPO --}}
    @php
        $operational =
            $this
                ->groupOperationalSummary;
    @endphp

    <section
        x-show="
            dossierTab
            === 'company'
        "
        x-cloak
        x-transition.opacity.duration.120ms
        class="
            ec-detail-panel
            ec-dossier-tab-panel
        "
    >

        <div class="ec-detail-header">

            <div>

                <h2 class="ec-detail-title">
                    Presença operacional do grupo
                </h2>

                <p class="ec-detail-description">
                    Distribuição das unidades cadastradas
                    e da operação ativa do grupo.
                </p>

            </div>

        </div>


        <div
            class="
                grid gap-3
                sm:grid-cols-2
                xl:grid-cols-4
            "
        >

            <div
                class="
                    rounded-xl
                    border border-[var(--ec-border-soft)]
                    bg-[var(--ec-surface-soft)]
                    p-4
                "
                data-operational-total="{{ $operational['total'] }}"
            >

                <div class="ec-intelligence-label">
                    Unidades cadastradas
                </div>

                <div class="ec-score-value">
                    {{ $operational['total'] }}
                </div>

                <div class="ec-intelligence-caption">
                    Matriz + filiais
                </div>

            </div>


            <div
                class="
                    rounded-xl
                    border border-emerald-400/10
                    bg-emerald-400/[0.025]
                    p-4
                "
                data-operational-active="{{ $operational['active'] }}"
            >

                <div class="ec-intelligence-label">
                    Unidades ativas
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold
                        text-emerald-300
                    "
                >
                    {{ $operational['active'] }}
                </div>

                <div class="ec-intelligence-caption">
                    Operação cadastrada como ativa
                </div>

            </div>


            <div
                class="
                    rounded-xl
                    border border-[var(--ec-border-soft)]
                    bg-[var(--ec-surface-soft)]
                    p-4
                "
                data-operational-states="{{ $operational['active_states_count'] }}"
            >

                <div class="ec-intelligence-label">
                    Estados ativos
                </div>

                <div class="ec-score-value">
                    {{
                        $operational[
                            'active_states_count'
                        ]
                    }}
                </div>

                <div class="ec-intelligence-caption">
                    Presença geográfica ativa
                </div>

            </div>


            <div
                class="
                    rounded-xl
                    border border-[var(--ec-border-soft)]
                    bg-[var(--ec-surface-soft)]
                    p-4
                "
                data-operational-cities="{{ $operational['active_municipalities_count'] }}"
            >

                <div class="ec-intelligence-label">
                    Municípios ativos
                </div>

                <div class="ec-score-value">
                    {{
                        $operational[
                            'active_municipalities_count'
                        ]
                    }}
                </div>

                <div class="ec-intelligence-caption">
                    Municípios com unidade ativa
                </div>

            </div>

        </div>


        <div
            class="
                mt-4 grid gap-4
                lg:grid-cols-2
            "
        >

            {{-- ESTADOS ATIVOS --}}
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
                        font-semibold
                        uppercase
                        tracking-[0.12em]
                        text-[var(--ec-text-muted)]
                    "
                >
                    Estados com operação ativa
                </div>

                @if (
                    $operational[
                        'states'
                    ] !== []
                )

                    <div
                        class="
                            mt-3 flex
                            flex-wrap gap-2
                        "
                    >

                        @foreach (
                            $operational[
                                'states'
                            ]
                            as $stateItem
                        )

                            <span
                                class="
                                    rounded-full
                                    bg-cyan-400/10
                                    px-2.5 py-1
                                    text-[10px]
                                    font-semibold
                                    text-cyan-300
                                "
                            >
                                {{ $stateItem['state'] }}
                                ·
                                {{ $stateItem['count'] }}
                            </span>

                        @endforeach

                    </div>

                @else

                    <div
                        class="
                            mt-3 text-xs
                            text-[var(--ec-text-muted)]
                        "
                    >
                        Nenhum estado ativo identificado.
                    </div>

                @endif

            </div>


            {{-- SITUAÇÕES CADASTRAIS --}}
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
                        font-semibold
                        uppercase
                        tracking-[0.12em]
                        text-[var(--ec-text-muted)]
                    "
                >
                    Situação cadastral das unidades
                </div>

                <div
                    class="
                        mt-3 flex
                        flex-wrap gap-2
                    "
                >

                    @foreach (
                        $operational[
                            'statuses'
                        ]
                        as $statusItem
                    )

                        @php
                            $statusName =
                                $statusItem[
                                    'status'
                                ];

                            $statusClasses =
                                match ($statusName) {
                                    'ATIVA' =>
                                        'bg-emerald-500/10 text-emerald-300',

                                    'SUSPENSA' =>
                                        'bg-amber-500/10 text-amber-300',

                                    'BAIXADA',
                                    'INAPTA',
                                    'NULA' =>
                                        'bg-rose-500/10 text-rose-300',

                                    default =>
                                        'bg-[var(--ec-surface-soft)] text-[var(--ec-text-muted)]',
                                };
                        @endphp

                        <span
                            class="
                                rounded-full
                                px-2.5 py-1
                                text-[10px]
                                font-semibold
                                {{ $statusClasses }}
                            "
                        >
                            {{ $statusName }}
                            ·
                            {{ $statusItem['count'] }}
                        </span>

                    @endforeach

                </div>

            </div>

        </div>

    </section>


    {{-- DADOS + CONTATO --}}
    <div
        x-show="
            dossierTab
            === 'company'
        "
        x-cloak
        x-transition.opacity.duration.120ms
        class="
            ec-dossier-main-grid
            ec-dossier-tab-panel
        "
    >

        {{-- DADOS CADASTRAIS --}}
        <section class="ec-detail-panel ec-dossier-data-panel">

            <div class="ec-detail-header">

                <div>

                    <h2 class="ec-detail-title">
                        Dados cadastrais
                    </h2>

                    <p class="ec-detail-description">
                        Informações oficiais e cadastrais da empresa.
                    </p>

                </div>

            </div>


            <dl class="ec-data-grid">

                <div class="ec-data-item">

                    <dt>
                        Razão social
                    </dt>

                    <dd>
                        {{ $company->corporate_name }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Nome fantasia
                    </dt>

                    <dd>
                        {{ $this->matrix?->fantasy_name ?: '—' }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        CNPJ raiz
                    </dt>

                    <dd class="font-mono">
                        {{ $company->cnpj_root }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Capital social
                    </dt>

                    <dd>
                        {{ $this->formatMoney($company->share_capital) }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Porte
                    </dt>

                    <dd>

                        {{ $company->size_code ?: '—' }}

                        @if ($company->size_description)

                            <span class="ec-data-muted">
                                — {{ $company->size_description }}
                            </span>

                        @endif

                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Natureza jurídica
                    </dt>

                    <dd>

                        {{ $company->legal_nature_code ?: '—' }}

                        @if ($company->legal_nature_description)

                            <span class="ec-data-muted">
                                — {{ $company->legal_nature_description }}
                            </span>

                        @endif

                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Origem
                    </dt>

                    <dd>
                        <span class="ec-source-badge">
                            {{ ucfirst($company->source) }}
                        </span>
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Última atualização
                    </dt>

                    <dd>
                        {{
                            $company
                                ->source_updated_at
                                ?->format('d/m/Y H:i')
                            ?? '—'
                        }}
                    </dd>

                </div>

            </dl>

        </section>


        {{-- CONTATOS CADASTRAIS DO GRUPO --}}
        <section class="ec-detail-panel">

            <div class="ec-detail-header">

                <div>

                    <div
                        class="
                            flex flex-wrap
                            items-center gap-2
                        "
                    >

                        <h2 class="ec-detail-title">
                            Contatos cadastrais do grupo
                        </h2>

                        <span class="ec-count-badge">
                            {{
                                $this
                                    ->contactEstablishments
                                    ->count()
                            }}
                        </span>

                    </div>

                    <p class="ec-detail-description">
                        E-mails e telefones públicos da matriz e filiais.
                    </p>

                </div>

            </div>


            @php
                $groupContacts =
                    $this
                        ->groupContactSummary;
            @endphp

            {{-- CONTATOS ÚNICOS DO GRUPO --}}
            @if (
                $groupContacts[
                    'emails'
                ] !== []
                || $groupContacts[
                    'phones'
                ] !== []
            )

                <div
                    class="
                        mb-4 rounded-xl
                        border border-cyan-300/10
                        bg-cyan-300/[0.025]
                        p-4
                    "
                >

                    <div
                        class="
                            flex flex-col gap-3
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
                                Contatos únicos do grupo
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                Contatos repetidos entre
                                filiais são consolidados.
                            </div>

                        </div>


                        <div
                            class="
                                flex flex-wrap
                                gap-2
                                text-[10px]
                                font-semibold
                                uppercase
                            "
                        >

                            <span
                                class="
                                    rounded-full
                                    bg-[var(--ec-surface-soft)]
                                    px-2.5 py-1
                                    text-[var(--ec-text-soft)]
                                "
                            >
                                {{
                                    $groupContacts[
                                        'units_with_contact'
                                    ]
                                }}
                                unidade(s)
                            </span>

                            <span
                                class="
                                    rounded-full
                                    bg-cyan-400/10
                                    px-2.5 py-1
                                    text-cyan-300
                                "
                            >
                                {{ count($groupContacts['emails']) }} e-mail(s) único(s)
                            </span>

                            <span
                                class="
                                    rounded-full
                                    bg-emerald-400/10
                                    px-2.5 py-1
                                    text-emerald-300
                                "
                            >
                                {{ count($groupContacts['phones']) }} telefone(s) único(s)
                            </span>

                        </div>

                    </div>


                    <div
                        class="
                            mt-4 grid gap-3
                            lg:grid-cols-2
                        "
                    >

                        {{-- E-MAILS ÚNICOS --}}
                        <div
                            class="
                                rounded-xl
                                border border-[var(--ec-border-soft)]
                                bg-[var(--ec-surface-soft)]
                                p-3
                            "
                        >

                            <div
                                class="
                                    text-[10px]
                                    font-semibold
                                    uppercase
                                    tracking-[0.12em]
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                E-mails
                            </div>

                            @if (
                                $groupContacts[
                                    'emails'
                                ] !== []
                            )

                                <div
                                    class="
                                        mt-3 max-h-60
                                        space-y-3
                                        overflow-y-auto
                                        pr-1
                                    "
                                >

                                    @foreach (
                                        $groupContacts[
                                            'emails'
                                        ]
                                        as $emailContact
                                    )

                                        <div>

                                            <div
                                                class="
                                                    flex
                                                    items-start
                                                    justify-between
                                                    gap-2
                                                "
                                            >

                                                <a
                                                    href="mailto:{{
                                                        $emailContact[
                                                            'value'
                                                        ]
                                                    }}"
                                                    class="
                                                        min-w-0
                                                        break-all
                                                        text-xs
                                                        font-semibold
                                                        text-cyan-300
                                                        hover:text-cyan-200
                                                    "
                                                >
                                                    {{
                                                        $emailContact[
                                                            'value'
                                                        ]
                                                    }}
                                                </a>

                                                <span
                                                    class="
                                                        shrink-0
                                                        rounded-full
                                                        bg-[var(--ec-surface-soft)]
                                                        px-2 py-0.5
                                                        text-[9px]
                                                        text-[var(--ec-text-muted)]
                                                    "
                                                >
                                                    {{ $emailContact['count'] }} unidade(s)
                                                </span>

                                            </div>


                                            <div
                                                class="
                                                    mt-1
                                                    text-[10px]
                                                    leading-4
                                                    text-[var(--ec-text-muted)]
                                                "
                                                title="{{
                                                    implode(
                                                        ' | ',
                                                        $emailContact[
                                                            'locations'
                                                        ]
                                                    )
                                                }}"
                                            >

                                                {{
                                                    implode(
                                                        ' · ',
                                                        array_slice(
                                                            $emailContact[
                                                                'locations'
                                                            ],
                                                            0,
                                                            2
                                                        )
                                                    )
                                                }}

                                                @if (
                                                    $emailContact[
                                                        'count'
                                                    ] > 2
                                                )

                                                    · +{{
                                                        $emailContact[
                                                            'count'
                                                        ] - 2
                                                    }}
                                                    unidade(s)

                                                @endif

                                            </div>

                                        </div>

                                    @endforeach

                                </div>

                            @else

                                <div
                                    class="
                                        mt-3 text-xs
                                        text-[var(--ec-text-muted)]
                                    "
                                >
                                    Nenhum e-mail encontrado.
                                </div>

                            @endif

                        </div>


                        {{-- TELEFONES ÚNICOS --}}
                        <div
                            class="
                                rounded-xl
                                border border-[var(--ec-border-soft)]
                                bg-[var(--ec-surface-soft)]
                                p-3
                            "
                        >

                            <div
                                class="
                                    text-[10px]
                                    font-semibold
                                    uppercase
                                    tracking-[0.12em]
                                    text-[var(--ec-text-muted)]
                                "
                            >
                                Telefones
                            </div>

                            @if (
                                $groupContacts[
                                    'phones'
                                ] !== []
                            )

                                <div
                                    class="
                                        mt-3 max-h-60
                                        space-y-3
                                        overflow-y-auto
                                        pr-1
                                    "
                                >

                                    @foreach (
                                        $groupContacts[
                                            'phones'
                                        ]
                                        as $phoneContact
                                    )

                                        <div>

                                            <div
                                                class="
                                                    flex
                                                    items-start
                                                    justify-between
                                                    gap-2
                                                "
                                            >

                                                <a
                                                    href="{{
                                                        $phoneContact[
                                                            'href'
                                                        ]
                                                    }}"
                                                    class="
                                                        text-xs
                                                        font-semibold
                                                        text-emerald-300
                                                        hover:text-emerald-200
                                                    "
                                                >
                                                    {{
                                                        $phoneContact[
                                                            'value'
                                                        ]
                                                    }}
                                                </a>

                                                <span
                                                    class="
                                                        shrink-0
                                                        rounded-full
                                                        bg-[var(--ec-surface-soft)]
                                                        px-2 py-0.5
                                                        text-[9px]
                                                        text-[var(--ec-text-muted)]
                                                    "
                                                >
                                                    {{ $phoneContact['count'] }} unidade(s)
                                                </span>

                                            </div>


                                            <div
                                                class="
                                                    mt-1
                                                    text-[10px]
                                                    leading-4
                                                    text-[var(--ec-text-muted)]
                                                "
                                                title="{{
                                                    implode(
                                                        ' | ',
                                                        $phoneContact[
                                                            'locations'
                                                        ]
                                                    )
                                                }}"
                                            >

                                                {{
                                                    implode(
                                                        ' · ',
                                                        array_slice(
                                                            $phoneContact[
                                                                'locations'
                                                            ],
                                                            0,
                                                            2
                                                        )
                                                    )
                                                }}

                                                @if (
                                                    $phoneContact[
                                                        'count'
                                                    ] > 2
                                                )

                                                    · +{{
                                                        $phoneContact[
                                                            'count'
                                                        ] - 2
                                                    }}
                                                    unidade(s)

                                                @endif

                                            </div>

                                        </div>

                                    @endforeach

                                </div>

                            @else

                                <div
                                    class="
                                        mt-3 text-xs
                                        text-[var(--ec-text-muted)]
                                    "
                                >
                                    Nenhum telefone encontrado.
                                </div>

                            @endif

                        </div>

                    </div>

                </div>

            @endif


            @if (
                $this
                    ->contactEstablishments
                    ->isNotEmpty()
            )

                <div
                    class="
                        mx-4 mb-4
                    "
                >

                    <button
                        type="button"
                        class="
                            ec-contact-units-toggle
                        "
                        @click="
                            showUnitContacts =
                                ! showUnitContacts
                        "
                    >

                        <span>

                            <strong>
                                Contatos por unidade
                            </strong>

                            <small>
                                {{
                                    $this
                                        ->contactEstablishments
                                        ->count()
                                }}
                                unidade(s) com contato cadastrado
                            </small>

                        </span>

                        <span
                            class="
                                ec-contact-toggle-action
                            "
                        >

                            <span
                                x-show="
                                    ! showUnitContacts
                                "
                            >
                                Ver unidades
                            </span>

                            <span
                                x-show="
                                    showUnitContacts
                                "
                                x-cloak
                            >
                                Ocultar
                            </span>

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                :class="{
                                    'is-expanded':
                                        showUnitContacts
                                }"
                            >
                                <path
                                    d="m6 9 6 6 6-6"
                                />
                            </svg>

                        </span>

                    </button>

                </div>


                <div
                    x-show="
                        showUnitContacts
                    "
                    x-cloak
                    x-transition.opacity.duration.120ms
                    class="
                        mx-4 mb-4
                        max-h-[430px]
                        space-y-3
                        overflow-y-auto
                        pr-1
                    "
                >

                    @foreach (
                        $this
                            ->contactEstablishments
                        as $contactEstablishment
                    )

                        <div
                            wire:key="contact-establishment-{{
                                $contactEstablishment->id
                            }}"
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
                                    flex flex-wrap
                                    items-start
                                    justify-between
                                    gap-2
                                "
                            >

                                <div>

                                    <div
                                        class="
                                            flex flex-wrap
                                            items-center
                                            gap-2
                                        "
                                    >

                                        <span
                                            class="
                                                text-xs
                                                font-semibold
                                                text-[var(--ec-text)]
                                            "
                                        >
                                            {{
                                                $contactEstablishment
                                                    ->type
                                                    === 'matrix'
                                                    ? 'Matriz'
                                                    : 'Filial'
                                            }}
                                        </span>

                                        @if (
                                            $contactEstablishment
                                                ->registration_status
                                        )

                                            <span
                                                class="
                                                    rounded-full
                                                    px-2 py-0.5
                                                    text-[9px]
                                                    font-bold
                                                    uppercase
                                                    {{
                                                        $contactEstablishment
                                                            ->registration_status
                                                            === 'ATIVA'
                                                            ? 'bg-emerald-500/10 text-emerald-300'
                                                            : 'bg-[var(--ec-surface-soft)] text-[var(--ec-text-muted)]'
                                                    }}
                                                "
                                            >
                                                {{
                                                    $contactEstablishment
                                                        ->registration_status
                                                }}
                                            </span>

                                        @endif

                                    </div>


                                    <div
                                        class="
                                            mt-1 text-[11px]
                                            text-[var(--ec-text-muted)]
                                        "
                                    >

                                        {{
                                            \App\Support\Cnpj::format(
                                                $contactEstablishment
                                                    ->cnpj
                                            )
                                        }}

                                        @if (
                                            $contactEstablishment
                                                ->municipality_name
                                        )

                                            ·

                                            {{
                                                $contactEstablishment
                                                    ->municipality_name
                                            }}

                                            @if (
                                                $contactEstablishment
                                                    ->state
                                            )
                                                /
                                                {{
                                                    $contactEstablishment
                                                        ->state
                                                }}
                                            @endif

                                        @endif

                                    </div>

                                </div>

                            </div>


                            <div
                                class="
                                    mt-3 grid gap-3
                                    sm:grid-cols-2
                                "
                            >

                                <div>

                                    <div class="ec-contact-label">
                                        E-mail
                                    </div>

                                    <div
                                        class="
                                            mt-1 break-all
                                            text-xs
                                            text-[var(--ec-text-soft)]
                                        "
                                    >

                                        @if (
                                            $contactEstablishment
                                                ->email
                                        )

                                            <a
                                                href="mailto:{{
                                                    $contactEstablishment
                                                        ->email
                                                }}"
                                                class="
                                                    text-cyan-300
                                                    hover:text-cyan-200
                                                "
                                            >
                                                {{
                                                    $contactEstablishment
                                                        ->email
                                                }}
                                            </a>

                                        @else
                                            —
                                        @endif

                                    </div>

                                </div>


                                <div>

                                    <div class="ec-contact-label">
                                        Telefone(s)
                                    </div>

                                    <div
                                        class="
                                            mt-1 flex
                                            flex-col gap-1
                                            text-xs
                                            text-[var(--ec-text-soft)]
                                        "
                                    >

                                        @if (
                                            $contactEstablishment
                                                ->phone_1
                                        )

                                            <a
                                                href="{{
                                                    $this
                                                        ->phoneHref(
                                                            $contactEstablishment
                                                                ->phone_1
                                                        )
                                                }}"
                                                class="
                                                    hover:text-cyan-300
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->formatPhone(
                                                            $contactEstablishment
                                                                ->phone_1
                                                        )
                                                }}
                                            </a>

                                        @endif


                                        @if (
                                            $contactEstablishment
                                                ->phone_2
                                        )

                                            <a
                                                href="{{
                                                    $this
                                                        ->phoneHref(
                                                            $contactEstablishment
                                                                ->phone_2
                                                        )
                                                }}"
                                                class="
                                                    hover:text-cyan-300
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->formatPhone(
                                                            $contactEstablishment
                                                                ->phone_2
                                                        )
                                                }}
                                            </a>

                                        @endif


                                        @if (
                                            ! $contactEstablishment
                                                ->phone_1
                                            && ! $contactEstablishment
                                                ->phone_2
                                        )
                                            —
                                        @endif

                                    </div>

                                </div>

                            </div>

                        </div>

                    @endforeach

                </div>

            @else

                <div
                    class="
                        rounded-xl
                        border border-[var(--ec-border-soft)]
                        bg-[var(--ec-surface-soft)]
                        px-4 py-6
                        text-center
                    "
                >

                    <div
                        class="
                            text-sm
                            font-semibold
                            text-[var(--ec-text-soft)]
                        "
                    >
                        Nenhum contato público encontrado
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            text-[var(--ec-text-muted)]
                        "
                    >
                        A Receita não possui e-mail ou telefone
                        cadastrado nas unidades deste grupo.
                    </div>

                </div>

            @endif


            <div class="ec-contact-note">
                Estes contatos são dados cadastrais públicos.
                Eles ainda não representam necessariamente
                um decisor comercial.
            </div>

        </section>

    </div>


    {{-- ESTABELECIMENTOS --}}
    <section
        x-show="
            dossierTab
            === 'company'
        "
        x-cloak
        x-transition.opacity.duration.120ms
        class="
            ec-table-panel
            ec-dossier-tab-panel
        "
    >

        <div class="ec-table-toolbar">

            <div>

                <div class="flex items-center gap-2">

                    <h2 class="ec-table-title">
                        Estabelecimentos
                    </h2>

                    <span class="ec-count-badge">
                        {{ $company->establishments->count() }}
                    </span>

                </div>

                <p class="ec-table-description">
                    Matriz e filiais vinculadas ao mesmo CNPJ raiz.
                </p>

            </div>

            <a
                href="{{ route('companies.branches.create', $company) }}"
                wire:navigate
                class="ec-button-primary"
            >

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    class="size-4"
                >
                    <path d="M12 5v14M5 12h14" />
                </svg>

                Adicionar filial

            </a>

        </div>


        <div class="overflow-x-auto">

            <table class="ec-table">

                <thead>

                    <tr>

                        <th>
                            Tipo
                        </th>

                        <th>
                            CNPJ
                        </th>

                        <th>
                            Nome fantasia
                        </th>

                        <th>
                            Localização
                        </th>

                        <th>
                            Contato
                        </th>

                        <th>
                            Situação
                        </th>

                        <th>
                            Cadastro
                        </th>

                    </tr>

                </thead>

                <tbody>

                    @forelse (
                        $company->establishments
                        as $establishment
                    )

                        <tr
                            @if (
                                $loop->index
                                >= 8
                            )
                                x-show="
                                    expandedEstablishments
                                "
                                x-cloak
                            @endif

                            wire:key="establishment-{{ $establishment->id }}"
                        >

                            <td>

                                @if ($establishment->type === 'matrix')

                                    <span class="ec-type-badge ec-type-matrix">
                                        Matriz
                                    </span>

                                @else

                                    <span class="ec-type-badge ec-type-branch">
                                        Filial
                                    </span>

                                @endif

                            </td>


                            <td class="whitespace-nowrap">

                                <span class="ec-table-primary-text font-mono">
                                    {{ \App\Support\Cnpj::format($establishment->cnpj) }}
                                </span>

                            </td>


                            <td>

                                <span class="ec-table-primary-text">
                                    {{ $establishment->fantasy_name ?: '—' }}
                                </span>

                            </td>


                            <td>

                                <div
                                    class="
                                        min-w-[280px]
                                        max-w-[440px]
                                    "
                                >

                                    <div
                                        class="
                                            text-xs
                                            leading-5
                                            text-[var(--ec-text-soft)]
                                        "
                                    >
                                        {{
                                            $this
                                                ->establishmentAddress(
                                                    $establishment
                                                )
                                        }}
                                    </div>

                                </div>

                            </td>


                            <td>

                                <div
                                    class="
                                        min-w-[220px]
                                        space-y-1
                                    "
                                >

                                    @if (
                                        $establishment
                                            ->email
                                    )

                                        <a
                                            href="mailto:{{
                                                $establishment
                                                    ->email
                                            }}"
                                            class="
                                                block
                                                truncate
                                                text-xs
                                                text-cyan-300
                                                hover:text-cyan-200
                                            "
                                            title="{{
                                                $establishment
                                                    ->email
                                            }}"
                                        >
                                            {{
                                                $establishment
                                                    ->email
                                            }}
                                        </a>

                                    @endif


                                    @if (
                                        $establishment
                                            ->phone_1
                                    )

                                        <a
                                            href="{{
                                                $this
                                                    ->phoneHref(
                                                        $establishment
                                                            ->phone_1
                                                    )
                                            }}"
                                            class="
                                                block text-xs
                                                text-[var(--ec-text-soft)]
                                                hover:text-cyan-300
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatPhone(
                                                        $establishment
                                                            ->phone_1
                                                    )
                                            }}
                                        </a>

                                    @endif


                                    @if (
                                        $establishment
                                            ->phone_2
                                    )

                                        <a
                                            href="{{
                                                $this
                                                    ->phoneHref(
                                                        $establishment
                                                            ->phone_2
                                                    )
                                            }}"
                                            class="
                                                block text-xs
                                                text-[var(--ec-text-soft)]
                                                hover:text-cyan-300
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatPhone(
                                                        $establishment
                                                            ->phone_2
                                                    )
                                            }}
                                        </a>

                                    @endif


                                    @if (
                                        ! $establishment
                                            ->email
                                        && ! $establishment
                                            ->phone_1
                                        && ! $establishment
                                            ->phone_2
                                    )

                                        <span class="ec-table-muted">
                                            —
                                        </span>

                                    @endif

                                </div>

                            </td>


                            <td class="whitespace-nowrap">

                                @if (
                                    $establishment->registration_status
                                    === 'ATIVA'
                                )

                                    <span class="ec-status ec-status-active">
                                        <span></span>
                                        Ativa
                                    </span>

                                @elseif (
                                    $establishment->registration_status
                                    === 'SUSPENSA'
                                )

                                    <span class="ec-status ec-status-warning">
                                        <span></span>
                                        Suspensa
                                    </span>

                                @elseif (
                                    $establishment->registration_status
                                )

                                    <span class="ec-status ec-status-inactive">

                                        <span></span>

                                        {{
                                            ucfirst(
                                                mb_strtolower(
                                                    $establishment
                                                        ->registration_status
                                                )
                                            )
                                        }}

                                    </span>

                                @else

                                    <span class="ec-table-muted">
                                        —
                                    </span>

                                @endif

                            </td>


                            <td
                                data-establishment-registration-details
                            >

                                <div
                                    class="
                                        min-w-[230px]
                                        space-y-2
                                        text-xs
                                    "
                                >

                                    <div>

                                        <span
                                            class="
                                                text-[var(--ec-text-muted)]
                                            "
                                        >
                                            Abertura:
                                        </span>

                                        <span
                                            class="
                                                ml-1
                                                text-[var(--ec-text-soft)]
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatEstablishmentDate(
                                                        $establishment
                                                            ->start_date
                                                    )
                                            }}
                                        </span>

                                    </div>


                                    <div>

                                        <span
                                            class="
                                                text-[var(--ec-text-muted)]
                                            "
                                        >
                                            Situação desde:
                                        </span>

                                        <span
                                            class="
                                                ml-1
                                                text-[var(--ec-text-soft)]
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatEstablishmentDate(
                                                        $establishment
                                                            ->registration_status_date
                                                    )
                                            }}
                                        </span>

                                    </div>


                                    @if (
                                        $establishment
                                            ->registration_status_reason_code
                                    )

                                        <div>

                                            <span
                                                class="
                                                    text-[var(--ec-text-muted)]
                                                "
                                            >
                                                Motivo:
                                            </span>

                                            <span
                                                class="
                                                    ml-1
                                                    font-mono
                                                    text-[var(--ec-text-soft)]
                                                "
                                            >
                                                {{
                                                    $establishment
                                                        ->registration_status_reason_code
                                                }}
                                            </span>

                                        </div>

                                    @endif


                                    @if (
                                        $establishment
                                            ->special_situation
                                    )

                                        <div
                                            class="
                                                rounded-lg
                                                border
                                                border-amber-400/10
                                                bg-amber-400/[0.025]
                                                px-2.5 py-2
                                            "
                                        >

                                            <div
                                                class="
                                                    text-[10px]
                                                    font-semibold
                                                    uppercase
                                                    tracking-wide
                                                    text-amber-300
                                                "
                                            >
                                                Situação especial
                                            </div>

                                            <div
                                                class="
                                                    mt-1
                                                    text-xs
                                                    font-medium
                                                    text-[var(--ec-text-soft)]
                                                "
                                            >
                                                {{
                                                    $establishment
                                                        ->special_situation
                                                }}
                                            </div>

                                            @if (
                                                $establishment
                                                    ->special_situation_date
                                            )

                                                <div
                                                    class="
                                                        mt-1
                                                        text-[10px]
                                                        text-[var(--ec-text-muted)]
                                                    "
                                                >
                                                    Desde
                                                    {{
                                                        $this
                                                            ->formatEstablishmentDate(
                                                                $establishment
                                                                    ->special_situation_date
                                                            )
                                                    }}
                                                </div>

                                            @endif

                                        </div>

                                    @endif


                                    @if (
                                        $establishment
                                            ->source_updated_at
                                    )

                                        <div
                                            class="
                                                pt-1
                                                text-[10px]
                                                text-[var(--ec-text-muted)]
                                            "
                                        >
                                            Fonte atualizada em
                                            {{
                                                $this
                                                    ->formatEstablishmentDate(
                                                        $establishment
                                                            ->source_updated_at
                                                    )
                                            }}
                                        </div>

                                    @endif

                                </div>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="7"
                                class="!py-16 text-center"
                            >

                                <div class="ec-empty-title">
                                    Nenhum estabelecimento encontrado
                                </div>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


        @if (
            $company
                ->establishments
                ->count()
            > 8
        )

            <div class="ec-show-more-bar">

                <button
                    type="button"
                    class="ec-show-more-button"
                    @click="
                        expandedEstablishments =
                            ! expandedEstablishments
                    "
                >

                    <span
                        x-show="
                            ! expandedEstablishments
                        "
                    >
                        Ver todas as
                        {{
                            $company
                                ->establishments
                                ->count()
                        }}
                        unidades
                    </span>

                    <span
                        x-show="
                            expandedEstablishments
                        "
                        x-cloak
                    >
                        Mostrar menos
                    </span>

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        :class="{
                            'is-expanded':
                                expandedEstablishments
                        }"
                    >
                        <path
                            d="m6 9 6 6 6-6"
                        />
                    </svg>

                </button>

            </div>

        @endif

    </section>


    {{-- CNAES DO GRUPO --}}
    <section
        x-show="
            dossierTab
            === 'cnaes'
        "
        x-cloak
        x-transition.opacity.duration.120ms
        class="
            ec-detail-panel
            ec-dossier-tab-panel
        "
    >

        <div class="ec-detail-header">

            <div>

                <div
                    class="
                        flex flex-wrap
                        items-center gap-2
                    "
                >

                    <h2 class="ec-detail-title">
                        CNAEs do grupo
                    </h2>

                    <span class="ec-count-badge">
                        {{
                            $this
                                ->groupCnaes
                                ->count()
                        }}
                    </span>

                </div>

                <p class="ec-detail-description">
                    Atividades econômicas encontradas
                    na matriz e nas filiais do grupo.
                </p>

            </div>

        </div>


        @if (
            $this
                ->groupCnaes
                ->isNotEmpty()
        )

            <div
                class="
                    grid gap-3
                    md:grid-cols-2
                    xl:grid-cols-3
                "
            >

                @foreach (
                    $this->groupCnaes
                    as $groupCnae
                )

                    <div
                        @if (
                            $loop->index
                            >= 6
                        )
                            x-show="
                                expandedGroupCnaes
                            "
                            x-cloak
                        @endif

                        wire:key="group-cnae-{{
                            $groupCnae['code']
                        }}"
                        class="
                            rounded-xl
                            border border-[var(--ec-border-soft)]
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
                                    font-mono
                                    text-sm
                                    font-bold
                                    text-cyan-300
                                "
                            >
                                {{
                                    $groupCnae[
                                        'code'
                                    ]
                                }}
                            </div>


                            @if (
                                $groupCnae[
                                    'primary_units_count'
                                ] > 0
                            )

                                <span
                                    class="
                                        rounded-full
                                        bg-emerald-500/10
                                        px-2 py-1
                                        text-[9px]
                                        font-bold
                                        uppercase
                                        text-emerald-300
                                    "
                                >
                                    Principal em
                                    {{
                                        $groupCnae[
                                            'primary_units_count'
                                        ]
                                    }}
                                </span>

                            @endif

                        </div>


                        <div
                            class="
                                mt-2 min-h-10
                                text-xs
                                leading-5
                                text-[var(--ec-text-soft)]
                            "
                        >
                            {{
                                $groupCnae[
                                    'description'
                                ]
                                ?: 'Sem descrição'
                            }}
                        </div>


                        <div
                            class="
                                mt-3 flex
                                flex-wrap gap-2
                            "
                        >

                            <span
                                class="
                                    rounded-full
                                    bg-[var(--ec-surface-soft)]
                                    px-2 py-1
                                    text-[10px]
                                    font-semibold
                                    text-[var(--ec-text-soft)]
                                "
                            >
                                {{ $groupCnae['units_count'] }} unidade(s)
                            </span>

                            <span
                                class="
                                    rounded-full
                                    bg-emerald-400/10
                                    px-2 py-1
                                    text-[10px]
                                    font-semibold
                                    text-emerald-300
                                "
                            >
                                {{ $groupCnae['active_units_count'] }} ativa(s)
                            </span>

                        </div>


                        @if (
                            $groupCnae[
                                'locations'
                            ] !== []
                        )

                            <div
                                class="
                                    mt-3 border-t
                                    border-white/5
                                    pt-3 text-[10px]
                                    leading-4
                                    text-[var(--ec-text-muted)]
                                "
                                title="{{
                                    implode(
                                        ' | ',
                                        $groupCnae[
                                            'locations'
                                        ]
                                    )
                                }}"
                            >

                                {{
                                    implode(
                                        ' · ',
                                        array_slice(
                                            $groupCnae[
                                                'locations'
                                            ],
                                            0,
                                            3
                                        )
                                    )
                                }}

                                @if (
                                    $groupCnae[
                                        'units_count'
                                    ] > 3
                                )

                                    · +{{
                                        $groupCnae[
                                            'units_count'
                                        ] - 3
                                    }}
                                    unidade(s)

                                @endif

                            </div>

                        @endif

                    </div>

                @endforeach

            </div>


            @if (
                $this
                    ->groupCnaes
                    ->count()
                > 6
            )

                <div class="ec-show-more-bar">

                    <button
                        type="button"
                        class="ec-show-more-button"
                        @click="
                            expandedGroupCnaes =
                                ! expandedGroupCnaes
                        "
                    >

                        <span
                            x-show="
                                ! expandedGroupCnaes
                            "
                        >
                            Ver todos os
                            {{
                                $this
                                    ->groupCnaes
                                    ->count()
                            }}
                            CNAEs
                        </span>

                        <span
                            x-show="
                                expandedGroupCnaes
                            "
                            x-cloak
                        >
                            Mostrar menos
                        </span>

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            :class="{
                                'is-expanded':
                                    expandedGroupCnaes
                            }"
                        >
                            <path
                                d="m6 9 6 6 6-6"
                            />
                        </svg>

                    </button>

                </div>

            @endif

        @else

            <div
                class="
                    rounded-xl
                    border border-[var(--ec-border-soft)]
                    bg-[var(--ec-surface-soft)]
                    px-4 py-8
                    text-center
                "
            >

                <div
                    class="
                        text-sm font-semibold
                        text-[var(--ec-text-soft)]
                    "
                >
                    Nenhum CNAE encontrado no grupo
                </div>

            </div>

        @endif

    </section>
