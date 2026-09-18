<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Support\Cnpj;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $state = '';

    public string $type = '';

    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search',
            'state',
            'type',
            'status',
        ]);

        $this->resetPage();
    }

    #[Computed]
    public function companies()
    {
        $search =
            trim(
                $this->search
            );

        return Company::query()
            ->with([
                'establishments' =>
                    function ($query): void {
                        $query
                            ->orderByRaw(
                                "CASE
                                    WHEN type = 'matrix'
                                    THEN 0
                                    ELSE 1
                                END"
                            )
                            ->orderBy(
                                'id'
                            );
                    },

                'establishments.cnaes',
            ])

            ->when(
                $search !== '',
                function ($query) use (
                    $search
                ): void {
                    $normalizedSearch =
                        mb_strtolower(
                            $search
                        );

                    $cnpjSearch =
                        preg_replace(
                            '/[^A-Z0-9]/i',
                            '',
                            mb_strtoupper(
                                $search
                            )
                        );

                    $query->where(
                        function ($subQuery) use (
                            $normalizedSearch,
                            $cnpjSearch
                        ): void {
                            $subQuery
                                ->whereRaw(
                                    'LOWER(corporate_name) LIKE ?',
                                    [
                                        '%'
                                        .$normalizedSearch
                                        .'%',
                                    ]
                                )

                                ->orWhere(
                                    'cnpj_root',
                                    'like',
                                    '%'
                                    .$cnpjSearch
                                    .'%'
                                )

                                ->orWhereHas(
                                    'establishments',
                                    function (
                                        $establishmentQuery
                                    ) use (
                                        $normalizedSearch,
                                        $cnpjSearch
                                    ): void {
                                        $establishmentQuery
                                            ->where(
                                                'cnpj',
                                                'like',
                                                '%'
                                                .$cnpjSearch
                                                .'%'
                                            )

                                            ->orWhereRaw(
                                                'LOWER(fantasy_name) LIKE ?',
                                                [
                                                    '%'
                                                    .$normalizedSearch
                                                    .'%',
                                                ]
                                            )

                                            ->orWhereRaw(
                                                'LOWER(municipality_name) LIKE ?',
                                                [
                                                    '%'
                                                    .$normalizedSearch
                                                    .'%',
                                                ]
                                            );
                                    }
                                );
                        }
                    );
                }
            )

            ->when(
                $this->state !== '',
                fn ($query) =>
                    $query->whereHas(
                        'establishments',
                        fn (
                            $establishmentQuery
                        ) =>
                            $establishmentQuery
                                ->where(
                                    'state',
                                    $this->state
                                )
                    )
            )

            ->when(
                $this->type !== '',
                fn ($query) =>
                    $query->whereHas(
                        'establishments',
                        fn (
                            $establishmentQuery
                        ) =>
                            $establishmentQuery
                                ->where(
                                    'type',
                                    $this->type
                                )
                    )
            )

            ->when(
                $this->status !== '',
                fn ($query) =>
                    $query->whereHas(
                        'establishments',
                        fn (
                            $establishmentQuery
                        ) =>
                            $establishmentQuery
                                ->where(
                                    'registration_status',
                                    $this->status
                                )
                    )
            )

            ->orderBy(
                'corporate_name'
            )

            ->paginate(
                20
            );
    }

    #[Computed]
    public function states()
    {
        return Establishment::query()
            ->whereNotNull(
                'state'
            )
            ->where(
                'state',
                '!=',
                ''
            )
            ->select(
                'state'
            )
            ->distinct()
            ->orderBy(
                'state'
            )
            ->pluck(
                'state'
            );
    }
};
?>


