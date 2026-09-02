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
        $search = trim($this->search);

        return Company::query()
            ->with([
                'establishments' => function ($query) {
                    $query
                        ->orderByRaw(
                            "CASE WHEN type = 'matrix' THEN 0 ELSE 1 END"
                        )
                        ->orderBy('id');
                },

                'establishments.cnaes',
            ])

            ->when(
                $search !== '',
                function ($query) use ($search) {
                    $normalizedSearch =
                        mb_strtolower($search);

                    $cnpjSearch =
                        preg_replace(
                            '/[^A-Z0-9]/i',
                            '',
                            mb_strtoupper($search)
                        );

                    $query->where(
                        function ($subQuery) use (
                            $normalizedSearch,
                            $cnpjSearch
                        ) {
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
                                    '%'.$cnpjSearch.'%'
                                )

                                ->orWhereHas(
                                    'establishments',
                                    function ($establishmentQuery) use (
                                        $normalizedSearch,
                                        $cnpjSearch
                                    ) {
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
                        fn ($establishmentQuery) =>
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
                        fn ($establishmentQuery) =>
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
                        fn ($establishmentQuery) =>
                            $establishmentQuery
                                ->where(
                                    'registration_status',
                                    $this->status
                                )
                    )
            )

            ->orderBy('corporate_name')
            ->paginate(20);
    }

    #[Computed]
    public function states()
    {
        return Establishment::query()
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->select('state')
            ->distinct()
            ->orderBy('state')
            ->pluck('state');
    }
};
?>

<div class="ec-page-shell">

    {{-- CABEÇALHO --}}
    <div class="ec-page-header">

        <div>

            <div class="ec-page-kicker">
                Inteligência de Leads
            </div>

            <div class="mt-1 flex flex-wrap items-center gap-3">

                <h1 class="ec-page-title">
                    Empresas
                </h1>

                <span class="ec-count-badge">
                    {{ $this->companies->total() }}
                </span>

            </div>

            <p class="ec-page-description">
                Base empresarial utilizada pelo processo de prospecção.
            </p>

        </div>

        <a
            href="{{ route('companies.create') }}"
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

            Nova empresa

        </a>

    </div>


    {{-- MENSAGEM --}}
    @if (session('success'))

        <div class="ec-alert-success">

            <span class="ec-alert-dot"></span>

            <span>
                {{ session('success') }}
            </span>

        </div>

    @endif


    {{-- FILTROS --}}
    <section class="ec-filter-panel">

        <div class="ec-filter-header">

            <div>

                <h2 class="ec-filter-title">
                    Filtros
                </h2>

                <p class="ec-filter-description">
                    Refine a base para localizar empresas específicas.
                </p>

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
                    class="ec-filter-clear"
                >
                    Limpar filtros
                </button>

            @endif

        </div>


        <div class="ec-filter-grid">

            {{-- PESQUISA --}}
            <div class="lg:col-span-2">

                <label
                    for="search"
                    class="ec-field-label"
                >
                    Pesquisar
                </label>

                <div class="ec-search-wrap">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        class="ec-search-icon"
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
                        class="ec-input ec-input-search"
                    >

                </div>

            </div>


            {{-- UF --}}
            <div>

                <label
                    for="state"
                    class="ec-field-label"
                >
                    UF
                </label>

                <select
                    id="state"
                    wire:model.live="state"
                    class="ec-input"
                >

                    <option value="">
                        Todas
                    </option>

                    @foreach ($this->states as $uf)

                        <option value="{{ $uf }}">
                            {{ $uf }}
                        </option>

                    @endforeach

                </select>

            </div>


            {{-- ESTABELECIMENTO --}}
            <div>

                <label
                    for="type"
                    class="ec-field-label"
                >
                    Estabelecimento
                </label>

                <select
                    id="type"
                    wire:model.live="type"
                    class="ec-input"
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
                    class="ec-field-label"
                >
                    Situação
                </label>

                <select
                    id="status"
                    wire:model.live="status"
                    class="ec-input"
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


    {{-- TABELA --}}
    <section class="ec-table-panel">

        <div class="ec-table-toolbar">

            <div>

                <h2 class="ec-table-title">
                    Base empresarial
                </h2>

                <p class="ec-table-description">

                    @if ($this->companies->total() === 1)

                        1 empresa encontrada

                    @else

                        {{ $this->companies->total() }}
                        empresas encontradas

                    @endif

                </p>

            </div>

            <div class="ec-table-meta">
                Até 20 por página
            </div>

        </div>


        <div class="overflow-x-auto">

            <table class="ec-table">

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

                        <th class="text-right">
                            Origem
                        </th>

                    </tr>

                </thead>

                <tbody>

                    @forelse ($this->companies as $company)

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

                                <div class="ec-company-cell">

                                    <div class="ec-company-avatar">

                                        {{ mb_strtoupper(
                                            mb_substr(
                                                $company->corporate_name,
                                                0,
                                                1
                                            )
                                        ) }}

                                    </div>

                                    <div class="min-w-0">

                                        <a
                                            href="{{ route('companies.show', $company) }}"
                                            wire:navigate
                                            class="ec-company-link"
                                        >
                                            {{ $company->corporate_name }}
                                        </a>

                                        @if ($establishment?->fantasy_name)

                                            <div class="ec-company-fantasy">
                                                {{ $establishment->fantasy_name }}
                                            </div>

                                        @else

                                            <div class="ec-company-fantasy">
                                                Sem nome fantasia
                                            </div>

                                        @endif

                                    </div>

                                </div>

                            </td>


                            {{-- CNPJ --}}
                            <td class="whitespace-nowrap">

                                <span class="ec-table-primary-text">

                                    @if ($establishment)

                                        {{ Cnpj::format(
                                            $establishment->cnpj
                                        ) }}

                                    @else
                                        —
                                    @endif

                                </span>

                            </td>


                            {{-- LOCALIZAÇÃO --}}
                            <td class="whitespace-nowrap">

                                @if ($establishment)

                                    <span class="ec-table-primary-text">
                                        {{ $establishment->municipality_name ?: '—' }}
                                    </span>

                                    @if ($establishment->state)

                                        <span class="ec-table-muted">
                                            / {{ $establishment->state }}
                                        </span>

                                    @endif

                                @else
                                    —
                                @endif

                            </td>


                            {{-- CNAE --}}
                            <td>

                                @if ($primaryCnae)

                                    <div class="ec-cnae-code">
                                        {{ $primaryCnae->code }}
                                    </div>

                                    <div class="ec-cnae-description">
                                        {{ $primaryCnae->description }}
                                    </div>

                                @else

                                    <span class="ec-table-muted">
                                        Não informado
                                    </span>

                                @endif

                            </td>


                            {{-- SITUAÇÃO --}}
                            <td class="whitespace-nowrap">

                                @if (
                                    $establishment
                                        ?->registration_status
                                        === 'ATIVA'
                                )

                                    <span class="ec-status ec-status-active">

                                        <span></span>

                                        Ativa

                                    </span>

                                @elseif (
                                    $establishment
                                        ?->registration_status
                                    === 'SUSPENSA'
                                )

                                    <span class="ec-status ec-status-warning">

                                        <span></span>

                                        Suspensa

                                    </span>

                                @elseif (
                                    $establishment
                                        ?->registration_status
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


                            {{-- ORIGEM --}}
                            <td class="whitespace-nowrap text-right">

                                <span class="ec-source-badge">
                                    {{ ucfirst($company->source) }}
                                </span>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="6"
                                class="!py-20 text-center"
                            >

                                <div class="ec-empty-icon">

                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                        class="size-7"
                                    >
                                        <path d="M3 21h18" />
                                        <path d="M6 21V4h12v17" />
                                        <path d="M9 8h2" />
                                        <path d="M13 8h2" />
                                        <path d="M9 12h2" />
                                        <path d="M13 12h2" />
                                        <path d="M9 16h2" />
                                        <path d="M13 16h2" />
                                    </svg>

                                </div>

                                <div class="ec-empty-title">
                                    Nenhuma empresa encontrada
                                </div>

                                <p class="ec-empty-description">
                                    Cadastre uma empresa ou ajuste os filtros.
                                </p>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


        @if ($this->companies->hasPages())

            <div class="ec-pagination">
                {{ $this->companies->links() }}
            </div>

        @endif

    </section>

</div>
