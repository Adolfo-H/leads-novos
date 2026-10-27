<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Support\Cnpj;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public string $state = '';

    public string $type = '';

    public string $status = '';

    #[On('companies-topbar-search')]
    public function companiesTopbarSearch(string $term): void
    {
        $this->search = trim(mb_substr($term, 0, 120));
        $this->resetPage();
    }

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
            ->withCount(
                'establishments'
            )

            ->with([
                'matrix.cnaes',
                'oldestEstablishment.cnaes',
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
                $this->state !== ''
                || $this->type !== ''
                || $this->status !== '',
                function ($query): void {
                    $query->whereHas(
                        'establishments',
                        function (
                            $establishmentQuery
                        ): void {
                            $establishmentQuery
                                ->when(
                                    $this->state !== '',
                                    fn ($query) =>
                                        $query->where(
                                            'state',
                                            $this->state
                                        )
                                )

                                ->when(
                                    $this->type !== '',
                                    fn ($query) =>
                                        $query->where(
                                            'type',
                                            $this->type
                                        )
                                )

                                ->when(
                                    $this->status !== '',
                                    fn ($query) =>
                                        $query->where(
                                            'registration_status',
                                            $this->status
                                        )
                                );
                        }
                    );
                }
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

{{-- EMPRESAS_FIGMA_V64_E01: apenas apresentação; os filtros e a consulta seguem no componente Volt original. --}}
<div class="eccl-page eccl-v26 ec-e01-page" data-ec-companies-figma="E01-V64">
    @php
        $records = $this->companies;
        $ufs = $this->states;
        $hasFilters = $search !== '' || $state !== '' || $type !== '' || $status !== '';
        $statusLabels = ['ATIVA' => 'Ativa', 'SUSPENSA' => 'Suspensa', 'INAPTA' => 'Inapta', 'BAIXADA' => 'Baixada', 'NULA' => 'Nula'];
        $number = static fn ($value) => number_format((int) $value, 0, ',', '.');
        $companiesTotal = \App\Models\Company::query()->count();
        $establishmentsTotal = \App\Models\Establishment::query()->count();
        $activeEstablishmentsTotal = \App\Models\Establishment::query()->where('registration_status', 'ATIVA')->count();
    @endphp

    <header class="ec-e01-header eccl-header">
        <div>
            <span class="eccl-eyebrow">EMPRESAS</span>
            <h1>Sua base de empresas</h1>
            <p>Uma linha por empresa, com matriz e filiais no mesmo dossiê.</p>
            <a class="eccl-button eccl-primary ec-e01-primary" href="{{ route('companies.create') }}" wire:navigate>Nova empresa</a>
        </div>
    </header>

    @if (session('success'))
        <div class="eccl-success" role="status">{{ session('success') }}</div>
    @endif

    <section class="ec-e01-stats" aria-label="Indicadores da base empresarial">
        <article class="ec-e01-stat"><span>Empresas locais</span><strong>{{ $number($companiesTotal) }}</strong></article>
        <article class="ec-e01-stat"><span>Estabelecimentos</span><strong>{{ $number($establishmentsTotal) }}</strong></article>
        <article class="ec-e01-stat"><span>Ativos</span><strong>{{ $number($activeEstablishmentsTotal) }}</strong></article>
    </section>

    <section class="eccl-panel ec-e01-filter-card" aria-label="Pesquisa e filtros de empresas">
        <div class="eccl-toolbar">
            <div class="eccl-toolbar-title">
                <h2>Pesquisar e filtrar</h2>
                @if ($hasFilters)
                    <button type="button" class="eccl-text-button" wire:click="clearFilters" wire:loading.attr="disabled">Limpar filtros</button>
                @endif
            </div>
            <div class="eccl-filters">
                <div class="eccl-field eccl-search-field">
                    <label for="eccl-search">Empresa ou CNPJ</label>
                    <input id="eccl-search" type="search" wire:model.live.debounce.400ms="search"
                           placeholder="Pesquisar empresa ou CNPJ..." autocomplete="off">
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
                        <button type="button" wire:click="$set('search','')" aria-label="Remover busca"><span>Busca: {{ $search }}</span><b aria-hidden="true">×</b></button>
                    @endif
                    @if ($state !== '')
                        <button type="button" wire:click="$set('state','')" aria-label="Remover UF"><span>UF: {{ $state }}</span><b aria-hidden="true">×</b></button>
                    @endif
                    @if ($type !== '')
                        <button type="button" wire:click="$set('type','')" aria-label="Remover estabelecimento"><span>{{ $type === 'matrix' ? 'Matriz' : 'Filial' }}</span><b aria-hidden="true">×</b></button>
                    @endif
                    @if ($status !== '')
                        <button type="button" wire:click="$set('status','')" aria-label="Remover situação"><span>{{ $statusLabels[$status] ?? $status }}</span><b aria-hidden="true">×</b></button>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <section class="eccl-v26-results-panel ec-e01-results" aria-label="Empresas cadastradas">
        <div class="eccl-results-head ec-e01-results-head">
            <h2>Empresas cadastradas</h2>
            <div class="eccl-results-meta" role="status" aria-live="polite">
                <span class="eccl-loading" wire:loading.delay>Atualizando...</span>
                <strong>{{ $number($records->total()) }}</strong> {{ $records->total() === 1 ? 'empresa' : 'empresas' }}
            </div>
        </div>
        <p class="eccl-context ec-e01-visually-hidden" id="eccl-reference-note">
            Uma linha por empresa. Os filtros consideram estabelecimentos vinculados e os dados são da matriz ou estabelecimento de referência.
        </p>
        <div class="eccl-table-scroll ec-e01-table-scroll" wire:loading.class="eccl-updating">
            <table class="eccl-table ec-e01-table" aria-describedby="eccl-reference-note">
                <caption class="eccl-sr">Empresas e unidades cadastradas</caption>
                <thead>
                    <tr>
                        <th scope="col">Empresa / unidades</th>
                        <th scope="col">CNPJ de referência</th>
                        <th scope="col">Localização</th>
                        <th scope="col">CNAE principal</th>
                        <th scope="col">Situação / Origem</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $company)
                        @php
                            $establishment = $company->matrix ?? $company->oldestEstablishment;
                            $primaryCnae = $establishment?->cnaes?->firstWhere('pivot.is_primary', true);
                            $units = (int) ($company->establishments_count ?? 0);
                            $registration = (string) ($establishment?->registration_status ?? '');
                            $registrationLabel = $statusLabels[$registration] ?? ($registration !== '' ? ucfirst(mb_strtolower($registration)) : 'Não informada');
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
                                <span class="eccl-units">{{ $number($units) }} {{ $units === 1 ? 'estabelecimento' : 'estabelecimentos' }}</span>
                            </td>
                            <td data-label="CNPJ de referência">
                                <span class="eccl-cnpj">{{ $establishment ? \App\Support\Cnpj::format($establishment->cnpj) : '—' }}</span>
                                <span class="eccl-subtext">{{ match ($establishment?->type) { 'matrix' => 'Matriz', 'branch' => 'Filial', default => 'Sem referência' } }}</span>
                            </td>
                            <td data-label="Localização">
                                <span>{{ $establishment?->municipality_name ?: 'Não informada' }}</span>
                                @if ($establishment?->state)
                                    <span class="ec-e01-uf">· {{ $establishment->state }}</span>
                                @endif
                            </td>
                            <td class="eccl-cnae-cell" data-label="CNAE principal">
                                @if ($primaryCnae)
                                    <strong class="eccl-cnae-code">{{ $primaryCnae->code }}</strong>
                                    <span class="ec-e01-cnae-label">· {{ $primaryCnae->description }}</span>
                                @else
                                    <span>Não informado</span>
                                @endif
                            </td>
                            <td data-label="Situação / Origem">
                                <span class="ec-e01-status">{{ $registrationLabel }}</span>
                                <span class="ec-e01-origin">· {{ $sourceLabel }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="eccl-empty">
                                <strong>Nenhuma empresa encontrada</strong>
                                <p>Altere a pesquisa ou os filtros para consultar outros cadastros.</p>
                                @if ($hasFilters)
                                    <button type="button" class="eccl-button eccl-secondary" wire:click="clearFilters">Limpar filtros</button>
                                @else
                                    <a class="eccl-button eccl-secondary" href="{{ route('companies.create') }}" wire:navigate>Cadastrar empresa</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="eccl-pagination ec-e01-pagination">
            <span>
                {{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} de {{ $number($records->total()) }} empresas ·
                Página {{ $records->currentPage() }} de {{ $records->lastPage() }}
            </span>
            <nav aria-label="Páginas de empresas">
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled" @disabled($records->onFirstPage())>Anterior</button>
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled" @disabled(! $records->hasMorePages())>Próxima</button>
            </nav>
        </footer>
    </section>

    <div class="ec-e01-explain">
        <span aria-hidden="true">✓</span>
        <div><strong>Como ler os filtros</strong>
            <p>Uma filial pode corresponder ao filtro, enquanto a linha apresenta os dados da matriz ou do estabelecimento de referência.</p>
        </div>
    </div>
    <footer class="ec-e01-footer">ExportControl Prospector · Dados locais e vínculos confirmados</footer>
</div>
