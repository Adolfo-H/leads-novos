<x-layouts::app :title="__('Dashboard')">

    @php
        $companiesTotal =
            \App\Models\Company::query()
                ->count();

        $establishmentsTotal =
            \App\Models\Establishment::query()
                ->count();

        $activeTotal =
            \App\Models\Establishment::query()
                ->where(
                    'registration_status',
                    'ATIVA'
                )
                ->count();

        $matrixTotal =
            \App\Models\Establishment::query()
                ->where(
                    'type',
                    'matrix'
                )
                ->count();

        $branchesTotal =
            \App\Models\Establishment::query()
                ->where(
                    'type',
                    'branch'
                )
                ->count();

        $cnaesTotal =
            \App\Models\Cnae::query()
                ->whereHas(
                    'establishments'
                )
                ->count();

        $emailTotal =
            \App\Models\Establishment::query()
                ->whereNotNull(
                    'email'
                )
                ->where(
                    'email',
                    '!=',
                    ''
                )
                ->count();

        $phoneTotal =
            \App\Models\Establishment::query()
                ->whereNotNull(
                    'phone_1'
                )
                ->where(
                    'phone_1',
                    '!=',
                    ''
                )
                ->count();


        $activePercent =
            $establishmentsTotal > 0
                ? round(
                    (
                        $activeTotal
                        / $establishmentsTotal
                    ) * 100
                )
                : 0;

        $emailPercent =
            $establishmentsTotal > 0
                ? round(
                    (
                        $emailTotal
                        / $establishmentsTotal
                    ) * 100
                )
                : 0;

        $phonePercent =
            $establishmentsTotal > 0
                ? round(
                    (
                        $phoneTotal
                        / $establishmentsTotal
                    ) * 100
                )
                : 0;


        $structureTotal =
            $matrixTotal
            + $branchesTotal;

        $matrixPercent =
            $structureTotal > 0
                ? round(
                    (
                        $matrixTotal
                        / $structureTotal
                    ) * 100,
                    1
                )
                : 0;

        $branchesPercent =
            $structureTotal > 0
                ? round(
                    (
                        $branchesTotal
                        / $structureTotal
                    ) * 100,
                    1
                )
                : 0;
    @endphp


    <div class="ec-page-shell ec-dashboard-page">

        {{-- =====================================================
             HERO
        ====================================================== --}}
        <section class="ec-dashboard-hero">

            <div class="ec-dashboard-hero-copy">

                <div class="ec-page-kicker">
                    Inteligência Comercial
                </div>

                <h1 class="ec-dashboard-title">
                    Dashboard
                </h1>

                <p class="ec-dashboard-subtitle">
                    Visão estratégica da base utilizada
                    pelo Prospector ExportControl.
                </p>

            </div>


            {{-- GLOBO DIGITAL --}}
            <div
                class="ec-dashboard-global-visual"
                aria-hidden="true"
            >

                <svg
                    viewBox="0 0 760 300"
                    role="presentation"
                >

                    <defs>

                        <radialGradient
                            id="globeHalo"
                            cx="50%"
                            cy="50%"
                            r="50%"
                        >
                            <stop
                                offset="0%"
                                stop-color="#168dff"
                                stop-opacity=".28"
                            />

                            <stop
                                offset="60%"
                                stop-color="#0878ef"
                                stop-opacity=".12"
                            />

                            <stop
                                offset="100%"
                                stop-color="#0878ef"
                                stop-opacity="0"
                            />

                        </radialGradient>


                        <radialGradient
                            id="globeSurface"
                            cx="40%"
                            cy="30%"
                            r="72%"
                        >
                            <stop
                                offset="0%"
                                stop-color="#0d62bb"
                                stop-opacity=".21"
                            />

                            <stop
                                offset="100%"
                                stop-color="#031a37"
                                stop-opacity=".16"
                            />

                        </radialGradient>


                        <linearGradient
                            id="orbitGradient"
                            x1="0"
                            y1="0"
                            x2="1"
                            y2="1"
                        >

                            <stop
                                offset="0%"
                                stop-color="#20e0dc"
                                stop-opacity="0"
                            />

                            <stop
                                offset="40%"
                                stop-color="#269fff"
                                stop-opacity=".72"
                            />

                            <stop
                                offset="72%"
                                stop-color="#35e1dc"
                                stop-opacity=".65"
                            />

                            <stop
                                offset="100%"
                                stop-color="#35e1dc"
                                stop-opacity="0"
                            />

                        </linearGradient>


                        <pattern
                            id="globeDots"
                            width="7"
                            height="7"
                            patternUnits="userSpaceOnUse"
                        >

                            <circle
                                cx="2"
                                cy="2"
                                r="1.35"
                                fill="#2ca9ff"
                            />

                        </pattern>


                        <pattern
                            id="heroDots"
                            width="14"
                            height="14"
                            patternUnits="userSpaceOnUse"
                        >

                            <circle
                                cx="2"
                                cy="2"
                                r="1.25"
                                fill="#137bd8"
                                opacity=".32"
                            />

                        </pattern>


                        <clipPath id="globeClip">

                            <circle
                                cx="505"
                                cy="155"
                                r="128"
                            />

                        </clipPath>

                    </defs>


                    {{-- brilho --}}
                    <circle
                        cx="505"
                        cy="155"
                        r="205"
                        fill="url(#globeHalo)"
                    />


                    {{-- pontos de fundo --}}
                    <rect
                        x="260"
                        y="20"
                        width="430"
                        height="245"
                        fill="url(#heroDots)"
                        opacity=".62"
                    />


                    {{-- esfera --}}
                    <circle
                        cx="505"
                        cy="155"
                        r="128"
                        fill="url(#globeSurface)"
                        stroke="#2099fa"
                        stroke-opacity=".34"
                        stroke-width="1.2"
                    />


                    {{-- linhas latitude --}}
                    <g
                        fill="none"
                        stroke="#238fef"
                        stroke-opacity=".22"
                        stroke-width="1"
                        clip-path="url(#globeClip)"
                    >

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="128"
                            ry="41"
                        />

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="128"
                            ry="78"
                        />

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="128"
                            ry="105"
                        />

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="47"
                            ry="128"
                        />

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="88"
                            ry="128"
                        />

                    </g>


                    {{-- CONTINENTES PONTILHADOS --}}
                    <g
                        fill="url(#globeDots)"
                        clip-path="url(#globeClip)"
                        opacity=".94"
                    >

                        {{-- América do Norte --}}
                        <path
                            d="
                                M407 77
                                C423 60 451 52 474 56
                                L493 68
                                L486 80
                                L465 84
                                L454 98
                                L432 102
                                L422 118
                                L407 113
                                L399 96
                                Z
                            "
                        />

                        {{-- América Central --}}
                        <path
                            d="
                                M431 116
                                L449 119
                                L454 131
                                L445 139
                                L435 130
                                Z
                            "
                        />

                        {{-- América do Sul --}}
                        <path
                            d="
                                M449 139
                                C467 139 480 149 480 164
                                L470 181
                                L465 202
                                L453 225
                                L441 210
                                L436 186
                                L428 163
                                L435 148
                                Z
                            "
                        />

                        {{-- Europa --}}
                        <path
                            d="
                                M528 83
                                L544 75
                                L565 78
                                L573 89
                                L561 99
                                L543 97
                                L533 107
                                L521 99
                                Z
                            "
                        />

                        {{-- África --}}
                        <path
                            d="
                                M534 105
                                C557 102 580 111 586 132
                                L579 159
                                L562 188
                                L545 184
                                L531 157
                                L523 128
                                Z
                            "
                        />

                        {{-- Ásia --}}
                        <path
                            d="
                                M566 78
                                C594 65 633 71 657 89
                                L676 104
                                L666 123
                                L641 126
                                L624 143
                                L600 135
                                L586 117
                                L566 110
                                L555 96
                                Z
                            "
                        />

                        {{-- Oceania --}}
                        <path
                            d="
                                M637 177
                                L659 169
                                L677 181
                                L668 197
                                L647 199
                                Z
                            "
                        />

                    </g>


                    {{-- órbitas --}}
                    <g
                        fill="none"
                        stroke="url(#orbitGradient)"
                        stroke-width="1.45"
                    >

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="202"
                            ry="74"
                            transform="
                                rotate(
                                    -12
                                    505
                                    155
                                )
                            "
                        />

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="190"
                            ry="56"
                            transform="
                                rotate(
                                    18
                                    505
                                    155
                                )
                            "
                        />

                        <ellipse
                            cx="505"
                            cy="155"
                            rx="176"
                            ry="102"
                            transform="
                                rotate(
                                    -28
                                    505
                                    155
                                )
                            "
                        />

                    </g>


                    {{-- pontos de conexão --}}
                    <g fill="#34e3d4">

                        <circle
                            cx="390"
                            cy="130"
                            r="3.8"
                        />

                        <circle
                            cx="450"
                            cy="94"
                            r="3.4"
                        />

                        <circle
                            cx="533"
                            cy="90"
                            r="3.5"
                        />

                        <circle
                            cx="595"
                            cy="118"
                            r="3.7"
                        />

                        <circle
                            cx="646"
                            cy="155"
                            r="3.5"
                        />

                        <circle
                            cx="466"
                            cy="198"
                            r="3.8"
                        />

                    </g>


                    {{-- halos --}}
                    <g
                        fill="none"
                        stroke="#42e5db"
                        stroke-opacity=".32"
                    >

                        <circle
                            cx="390"
                            cy="130"
                            r="8"
                        />

                        <circle
                            cx="595"
                            cy="118"
                            r="7"
                        />

                        <circle
                            cx="466"
                            cy="198"
                            r="8"
                        />

                    </g>

                </svg>

            </div>


            <div class="ec-dashboard-hero-side">

                <span>
                    Dados que geram
                </span>

                <strong>
                    oportunidades globais.
                </strong>

            </div>

        </section>


        {{-- =====================================================
             KPI CARDS
        ====================================================== --}}
        <section class="ec-dashboard-cards">

            {{-- EMPRESAS --}}
            <div class="ec-dashboard-card">

                <div class="ec-dashboard-card-heading">

                    <div class="ec-dashboard-card-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path d="M5 21V7l7-3v17" />
                            <path d="M12 9h7v12" />
                            <path d="M8 10h1" />
                            <path d="M8 14h1" />
                            <path d="M8 18h1" />
                            <path d="M15 13h1" />
                            <path d="M15 17h1" />
                        </svg>

                    </div>

                    <div>

                        <span>
                            Empresas
                        </span>

                        <small>
                            Grupos empresariais
                        </small>

                    </div>

                </div>


                <strong class="ec-dashboard-card-value">
                    {{
                        number_format(
                            $companiesTotal,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </strong>

                <div class="ec-dashboard-card-caption">
                    Base empresarial atual
                </div>

            </div>


            {{-- ESTABELECIMENTOS --}}
            <div class="ec-dashboard-card">

                <div class="ec-dashboard-card-heading">

                    <div class="ec-dashboard-card-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path d="M4 9 6 4h12l2 5" />
                            <path d="M5 9v10h14V9" />
                            <path d="M9 19v-5h6v5" />
                            <path d="M4 9h16" />
                        </svg>

                    </div>

                    <div>

                        <span>
                            Estabelecimentos
                        </span>

                        <small>
                            Matrizes e filiais
                        </small>

                    </div>

                </div>


                <strong class="ec-dashboard-card-value">
                    {{
                        number_format(
                            $establishmentsTotal,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </strong>

                <div class="ec-dashboard-card-caption">
                    Estrutura empresarial mapeada
                </div>

            </div>


            {{-- ATIVOS --}}
            <div class="ec-dashboard-card">

                <span class="ec-dashboard-percent-badge">
                    {{ $activePercent }}%
                </span>


                <div class="ec-dashboard-card-heading">

                    <div class="ec-dashboard-card-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <circle
                                cx="9"
                                cy="8"
                                r="3"
                            />

                            <circle
                                cx="17"
                                cy="9"
                                r="2.3"
                            />

                            <path
                                d="
                                    M3 20
                                    c0-4
                                    2.5-6
                                    6-6
                                    s6 2
                                    6 6
                                "
                            />

                            <path
                                d="
                                    M15 15
                                    c3 0
                                    5 1.7
                                    5 5
                                "
                            />
                        </svg>

                    </div>

                    <div>

                        <span>
                            Estabelecimentos ativos
                        </span>

                        <small>
                            Situação cadastral ativa
                        </small>

                    </div>

                </div>


                <strong class="ec-dashboard-card-value">
                    {{
                        number_format(
                            $activeTotal,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </strong>

                <div class="ec-dashboard-card-caption">
                    Representatividade na base cadastral
                </div>

            </div>


            {{-- CNAES --}}
            <div class="ec-dashboard-card">

                <div class="ec-dashboard-card-heading">

                    <div class="ec-dashboard-card-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path
                                d="
                                    M7 3
                                    h8
                                    l4 4
                                    v14
                                    H7
                                    Z
                                "
                            />

                            <path d="M15 3v5h5" />
                            <path d="M10 12h6" />
                            <path d="M10 16h6" />
                        </svg>

                    </div>

                    <div>

                        <span>
                            CNAEs mapeados
                        </span>

                        <small>
                            Atividades vinculadas
                        </small>

                    </div>

                </div>


                <strong class="ec-dashboard-card-value">
                    {{
                        number_format(
                            $cnaesTotal,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </strong>

                <div class="ec-dashboard-card-caption">
                    Cobertura de atividades da base
                </div>

            </div>

        </section>


        {{-- =====================================================
             CONTEÚDO
        ====================================================== --}}
        <section class="ec-dashboard-grid">

            {{-- QUALIDADE DA BASE --}}
            <div class="ec-dashboard-panel">

                <div class="ec-dashboard-panel-header">

                    <div class="ec-dashboard-panel-title-row">

                        <div class="ec-dashboard-panel-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                            >
                                <ellipse
                                    cx="12"
                                    cy="5"
                                    rx="7"
                                    ry="3"
                                />

                                <path
                                    d="
                                        M5 5v7
                                        c0 1.7
                                        3.1 3
                                        7 3
                                        s7-1.3
                                        7-3
                                        V5
                                    "
                                />

                                <path
                                    d="
                                        M5 12v7
                                        c0 1.7
                                        3.1 3
                                        7 3
                                        s7-1.3
                                        7-3
                                        v-7
                                    "
                                />
                            </svg>

                        </div>

                        <div>

                            <h2>
                                Qualidade da base
                            </h2>

                            <p>
                                Cobertura atual dos dados empresariais.
                            </p>

                        </div>

                    </div>


                    <span class="ec-dashboard-panel-chip">
                        Base atual
                    </span>

                </div>


                <div class="ec-dashboard-progress-list">

                    {{-- ATIVOS --}}
                    <div class="ec-dashboard-quality-row">

                        <div class="ec-dashboard-quality-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                            >
                                <circle
                                    cx="12"
                                    cy="8"
                                    r="3"
                                />

                                <path
                                    d="
                                        M5 21
                                        c0-4.7
                                        2.7-7
                                        7-7
                                        s7 2.3
                                        7 7
                                    "
                                />
                            </svg>

                        </div>


                        <div class="ec-dashboard-quality-content">

                            <div class="ec-dashboard-progress-head">

                                <span>
                                    Situação ativa
                                </span>

                                <strong>
                                    {{ $activePercent }}%
                                </strong>

                                <small>
                                    {{
                                        number_format(
                                            $activeTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                    de
                                    {{
                                        number_format(
                                            $establishmentsTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                </small>

                            </div>


                            <div class="ec-dashboard-progress">

                                <span
                                    style="
                                        width:
                                        {{ $activePercent }}%
                                    "
                                ></span>

                            </div>

                        </div>

                    </div>


                    {{-- EMAIL --}}
                    <div class="ec-dashboard-quality-row">

                        <div class="ec-dashboard-quality-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                            >
                                <rect
                                    x="3"
                                    y="5"
                                    width="18"
                                    height="14"
                                    rx="2"
                                />

                                <path
                                    d="m3 7 9 6 9-6"
                                />
                            </svg>

                        </div>


                        <div class="ec-dashboard-quality-content">

                            <div class="ec-dashboard-progress-head">

                                <span>
                                    E-mail cadastral
                                </span>

                                <strong>
                                    {{ $emailPercent }}%
                                </strong>

                                <small>
                                    {{
                                        number_format(
                                            $emailTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                    de
                                    {{
                                        number_format(
                                            $establishmentsTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                </small>

                            </div>


                            <div class="ec-dashboard-progress">

                                <span
                                    style="
                                        width:
                                        {{ $emailPercent }}%
                                    "
                                ></span>

                            </div>

                        </div>

                    </div>


                    {{-- TELEFONE --}}
                    <div class="ec-dashboard-quality-row">

                        <div class="ec-dashboard-quality-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                            >
                                <path
                                    d="
                                        M6 3
                                        h3
                                        l2 5
                                        -2 2
                                        c1.4 2.9
                                        3.1 4.6
                                        6 6
                                        l2-2
                                        4 2
                                        v3
                                        c0 1.1
                                        -.9 2
                                        -2 2
                                        C10.2 21
                                        3 13.8
                                        3 5
                                        c0-1.1
                                        .9-2
                                        2-2
                                        Z
                                    "
                                />
                            </svg>

                        </div>


                        <div class="ec-dashboard-quality-content">

                            <div class="ec-dashboard-progress-head">

                                <span>
                                    Telefone cadastral
                                </span>

                                <strong>
                                    {{ $phonePercent }}%
                                </strong>

                                <small>
                                    {{
                                        number_format(
                                            $phoneTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                    de
                                    {{
                                        number_format(
                                            $establishmentsTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                </small>

                            </div>


                            <div class="ec-dashboard-progress">

                                <span
                                    style="
                                        width:
                                        {{ $phonePercent }}%
                                    "
                                ></span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            {{-- ESTRUTURA --}}
            <div class="ec-dashboard-panel">

                <div class="ec-dashboard-panel-header">

                    <div class="ec-dashboard-panel-title-row">

                        <div class="ec-dashboard-panel-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                            >
                                <circle
                                    cx="12"
                                    cy="5"
                                    r="2"
                                />

                                <circle
                                    cx="6"
                                    cy="18"
                                    r="2"
                                />

                                <circle
                                    cx="18"
                                    cy="18"
                                    r="2"
                                />

                                <path d="M12 7v4" />
                                <path d="M6 16v-3h12v3" />
                            </svg>

                        </div>

                        <div>

                            <h2>
                                Estrutura empresarial
                            </h2>

                            <p>
                                Composição dos estabelecimentos.
                            </p>

                        </div>

                    </div>


                    <span class="ec-dashboard-panel-chip">
                        {{
                            number_format(
                                $structureTotal,
                                0,
                                ',',
                                '.'
                            )
                        }}
                        registros
                    </span>

                </div>


                <div class="ec-dashboard-structure-modern">

                    <div
                        class="ec-dashboard-donut"
                        style="
                            --ec-matrix-percent:
                            {{ $matrixPercent }}%;
                        "
                    >

                        <div>

                            <strong>
                                {{
                                    number_format(
                                        $structureTotal,
                                        0,
                                        ',',
                                        '.'
                                    )
                                }}
                            </strong>

                            <span>
                                estabelecimentos
                            </span>

                        </div>

                    </div>


                    <div class="ec-dashboard-structure-stats">

                        <div>

                            <span
                                class="
                                    ec-dashboard-legend-dot
                                    ec-dashboard-legend-matrix
                                "
                            ></span>

                            <div>

                                <small>
                                    Matrizes
                                </small>

                                <strong>
                                    {{
                                        number_format(
                                            $matrixTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                </strong>

                                <span>
                                    {{ $matrixPercent }}%
                                    do total
                                </span>

                            </div>

                        </div>


                        <div>

                            <span
                                class="
                                    ec-dashboard-legend-dot
                                    ec-dashboard-legend-branch
                                "
                            ></span>

                            <div>

                                <small>
                                    Filiais
                                </small>

                                <strong>
                                    {{
                                        number_format(
                                            $branchesTotal,
                                            0,
                                            ',',
                                            '.'
                                        )
                                    }}
                                </strong>

                                <span>
                                    {{ $branchesPercent }}%
                                    do total
                                </span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </section>


        {{-- =====================================================
             MOTOR
        ====================================================== --}}
        <section
            class="
                ec-dashboard-panel
                ec-dashboard-pipeline-panel
            "
        >

            <div class="ec-dashboard-panel-header">

                <div class="ec-dashboard-panel-title-row">

                    <div class="ec-dashboard-panel-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <circle
                                cx="12"
                                cy="12"
                                r="7"
                            />

                            <circle
                                cx="12"
                                cy="12"
                                r="2"
                            />

                            <path d="M12 2v3" />
                            <path d="M22 12h-3" />
                            <path d="M12 22v-3" />
                            <path d="M2 12h3" />
                        </svg>

                    </div>

                    <div>

                        <h2>
                            Motor de prospecção
                        </h2>

                        <p>
                            Descoberta e qualificação automática
                            de novas oportunidades comerciais.
                        </p>

                    </div>

                </div>


                @if (
                    auth()
                        ->user()
                        ?->isCommercialManager()
                )

                    <a
                        href="{{ route('prospecting.index') }}"
                        wire:navigate
                        class="ec-dashboard-engine-button"
                    >
                        Abrir motor

                        <span>
                            →
                        </span>
                    </a>

                @endif

            </div>


            <div class="ec-pipeline">

                {{-- 01 --}}
                <div class="ec-pipeline-step">

                    <div class="ec-pipeline-step-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <circle
                                cx="11"
                                cy="11"
                                r="6"
                            />

                            <path
                                d="m16 16 5 5"
                            />
                        </svg>

                    </div>

                    <div>

                        <span>01</span>

                        <strong>
                            Descoberta
                        </strong>

                        <small>
                            Receita Federal e filtros ICP
                        </small>

                    </div>

                </div>


                <div class="ec-pipeline-arrow">
                    →
                </div>


                {{-- 02 --}}
                <div class="ec-pipeline-step">

                    <div class="ec-pipeline-step-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <circle
                                cx="9"
                                cy="8"
                                r="3"
                            />

                            <circle
                                cx="17"
                                cy="9"
                                r="2"
                            />

                            <path
                                d="
                                    M3 20
                                    c0-4
                                    2.5-6
                                    6-6
                                    s6 2
                                    6 6
                                "
                            />
                        </svg>

                    </div>

                    <div>

                        <span>02</span>

                        <strong>
                            ICP
                        </strong>

                        <small>
                            Perfil e aderência comercial
                        </small>

                    </div>

                </div>


                <div class="ec-pipeline-arrow">
                    →
                </div>


                {{-- 03 --}}
                <div class="ec-pipeline-step">

                    <div class="ec-pipeline-step-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <ellipse
                                cx="12"
                                cy="6"
                                rx="7"
                                ry="3"
                            />

                            <path
                                d="
                                    M5 6v6
                                    c0 1.7
                                    3.1 3
                                    7 3
                                    s7-1.3
                                    7-3
                                    V6
                                "
                            />

                            <path
                                d="
                                    M5 12v6
                                    c0 1.7
                                    3.1 3
                                    7 3
                                    s7-1.3
                                    7-3
                                    v-6
                                "
                            />
                        </svg>

                    </div>

                    <div>

                        <span>03</span>

                        <strong>
                            CRM
                        </strong>

                        <small>
                            HubSpot e histórico comercial
                        </small>

                    </div>

                </div>


                <div class="ec-pipeline-arrow">
                    →
                </div>


                {{-- 04 --}}
                <div class="ec-pipeline-step">

                    <div class="ec-pipeline-step-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path d="M5 17V8h6" />
                            <path d="m9 5 3 3-3 3" />
                            <path d="M9 19h10V9" />
                        </svg>

                    </div>

                    <div>

                        <span>04</span>

                        <strong>
                            Exportação
                        </strong>

                        <small>
                            Evidências públicas de exportação
                        </small>

                    </div>

                </div>


                <div class="ec-pipeline-arrow">
                    →
                </div>


                {{-- 05 --}}
                <div class="ec-pipeline-step">

                    <div class="ec-pipeline-step-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path d="M5 19V9" />
                            <path d="M10 19V5" />
                            <path d="M15 19v-7" />
                            <path d="M20 19V3" />
                        </svg>

                    </div>

                    <div>

                        <span>05</span>

                        <strong>
                            Score
                        </strong>

                        <small>
                            Priorização e fila de Leads
                        </small>

                    </div>

                </div>

            </div>

        </section>

    </div>

</x-layouts::app>
