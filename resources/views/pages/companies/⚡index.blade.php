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

<div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

        <div>
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                Inteligência de Leads
            </p>

            <h1 class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">
                Empresas
            </h1>

            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                Base empresarial utilizada pelo processo de prospecção.
            </p>
        </div>

        <a
            href="{{ route('companies.create') }}"
            wire:navigate
            class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
        >
            + Nova empresa
        </a>

    </div>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-5">

            <div class="lg:col-span-2">
                <label
                    for="search"
                    class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-500"
                >
                    Pesquisar
                </label>

                <input
                    id="search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Razão social, CNPJ, fantasia ou município"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                >
            </div>

            <div>
                <label
                    for="state"
                    class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-500"
                >
                    UF
                </label>

                <select
                    id="state"
                    wire:model.live="state"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
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

            <div>
                <label
                    for="type"
                    class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-500"
                >
                    Estabelecimento
                </label>

                <select
                    id="type"
                    wire:model.live="type"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
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

            <div>
                <label
                    for="status"
                    class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-500"
                >
                    Situação
                </label>

                <select
                    id="status"
                    wire:model.live="status"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
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

        @if (
            $search !== ''
            || $state !== ''
            || $type !== ''
            || $status !== ''
        )
            <div class="mt-3 flex justify-end">
                <button
                    type="button"
                    wire:click="clearFilters"
                    class="text-sm font-medium text-blue-600 hover:text-blue-700"
                >
                    Limpar filtros
                </button>
            </div>
        @endif

    </section>

    <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        <div class="overflow-x-auto">

            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">

                <thead class="bg-zinc-50 dark:bg-zinc-950/50">

                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Empresa
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            CNPJ
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Localização
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            CNAE principal
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Situação
                        </th>

                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Origem
                        </th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">

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
                            class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
                        >

                            <td class="px-5 py-4">

                                <a
                                    href="{{ route('companies.show', $company) }}"
                                    wire:navigate
                                    class="font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400"
                                >
                                    {{ $company->corporate_name }}
                                </a>

                                @if ($establishment?->fantasy_name)
                                    <div class="mt-1 text-sm text-zinc-500">
                                        {{ $establishment->fantasy_name }}
                                    </div>
                                @endif

                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-sm text-zinc-600 dark:text-zinc-300">
                                @if ($establishment)
                                    {{ Cnpj::format($establishment->cnpj) }}
                                @else
                                    —
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-sm text-zinc-600 dark:text-zinc-300">
                                @if ($establishment)
                                    {{ $establishment->municipality_name ?: '—' }}

                                    @if ($establishment->state)
                                        / {{ $establishment->state }}
                                    @endif
                                @else
                                    —
                                @endif
                            </td>

                            <td class="px-5 py-4 text-sm text-zinc-600 dark:text-zinc-300">

                                @if ($primaryCnae)
                                    <div class="font-medium">
                                        {{ $primaryCnae->code }}
                                    </div>

                                    <div class="mt-1 max-w-xs text-xs text-zinc-500">
                                        {{ $primaryCnae->description }}
                                    </div>
                                @else
                                    —
                                @endif

                            </td>

                            <td class="whitespace-nowrap px-5 py-4">

                                @if ($establishment?->registration_status === 'ATIVA')

                                    <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                        Ativa
                                    </span>

                                @elseif ($establishment?->registration_status)

                                    <span class="inline-flex rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $establishment->registration_status }}
                                    </span>

                                @else
                                    —
                                @endif

                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-right text-sm text-zinc-500">
                                {{ $company->source }}
                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td
                                colspan="6"
                                class="px-6 py-16 text-center"
                            >
                                <div class="text-base font-medium text-zinc-800 dark:text-zinc-200">
                                    Nenhuma empresa encontrada
                                </div>

                                <p class="mt-2 text-sm text-zinc-500">
                                    Cadastre uma empresa ou ajuste os filtros.
                                </p>
                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

        @if ($this->companies->hasPages())
            <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">
                {{ $this->companies->links() }}
            </div>
        @endif

    </section>

</div>
