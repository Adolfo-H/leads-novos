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

<div class="eccl-page">
    @php
        $records = $this->companies;
        $ufs = $this->states;
        $hasFilters = $search !== '' || $state !== '' || $type !== '' || $status !== '';
        $statusLabels = [
            'ATIVA' => 'Ativa', 'SUSPENSA' => 'Suspensa', 'INAPTA' => 'Inapta',
            'BAIXADA' => 'Baixada', 'NULA' => 'Nula',
        ];
        $currentPage = $records->currentPage();
        $lastPage = $records->lastPage();
        $startPage = max(1, $currentPage - 2);
        $endPage = min($lastPage, $currentPage + 2);
    @endphp

    <header class="eccl-header">
        <div>
            <div class="eccl-eyebrow">BASE EMPRESARIAL</div>
            <h1>Empresas</h1>
            <p>Encontre um cadastro e acesse o dossiê da empresa.</p>
        </div>
        <a class="eccl-button eccl-primary" href="{{ route('companies.create') }}" wire:navigate>
            <span aria-hidden="true">+</span> Nova empresa
        </a>
    </header>

    @if (session('success'))
        <div class="eccl-success" role="status">{{ session('success') }}</div>
    @endif

    <section class="eccl-panel" aria-label="Consulta de empresas">
        <div class="eccl-toolbar">
            <div class="eccl-toolbar-title">
                <h2>Buscar na base</h2>
                @if ($hasFilters)
                    <button type="button" class="eccl-text-button" wire:click="clearFilters"
                            wire:loading.attr="disabled">Limpar filtros</button>
                @endif
            </div>

            <div class="eccl-filters">
                <div class="eccl-field eccl-search-field">
                    <label for="eccl-search">Pesquisar empresa</label>
                    <div class="eccl-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" aria-hidden="true">
                            <circle cx="10.5" cy="10.5" r="6.5" />
                            <path d="m16 16 4.5 4.5" />
                        </svg>
                        <input id="eccl-search" type="search"
                               wire:model.live.debounce.400ms="search"
                               placeholder="Razão social, CNPJ, fantasia ou município"
                               autocomplete="off">
                    </div>
                </div>

                <div class="eccl-field">
                    <label for="eccl-state">UF</label>
                    <select id="eccl-state" wire:model.live="state">
                        <option value="">Todas</option>
                        @foreach ($ufs as $uf)
                            <option value="{{ $uf }}">{{ $uf }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="eccl-field">
                    <label for="eccl-type">Estabelecimento</label>
                    <select id="eccl-type" wire:model.live="type">
                        <option value="">Todos</option>
                        <option value="matrix">Matriz</option>
                        <option value="branch">Filial</option>
                    </select>
                </div>

                <div class="eccl-field">
                    <label for="eccl-status">Situação cadastral</label>
                    <select id="eccl-status" wire:model.live="status">
                        <option value="">Todas</option>
                        @foreach ($statusLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($hasFilters)
                <div class="eccl-active-filters" aria-label="Filtros aplicados">
                    @if ($search !== '')
                        <button type="button" wire:click="$set('search', '')"
                                aria-label="Remover filtro de pesquisa" title="{{ $search }}">
                            <span>Busca: {{ $search }}</span><b aria-hidden="true">×</b>
                        </button>
                    @endif
                    @if ($state !== '')
                        <button type="button" wire:click="$set('state', '')" aria-label="Remover filtro de UF">
                            <span>UF: {{ $state }}</span><b aria-hidden="true">×</b>
                        </button>
                    @endif
                    @if ($type !== '')
                        <button type="button" wire:click="$set('type', '')" aria-label="Remover filtro de estabelecimento">
                            <span>{{ $type === 'matrix' ? 'Matriz' : 'Filial' }}</span><b aria-hidden="true">×</b>
                        </button>
                    @endif
                    @if ($status !== '')
                        <button type="button" wire:click="$set('status', '')" aria-label="Remover filtro de situação">
                            <span>{{ $statusLabels[$status] ?? $status }}</span><b aria-hidden="true">×</b>
                        </button>
                    @endif
                </div>
            @endif
        </div>

        <div class="eccl-results-head">
            <div role="status" aria-live="polite" aria-atomic="true">
                <strong>{{ number_format($records->total(), 0, ',', '.') }}</strong>
                {{ $records->total() === 1 ? 'empresa encontrada' : 'empresas encontradas' }}
            </div>
            <div class="eccl-results-meta">
                <span class="eccl-loading" wire:loading.delay role="status">Atualizando...</span>
                <span>{{ $records->perPage() }} por página</span>
            </div>
        </div>

        <p class="eccl-context" id="eccl-reference-note">
            Uma linha por empresa. Os filtros consideram os estabelecimentos vinculados;
            os dados abaixo são da matriz ou, na ausência dela, do primeiro estabelecimento cadastrado.
        </p>

        <div class="eccl-table-scroll" wire:loading.class="eccl-updating">
            <table class="eccl-table" aria-describedby="eccl-reference-note">
                <caption class="eccl-sr">Empresas e dados do estabelecimento de referência</caption>
                <thead>
                    <tr>
                        <th scope="col">Empresa</th>
                        <th scope="col">CNPJ de referência</th>
                        <th scope="col">Localização</th>
                        <th scope="col">CNAE principal</th>
                        <th scope="col">Situação</th>
                        <th scope="col">Origem</th>
                        <th scope="col"><span class="eccl-sr">Acessar dossiê</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $company)
                        @php
                            $establishment = $company->establishments->first();
                            $primaryCnae = $establishment?->cnaes->firstWhere('pivot.is_primary', true);
                            $units = $company->establishments->count();
                            $registration = (string) ($establishment?->registration_status ?? '');
                            $tone = match ($registration) {
                                'ATIVA' => 'active',
                                'SUSPENSA', 'INAPTA' => 'warning',
                                'BAIXADA', 'NULA' => 'inactive',
                                default => 'neutral',
                            };
                            $registrationLabel = $statusLabels[$registration]
                                ?? ($registration !== '' ? ucfirst(mb_strtolower($registration)) : 'Não informada');
                            $source = (string) ($company->source ?? '');
                            $sourceLabel = match ($source) {
                                'receita-local', 'receita_local' => 'Receita local',
                                'manual' => 'Manual',
                                default => $source !== '' ? ucfirst(str_replace('_', ' ', $source)) : 'Não informada',
                            };
                        @endphp
                        <tr wire:key="eccl-company-{{ $company->id }}">
                            <td class="eccl-company-cell">
                                <a class="eccl-company-name" href="{{ route('companies.show', $company) }}" wire:navigate>
                                    {{ $company->corporate_name }}
                                </a>
                                @if ($establishment?->fantasy_name)
                                    <span class="eccl-subtext">{{ $establishment->fantasy_name }}</span>
                                @endif
                                <span class="eccl-units">
                                    {{ number_format($units, 0, ',', '.') }}
                                    {{ $units === 1 ? 'estabelecimento' : 'estabelecimentos' }}
                                </span>
                            </td>
                            <td data-label="CNPJ de referência">
                                <span class="eccl-cnpj">
                                    {{ $establishment ? \App\Support\Cnpj::format($establishment->cnpj) : '—' }}
                                </span>
                                <span class="eccl-subtext">
                                    @if ($establishment?->type === 'matrix')
                                        Matriz
                                    @elseif ($establishment?->type === 'branch')
                                        Filial
                                    @else
                                        Sem referência
                                    @endif
                                </span>
                            </td>
                            <td data-label="Localização">
                                <span class="eccl-city">{{ $establishment?->municipality_name ?: 'Não informada' }}</span>
                                @if ($establishment?->state)
                                    <span class="eccl-uf">{{ $establishment->state }}</span>
                                @endif
                            </td>
                            <td class="eccl-cnae-cell" data-label="CNAE principal">
                                @if ($primaryCnae)
                                    <strong class="eccl-cnae-code">{{ $primaryCnae->code }}</strong>
                                    <span class="eccl-subtext">{{ $primaryCnae->description }}</span>
                                @else
                                    <span class="eccl-subtext">Não informado</span>
                                @endif
                            </td>
                            <td data-label="Situação">
                                <span class="eccl-badge eccl-{{ $tone }}">{{ $registrationLabel }}</span>
                            </td>
                            <td data-label="Origem"><span class="eccl-source">{{ $sourceLabel }}</span></td>
                            <td class="eccl-access-cell">
                                <a class="eccl-open" href="{{ route('companies.show', $company) }}" wire:navigate
                                   aria-label="Abrir dossiê de {{ $company->corporate_name }}">
                                    Abrir <span aria-hidden="true">↗</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="eccl-empty">
                                <strong>Nenhuma empresa encontrada</strong>
                                <p>Altere a pesquisa ou os filtros para consultar outros cadastros.</p>
                                @if ($hasFilters)
                                    <button type="button" class="eccl-button eccl-secondary" wire:click="clearFilters">
                                        Limpar filtros
                                    </button>
                                @else
                                    <a class="eccl-button eccl-secondary" href="{{ route('companies.create') }}" wire:navigate>
                                        Cadastrar empresa
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="eccl-pagination">
            <span>
                Exibindo <strong>{{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }}</strong>
                de <strong>{{ number_format($records->total(), 0, ',', '.') }}</strong>
            </span>
            @if ($records->hasPages())
                <nav aria-label="Páginas de empresas">
                    <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                            @disabled($records->onFirstPage()) aria-label="Página anterior">‹</button>
                    @if ($startPage > 1)
                        <button type="button" wire:click="gotoPage(1)" wire:loading.attr="disabled" aria-label="Página 1">1</button>
                        @if ($startPage > 2)
                            <span aria-hidden="true">…</span>
                        @endif
                    @endif
                    @for ($page = $startPage; $page <= $endPage; $page++)
                        <button type="button" wire:click="gotoPage({{ $page }})" wire:loading.attr="disabled"
                                aria-label="Página {{ $page }}" aria-current="{{ $page === $currentPage ? 'page' : 'false' }}">
                            {{ $page }}
                        </button>
                    @endfor
                    @if ($endPage < $lastPage)
                        @if ($endPage < $lastPage - 1)
                            <span aria-hidden="true">…</span>
                        @endif
                        <button type="button" wire:click="gotoPage({{ $lastPage }})" wire:loading.attr="disabled"
                                aria-label="Página {{ $lastPage }}">{{ $lastPage }}</button>
                    @endif
                    <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                            @disabled(! $records->hasMorePages()) aria-label="Próxima página">›</button>
                </nav>
            @endif
        </footer>
    </section>
</div>
