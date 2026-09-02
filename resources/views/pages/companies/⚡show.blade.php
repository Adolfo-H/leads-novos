<?php

use App\Models\Company;
use App\Support\Cnpj;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company->load([
            'establishments.cnaes',
        ]);
    }

    #[Computed]
    public function matrix()
    {
        return $this->company
            ->establishments
            ->firstWhere('type', 'matrix')
            ?? $this->company
                ->establishments
                ->first();
    }

    #[Computed]
    public function primaryCnae()
    {
        return $this->matrix
            ?->cnaes
            ->first(
                fn ($cnae) =>
                    (bool) $cnae
                        ->pivot
                        ->is_primary
            );
    }

    #[Computed]
    public function secondaryCnaes(): Collection
    {
        if (! $this->matrix) {
            return collect();
        }

        return $this->matrix
            ->cnaes
            ->filter(
                fn ($cnae) =>
                    ! (bool) $cnae
                        ->pivot
                        ->is_primary
            )
            ->values();
    }

    public function formatMoney(
        mixed $value
    ): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return 'R$ '.number_format(
            (float) $value,
            2,
            ',',
            '.'
        );
    }
};
?>

<div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">

    {{-- CABEÇALHO --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">

        <div class="min-w-0">

            <a
                href="{{ route('companies.index') }}"
                wire:navigate
                class="text-sm font-medium text-blue-600 hover:text-blue-700"
            >
                ← Voltar para empresas
            </a>

            <div class="mt-4 flex flex-wrap items-center gap-3">

                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white sm:text-3xl">
                    {{ $company->corporate_name }}
                </h1>

                @if ($this->matrix?->registration_status === 'ATIVA')

                    <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        Ativa
                    </span>

                @elseif ($this->matrix?->registration_status)

                    <span class="inline-flex rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ $this->matrix->registration_status }}
                    </span>

                @endif

            </div>

            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">

                @if ($this->matrix)
                    <span>
                        {{ Cnpj::format($this->matrix->cnpj) }}
                    </span>
                @endif

                @if ($this->matrix?->municipality_name)

                    <span>•</span>

                    <span>
                        {{ $this->matrix->municipality_name }}

                        @if ($this->matrix->state)
                            / {{ $this->matrix->state }}
                        @endif
                    </span>

                @endif

                @if ($this->matrix?->fantasy_name)

                    <span>•</span>

                    <span>
                        {{ $this->matrix->fantasy_name }}
                    </span>

                @endif

            </div>

        </div>

        <button
            type="button"
            disabled
            title="Será habilitado quando criarmos a edição"
            class="inline-flex cursor-not-allowed items-center justify-center rounded-lg border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-400 dark:border-zinc-700"
        >
            Editar
        </button>

    </div>

    {{-- INTELIGÊNCIA COMERCIAL --}}
    <section>

        <div class="mb-3">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                Inteligência comercial
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Situação atual da empresa dentro do processo de prospecção.
            </p>

        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    CRM
                </p>

                <p class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    Não verificado
                </p>

            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    ICP
                </p>

                <p class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    Não calculado
                </p>

            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    Exp. direta
                </p>

                <p class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    Não pesquisada
                </p>

            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    Exp. indireta
                </p>

                <p class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    Não pesquisada
                </p>

            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    Trading
                </p>

                <p class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    Não pesquisada
                </p>

            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    Score
                </p>

                <p class="mt-2 text-xl font-semibold text-zinc-700 dark:text-zinc-200">
                    —
                </p>

            </div>

        </div>

    </section>

    {{-- DADOS + CONTATO --}}
    <div class="grid gap-6 xl:grid-cols-3">

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 xl:col-span-2">

            <div class="mb-5">

                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                    Dados cadastrais
                </h2>

            </div>

            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2">

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Razão social
                    </dt>

                    <dd class="mt-1.5 text-sm font-medium text-zinc-900 dark:text-white">
                        {{ $company->corporate_name }}
                    </dd>

                </div>

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Nome fantasia
                    </dt>

                    <dd class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $this->matrix?->fantasy_name ?: '—' }}
                    </dd>

                </div>

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        CNPJ raiz
                    </dt>

                    <dd class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $company->cnpj_root }}
                    </dd>

                </div>

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Capital social
                    </dt>

                    <dd class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $this->formatMoney($company->share_capital) }}
                    </dd>

                </div>

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Porte
                    </dt>

                    <dd class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $company->size_code ?: '—' }}

                        @if ($company->size_description)
                            — {{ $company->size_description }}
                        @endif
                    </dd>

                </div>

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Natureza jurídica
                    </dt>

                    <dd class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $company->legal_nature_code ?: '—' }}

                        @if ($company->legal_nature_description)
                            — {{ $company->legal_nature_description }}
                        @endif
                    </dd>

                </div>

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Origem
                    </dt>

                    <dd class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $company->source }}
                    </dd>

                </div>

                <div>

                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Última atualização
                    </dt>

                    <dd class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $company->source_updated_at?->format('d/m/Y H:i') ?? '—' }}
                    </dd>

                </div>

            </dl>

        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <div class="mb-5">

                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                    Contato cadastral
                </h2>

                <p class="mt-1 text-xs text-zinc-500">
                    Ainda não representa um decisor comercial.
                </p>

            </div>

            <div class="space-y-5">

                <div>

                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        E-mail
                    </p>

                    <p class="mt-1.5 break-all text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $this->matrix?->email ?: '—' }}
                    </p>

                </div>

                <div>

                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Telefone
                    </p>

                    <p class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $this->matrix?->phone_1 ?: '—' }}
                    </p>

                </div>

                <div>

                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Município
                    </p>

                    <p class="mt-1.5 text-sm text-zinc-700 dark:text-zinc-300">

                        {{ $this->matrix?->municipality_name ?: '—' }}

                        @if ($this->matrix?->state)
                            / {{ $this->matrix->state }}
                        @endif

                    </p>

                </div>

            </div>

        </section>

    </div>

    {{-- ESTABELECIMENTOS --}}
    <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">

            <div class="flex items-center justify-between">

                <div>

                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                        Estabelecimentos
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500">
                        Matriz e filiais vinculadas ao mesmo CNPJ raiz.
                    </p>

                </div>

                <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {{ $company->establishments->count() }}
                </span>

            </div>

        </div>

        <div class="overflow-x-auto">

            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">

                <thead class="bg-zinc-50 dark:bg-zinc-950/50">

                    <tr>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Tipo
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            CNPJ
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Nome fantasia
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Localização
                        </th>

                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Situação
                        </th>

                    </tr>

                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">

                    @forelse ($company->establishments as $establishment)

                        <tr wire:key="establishment-{{ $establishment->id }}">

                            <td class="px-5 py-4">

                                @if ($establishment->type === 'matrix')

                                    <span class="inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                        Matriz
                                    </span>

                                @else

                                    <span class="inline-flex rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        Filial
                                    </span>

                                @endif

                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ Cnpj::format($establishment->cnpj) }}
                            </td>

                            <td class="px-5 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $establishment->fantasy_name ?: '—' }}
                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-sm text-zinc-700 dark:text-zinc-300">

                                {{ $establishment->municipality_name ?: '—' }}

                                @if ($establishment->state)
                                    / {{ $establishment->state }}
                                @endif

                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $establishment->registration_status ?: '—' }}
                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td
                                colspan="5"
                                class="px-5 py-10 text-center text-sm text-zinc-500"
                            >
                                Nenhum estabelecimento encontrado.
                            </td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </section>

    {{-- CNAES --}}
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        <div class="mb-5">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                CNAEs da matriz
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Esses dados serão utilizados posteriormente no cálculo do ICP.
            </p>

        </div>

        @if ($this->primaryCnae)

            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">

                <p class="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">
                    Principal
                </p>

                <div class="mt-2 font-semibold text-zinc-900 dark:text-white">
                    {{ $this->primaryCnae->code }}
                </div>

                <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ $this->primaryCnae->description ?: 'Sem descrição' }}
                </div>

            </div>

        @else

            <div class="rounded-xl border border-dashed border-zinc-300 px-5 py-8 text-center dark:border-zinc-700">

                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    CNAE principal ainda não cadastrado
                </p>

                <p class="mt-1 text-sm text-zinc-500">
                    Será preenchido manualmente ou durante o enriquecimento cadastral.
                </p>

            </div>

        @endif

        @if ($this->secondaryCnaes->isNotEmpty())

            <div class="mt-5">

                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    CNAEs secundários
                </p>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">

                    @foreach ($this->secondaryCnaes as $cnae)

                        <div
                            wire:key="cnae-{{ $cnae->id }}"
                            class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
                        >

                            <div class="text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ $cnae->code }}
                            </div>

                            <div class="mt-1 text-xs text-zinc-500">
                                {{ $cnae->description ?: 'Sem descrição' }}
                            </div>

                        </div>

                    @endforeach

                </div>

            </div>

        @endif

    </section>

</div>