<div class="ec-page-shell ec-companies-page">

    {{-- =====================================================
         HERO
    ====================================================== --}}
    <section class="ec-companies-hero">

        <div class="ec-companies-hero-copy">

            <div class="ec-page-kicker">
                Inteligência de Leads
            </div>

            <div class="ec-companies-title-row">

                <h1 class="ec-companies-title">
                    Empresas
                </h1>

                <span class="ec-companies-count">
                    {{
                        number_format(
                            $this
                                ->companies
                                ->total(),
                            0,
                            ',',
                            '.'
                        )
                    }}
                </span>

            </div>

            <p class="ec-companies-subtitle">
                Base empresarial utilizada pelo processo
                de prospecção e inteligência comercial.
            </p>

        </div>
        {{-- GLOBO DIGITAL --}}
        <div
            class="ec-companies-hero-visual"
            aria-hidden="true"
        >

            <svg
                viewBox="0 0 760 320"
                role="presentation"
            >

                <defs>

                    <radialGradient
                        id="companiesHeroHalo"
                        cx="50%"
                        cy="50%"
                        r="50%"
                    >
                        <stop
                            offset="0%"
                            stop-color="#1497ff"
                            stop-opacity=".26"
                        />
                        <stop
                            offset="55%"
                            stop-color="#1497ff"
                            stop-opacity=".10"
                        />
                        <stop
                            offset="100%"
                            stop-color="#1497ff"
                            stop-opacity="0"
                        />
                    </radialGradient>

                    <linearGradient
                        id="companiesHeroStroke"
                        x1="0"
                        y1="0"
                        x2="1"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#26d8d0"
                            stop-opacity=".12"
                        />
                        <stop
                            offset="50%"
                            stop-color="#2aa7ff"
                            stop-opacity=".65"
                        />
                        <stop
                            offset="100%"
                            stop-color="#33e1d1"
                            stop-opacity=".20"
                        />
                    </linearGradient>

                    <linearGradient
                        id="companiesHeroOrbit"
                        x1="0"
                        y1="0"
                        x2="1"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#2de0d1"
                            stop-opacity="0"
                        />
                        <stop
                            offset="45%"
                            stop-color="#2f9dff"
                            stop-opacity=".7"
                        />
                        <stop
                            offset="75%"
                            stop-color="#39e0d3"
                            stop-opacity=".55"
                        />
                        <stop
                            offset="100%"
                            stop-color="#39e0d3"
                            stop-opacity="0"
                        />
                    </linearGradient>

                    <pattern
                        id="companiesHeroDotsBg"
                        width="16"
                        height="16"
                        patternUnits="userSpaceOnUse"
                    >
                        <circle
                            cx="2"
                            cy="2"
                            r="1.05"
                            fill="#1685e0"
                            opacity=".26"
                        />
                    </pattern>

                    <pattern
                        id="companiesHeroWorldDots"
                        width="7"
                        height="7"
                        patternUnits="userSpaceOnUse"
                    >
                        <circle
                            cx="2"
                            cy="2"
                            r="1.2"
                            fill="#42b7ff"
                        />
                    </pattern>

                    <clipPath id="companiesHeroGlobeClip">
                        <circle
                            cx="535"
                            cy="150"
                            r="118"
                        />
                    </clipPath>

                </defs>

                <rect
                    x="250"
                    y="12"
                    width="460"
                    height="276"
                    fill="url(#companiesHeroDotsBg)"
                    opacity=".72"
                />

                <circle
                    cx="535"
                    cy="150"
                    r="172"
                    fill="url(#companiesHeroHalo)"
                />

                <circle
                    cx="535"
                    cy="150"
                    r="118"
                    fill="none"
                    stroke="url(#companiesHeroStroke)"
                    stroke-opacity=".35"
                    stroke-width="1.2"
                />

                <g
                    fill="none"
                    stroke="#2d8de5"
                    stroke-opacity=".22"
                    clip-path="url(#companiesHeroGlobeClip)"
                >
                    <ellipse cx="535" cy="150" rx="118" ry="40" />
                    <ellipse cx="535" cy="150" rx="118" ry="74" />
                    <ellipse cx="535" cy="150" rx="45" ry="118" />
                    <ellipse cx="535" cy="150" rx="83" ry="118" />
                    <path d="M417 150H653" />
                    <path d="M431 112H639" />
                    <path d="M431 188H639" />
                </g>

                <g
                    fill="url(#companiesHeroWorldDots)"
                    clip-path="url(#companiesHeroGlobeClip)"
                    opacity=".98"
                >
                    <path
                        d="
                            M460 86
                            C480 72 512 70 534 82
                            L546 94
                            L536 105
                            L514 111
                            L500 126
                            L477 129
                            L462 118
                            L453 103
                            Z
                        "
                    />

                    <path
                        d="
                            M493 128
                            C515 132 528 146 529 165
                            L522 187
                            L510 210
                            L494 218
                            L484 200
                            L481 177
                            L486 149
                            Z
                        "
                    />

                    <path
                        d="
                            M548 91
                            C566 81 589 83 607 96
                            L616 110
                            L608 121
                            L590 123
                            L580 134
                            L563 129
                            L552 116
                            Z
                        "
                    />

                    <path
                        d="
                            M563 133
                            C582 137 593 149 596 165
                            L589 184
                            L577 204
                            L564 199
                            L556 181
                            L554 157
                            Z
                        "
                    />
                </g>

                <g
                    fill="none"
                    stroke="url(#companiesHeroOrbit)"
                    stroke-width="1.6"
                >
                    <ellipse
                        cx="535"
                        cy="150"
                        rx="192"
                        ry="58"
                        transform="rotate(14 535 150)"
                    />
                    <ellipse
                        cx="535"
                        cy="150"
                        rx="176"
                        ry="48"
                        transform="rotate(-17 535 150)"
                    />
                    <ellipse
                        cx="535"
                        cy="150"
                        rx="145"
                        ry="34"
                        transform="rotate(4 535 150)"
                    />
                </g>

                <g fill="#39e1d4">
                    <circle cx="456" cy="112" r="4.2" />
                    <circle cx="505" cy="91" r="3.4" />
                    <circle cx="588" cy="101" r="4" />
                    <circle cx="607" cy="149" r="4.3" />
                    <circle cx="534" cy="201" r="4.2" />
                </g>

            </svg>

        </div>

        <a
            href="{{ route('companies.create') }}"
            wire:navigate
            class="ec-companies-create-button"
        >


            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M12 5v14" />
                <path d="M5 12h14" />
            </svg>

            <span>
                Nova empresa
            </span>

        </a>

    </section>


    {{-- =====================================================
         MENSAGEM
    ====================================================== --}}
    @if (session('success'))

        <div class="ec-alert-success">

            <span class="ec-alert-dot"></span>

            <span>
                {{ session('success') }}
            </span>

        </div>

    @endif


    {{-- =====================================================
         FILTROS
    ====================================================== --}}
    <section class="ec-companies-filter-panel">

        <div class="ec-companies-filter-header">

            <div class="ec-companies-section-title">

                <div class="ec-companies-section-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <path d="M4 5h16" />
                        <path d="M7 12h10" />
                        <path d="M10 19h4" />
                    </svg>

                </div>

                <div>

                    <h2>
                        Filtros
                    </h2>

                    <p>
                        Refine a base para localizar
                        empresas específicas.
                    </p>

                </div>

            </div>


            @if (
                $search !== ''
                || $state !== ''
                || $type !== ''
                || $status !== ''
            )

                <button
                    type="button"
                    wire:click="clearFilters"
                    class="ec-companies-clear"
                >
                    Limpar filtros
                </button>

            @endif

        </div>


        <div class="ec-companies-filter-grid">

            {{-- PESQUISAR --}}
            <div class="ec-companies-filter-search">

                <label
                    for="search"
                    class="ec-companies-field-label"
                >
                    Pesquisar
                </label>

                <div class="ec-companies-search">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        aria-hidden="true"
                    >
                        <circle
                            cx="11"
                            cy="11"
                            r="7"
                        />

                        <path
                            d="m20 20-3.5-3.5"
                        />
                    </svg>

                    <input
                        id="search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Razão social, CNPJ, fantasia ou município"
                    >

                </div>

            </div>


            {{-- UF --}}
            <div>

                <label
                    for="state"
                    class="ec-companies-field-label"
                >
                    UF
                </label>

                <select
                    id="state"
                    wire:model.live="state"
                    class="ec-companies-select"
                >

                    <option value="">
                        Todas
                    </option>

                    @foreach (
                        $this->states
                        as $uf
                    )

                        <option value="{{ $uf }}">
                            {{ $uf }}
                        </option>

                    @endforeach

                </select>

            </div>


            {{-- TIPO --}}
            <div>

                <label
                    for="type"
                    class="ec-companies-field-label"
                >
                    Estabelecimento
                </label>

                <select
                    id="type"
                    wire:model.live="type"
                    class="ec-companies-select"
                >

                    <option value="">
                        Todos
                    </option>

                    <option value="matrix">
                        Matriz
                    </option>

                    <option value="branch">
                        Filial
                    </option>

                </select>

            </div>


            {{-- SITUAÇÃO --}}
            <div>

                <label
                    for="status"
                    class="ec-companies-field-label"
                >
                    Situação
                </label>

                <select
                    id="status"
                    wire:model.live="status"
                    class="ec-companies-select"
                >

                    <option value="">
                        Todas
                    </option>

                    <option value="ATIVA">
                        Ativa
                    </option>

                    <option value="SUSPENSA">
                        Suspensa
                    </option>

                    <option value="INAPTA">
                        Inapta
                    </option>

                    <option value="BAIXADA">
                        Baixada
                    </option>

                    <option value="NULA">
                        Nula
                    </option>

                </select>

            </div>

        </div>

    </section>


    {{-- =====================================================
         BASE EMPRESARIAL
    ====================================================== --}}
    <section class="ec-companies-table-panel">

        <div class="ec-companies-table-header">

            <div class="ec-companies-section-title">

                <div class="ec-companies-section-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <path
                            d="
                                M5 21
                                V7
                                l7-3
                                v17
                            "
                        />

                        <path
                            d="
                                M12 9
                                h7
                                v12
                            "
                        />
                    </svg>

                </div>

                <div>

                    <h2>
                        Base empresarial
                    </h2>

                    <p>
                        @if (
                            $this
                                ->companies
                                ->total()
                            === 1
                        )

                            1 empresa encontrada

                        @else

                            {{
                                number_format(
                                    $this
                                        ->companies
                                        ->total(),
                                    0,
                                    ',',
                                    '.'
                                )
                            }}
                            empresas encontradas

                        @endif
                    </p>

                </div>

            </div>


            <span class="ec-companies-page-size">
                20 por página
            </span>

        </div>


        <div class="ec-companies-table-scroll">

            <table class="ec-companies-table">

                <thead>

                    <tr>

                        <th>
                            Empresa
                        </th>

                        <th>
                            CNPJ
                        </th>

                        <th>
                            Localização
                        </th>

                        <th>
                            CNAE principal
                        </th>

                        <th>
                            Situação
                        </th>

                        <th>
                            Origem
                        </th>

                    </tr>

                </thead>


                <tbody>

                    @forelse (
                        $this->companies
                        as $company
                    )

                        @php
                            $establishment =
                                $company
                                    ->establishments
                                    ->first();

                            $primaryCnae =
                                $establishment
                                    ?->cnaes
                                    ->firstWhere(
                                        'pivot.is_primary',
                                        true
                                    );
                        @endphp


                        <tr
                            wire:key="company-{{ $company->id }}"
                        >

                            {{-- EMPRESA --}}
                            <td>

                                <div class="ec-companies-company">

                                    <div class="ec-companies-avatar">

                                        {{
                                            mb_strtoupper(
                                                mb_substr(
                                                    $company
                                                        ->corporate_name,
                                                    0,
                                                    1
                                                )
                                            )
                                        }}

                                    </div>


                                    <div class="ec-companies-company-text">

                                        <a
                                            href="{{
                                                route(
                                                    'companies.show',
                                                    $company
                                                )
                                            }}"
                                            wire:navigate
                                        >
                                            {{
                                                $company
                                                    ->corporate_name
                                            }}
                                        </a>


                                        <span>
                                            {{
                                                $establishment
                                                    ?->fantasy_name
                                                ?: 'Sem nome fantasia'
                                            }}
                                        </span>

                                    </div>

                                </div>

                            </td>


                            {{-- CNPJ --}}
                            <td>

                                <span class="ec-companies-primary">

                                    @if ($establishment)

                                        {{
                                            Cnpj::format(
                                                $establishment
                                                    ->cnpj
                                            )
                                        }}

                                    @else

                                        —

                                    @endif

                                </span>

                            </td>


                            {{-- LOCALIZAÇÃO --}}
                            <td>

                                @if ($establishment)

                                    <span class="ec-companies-primary">

                                        {{
                                            $establishment
                                                ->municipality_name
                                            ?: '—'
                                        }}

                                    </span>

                                    @if (
                                        $establishment
                                            ->state
                                    )

                                        <span class="ec-companies-muted">
                                            /
                                            {{
                                                $establishment
                                                    ->state
                                            }}
                                        </span>

                                    @endif

                                @else

                                    <span class="ec-companies-muted">
                                        —
                                    </span>

                                @endif

                            </td>


                            {{-- CNAE --}}
                            <td>

                                @if ($primaryCnae)

                                    <div class="ec-companies-cnae-code">
                                        {{
                                            $primaryCnae
                                                ->code
                                        }}
                                    </div>

                                    <div class="ec-companies-cnae-description">
                                        {{
                                            $primaryCnae
                                                ->description
                                        }}
                                    </div>

                                @else

                                    <span class="ec-companies-muted">
                                        Não informado
                                    </span>

                                @endif

                            </td>


                            {{-- SITUAÇÃO --}}
                            <td>

                                @if (
                                    $establishment
                                        ?->registration_status
                                    === 'ATIVA'
                                )

                                    <span
                                        class="
                                            ec-companies-status
                                            ec-companies-status-active
                                        "
                                    >
                                        <span></span>
                                        Ativa
                                    </span>

                                @elseif (
                                    $establishment
                                        ?->registration_status
                                    === 'SUSPENSA'
                                )

                                    <span
                                        class="
                                            ec-companies-status
                                            ec-companies-status-warning
                                        "
                                    >
                                        <span></span>
                                        Suspensa
                                    </span>

                                @elseif (
                                    $establishment
                                        ?->registration_status
                                )

                                    <span
                                        class="
                                            ec-companies-status
                                            ec-companies-status-inactive
                                        "
                                    >
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

                                    <span class="ec-companies-muted">
                                        —
                                    </span>

                                @endif

                            </td>


                            {{-- ORIGEM --}}
                            <td>

                                <span class="ec-companies-source">

                                    {{
                                        ucfirst(
                                            $company
                                                ->source
                                        )
                                    }}

                                </span>

                            </td>

                        </tr>


                    @empty

                        <tr>

                            <td
                                colspan="6"
                                class="ec-companies-empty"
                            >

                                <div class="ec-companies-empty-icon">

                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <path d="M3 21h18" />
                                        <path d="M6 21V4h12v17" />
                                        <path d="M9 8h2" />
                                        <path d="M13 8h2" />
                                        <path d="M9 12h2" />
                                        <path d="M13 12h2" />
                                    </svg>

                                </div>

                                <strong>
                                    Nenhuma empresa encontrada
                                </strong>

                                <span>
                                    Ajuste os filtros ou cadastre
                                    uma nova empresa.
                                </span>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


        @if (
            $this
                ->companies
                ->hasPages()
        )

            <div class="ec-companies-pagination">

                <div class="ec-companies-pagination-info">

                    Exibindo

                    <strong>
                        {{
                            $this
                                ->companies
                                ->firstItem()
                        }}
                    </strong>

                    a

                    <strong>
                        {{
                            $this
                                ->companies
                                ->lastItem()
                        }}
                    </strong>

                    de

                    <strong>
                        {{
                            number_format(
                                $this
                                    ->companies
                                    ->total(),
                                0,
                                ',',
                                '.'
                            )
                        }}
                    </strong>

                    empresas

                </div>


                <div class="ec-companies-pagination-actions">

                    <button
                        type="button"
                        wire:click="previousPage"
                        @disabled(
                            $this
                                ->companies
                                ->onFirstPage()
                        )
                    >
                        ‹
                    </button>


                    @php
                        $currentPage =
                            $this
                                ->companies
                                ->currentPage();

                        $lastPage =
                            $this
                                ->companies
                                ->lastPage();

                        $startPage =
                            max(
                                1,
                                $currentPage - 2
                            );

                        $endPage =
                            min(
                                $lastPage,
                                $currentPage + 2
                            );
                    @endphp


                    @for (
                        $page = $startPage;
                        $page <= $endPage;
                        $page++
                    )

                        <button
                            type="button"
                            wire:click="gotoPage({{ $page }})"
                            class="{{
                                $page === $currentPage
                                    ? 'is-active'
                                    : ''
                            }}"
                        >
                            {{ $page }}
                        </button>

                    @endfor


                    <button
                        type="button"
                        wire:click="nextPage"
                        @disabled(
                            ! $this
                                ->companies
                                ->hasMorePages()
                        )
                    >
                        ›
                    </button>

                </div>

            </div>

        @endif

    </section>

</div>
