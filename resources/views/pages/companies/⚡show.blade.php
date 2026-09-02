<?php

use App\Models\Cnae;
use App\Models\Company;
use App\Services\EstablishmentService;
use App\Support\Cnpj;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public string $newCnaeCode = '';

public string $newCnaeDescription = '';

public bool $newCnaePrimary = false;

public bool $showCnaeForm = false;

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

public function toggleCnaeForm(): void
{
    $this->showCnaeForm =
        ! $this->showCnaeForm;

    if (! $this->showCnaeForm) {
        $this->resetCnaeForm();
    }
}

public function addCnae(
    EstablishmentService $service
): void {
    $validated = $this->validate([
        'newCnaeCode' => [
            'required',
            'string',
            'max:20',
        ],

        'newCnaeDescription' => [
            'nullable',
            'string',
            'max:255',
        ],

        'newCnaePrimary' => [
            'boolean',
        ],
    ]);

    if (! $this->matrix) {
        $this->addError(
            'newCnaeCode',
            'A empresa não possui matriz cadastrada.'
        );

        return;
    }

    try {
        $service->addCnae(
            $this->matrix,
            [
                'code' =>
                    $validated[
                        'newCnaeCode'
                    ],

                'description' =>
                    $validated[
                        'newCnaeDescription'
                    ] ?: null,

                'is_primary' =>
                    $validated[
                        'newCnaePrimary'
                    ],
            ]
        );
    } catch (\InvalidArgumentException $exception) {
        $this->addError(
            'newCnaeCode',
            $exception->getMessage()
        );

        return;
    }

    $this->reloadCompany();

    $this->resetCnaeForm();

    $this->showCnaeForm = false;

    session()->flash(
        'success',
        'CNAE adicionado com sucesso.'
    );
}

public function makeCnaePrimary(
    int $cnaeId,
    EstablishmentService $service
): void {
    if (! $this->matrix) {
        return;
    }

    $cnae = $this->matrix
        ->cnaes
        ->firstWhere(
            'id',
            $cnaeId
        );

    if (! $cnae) {
        return;
    }

    try {
        $service->setPrimaryCnae(
            $this->matrix,
            $cnae
        );
    } catch (\InvalidArgumentException) {
        return;
    }

    $this->reloadCompany();

    session()->flash(
        'success',
        'CNAE principal atualizado.'
    );
}

public function removeCnae(
    int $cnaeId,
    EstablishmentService $service
): void {
    if (! $this->matrix) {
        return;
    }

    $cnae = $this->matrix
        ->cnaes
        ->firstWhere(
            'id',
            $cnaeId
        );

    if (! $cnae) {
        return;
    }

    $service->removeCnae(
        $this->matrix,
        $cnae
    );

    $this->reloadCompany();

    session()->flash(
        'success',
        'CNAE removido.'
    );
}

private function resetCnaeForm(): void
{
    $this->newCnaeCode = '';

    $this->newCnaeDescription = '';

    $this->newCnaePrimary = false;

    $this->resetValidation([
        'newCnaeCode',
        'newCnaeDescription',
        'newCnaePrimary',
    ]);
}

private function reloadCompany(): void
{
    $this->company = $this->company
        ->fresh()
        ->load([
            'establishments.cnaes',
        ]);
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

<a
    href="{{ route('companies.edit', $company) }}"
    wire:navigate
    class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
>
    Editar
</a>

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
                    <div class="flex items-center gap-3">

    <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
        {{ $company->establishments->count() }}
    </span>

    <a
        href="{{ route('companies.branches.create', $company) }}"
        wire:navigate
        class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
    >
        + Adicionar filial
    </a>

</div>
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
<section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

    <div class="flex flex-col gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">

        <div>

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                CNAEs da matriz
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Atividades econômicas utilizadas posteriormente no cálculo do ICP.
            </p>

        </div>

        <button
            type="button"
            wire:click="toggleCnaeForm"
            class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
        >
            @if ($showCnaeForm)
                Cancelar
            @else
                + Adicionar CNAE
            @endif
        </button>

    </div>

    @if ($showCnaeForm)

        <div class="border-b border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-800 dark:bg-zinc-950/40">

            <form
                wire:submit="addCnae"
                class="space-y-4"
            >

                <div class="grid gap-4 md:grid-cols-3">

                    <div>

                        <label
                            for="newCnaeCode"
                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            Código CNAE
                        </label>

                        <input
                            id="newCnaeCode"
                            wire:model.blur="newCnaeCode"
                            placeholder="4622200"
                            maxlength="20"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                        >

                        @error('newCnaeCode')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>

                    <div class="md:col-span-2">

                        <label
                            for="newCnaeDescription"
                            class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            Descrição
                        </label>

                        <input
                            id="newCnaeDescription"
                            wire:model.blur="newCnaeDescription"
                            placeholder="Ex.: Comércio atacadista de soja"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                        >

                    </div>

                </div>

                <label class="flex cursor-pointer items-center gap-3">

                    <input
                        type="checkbox"
                        wire:model="newCnaePrimary"
                        class="h-4 w-4 rounded border-zinc-300"
                    >

                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Definir como CNAE principal
                    </span>

                </label>

                <div class="flex justify-end">

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="addCnae"
                        class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                    >

                        <span
                            wire:loading.remove
                            wire:target="addCnae"
                        >
                            Salvar CNAE
                        </span>

                        <span
                            wire:loading
                            wire:target="addCnae"
                        >
                            Salvando...
                        </span>

                    </button>

                </div>

            </form>

        </div>

    @endif

    <div class="p-5">

        @if ($this->primaryCnae)

            <div>

                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    CNAE principal
                </p>

                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                        <div>

                            <div class="flex flex-wrap items-center gap-2">

                                <span class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $this->primaryCnae->code }}
                                </span>

                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                    Principal
                                </span>

                            </div>

                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                                {{ $this->primaryCnae->description ?: 'Sem descrição' }}
                            </p>

                        </div>

                        <button
                            type="button"
                            wire:click="removeCnae({{ $this->primaryCnae->id }})"
                            wire:confirm="Deseja realmente remover este CNAE da matriz?"
                            class="text-sm font-medium text-red-600 hover:text-red-700"
                        >
                            Remover
                        </button>

                    </div>

                </div>

            </div>

        @else

            <div class="rounded-xl border border-dashed border-zinc-300 px-5 py-7 text-center dark:border-zinc-700">

                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Nenhum CNAE principal definido
                </p>

                <p class="mt-1 text-sm text-zinc-500">
                    Adicione um CNAE ou torne um CNAE secundário o principal.
                </p>

            </div>

        @endif

        @if ($this->secondaryCnaes->isNotEmpty())

            <div class="mt-6">

                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    CNAEs secundários
                </p>

                <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">

                    @foreach ($this->secondaryCnaes as $cnae)

                        <div
                            wire:key="cnae-secondary-{{ $cnae->id }}"
                            class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >

                            <div>

                                <p class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $cnae->code }}
                                </p>

                                <p class="mt-1 text-sm text-zinc-500">
                                    {{ $cnae->description ?: 'Sem descrição' }}
                                </p>

                            </div>

                            <div class="flex flex-wrap items-center gap-3">

                                <button
                                    type="button"
                                    wire:click="makeCnaePrimary({{ $cnae->id }})"
                                    class="text-sm font-medium text-blue-600 hover:text-blue-700"
                                >
                                    Tornar principal
                                </button>

                                <button
                                    type="button"
                                    wire:click="removeCnae({{ $cnae->id }})"
                                    wire:confirm="Deseja remover este CNAE da matriz?"
                                    class="text-sm font-medium text-red-600 hover:text-red-700"
                                >
                                    Remover
                                </button>

                            </div>

                        </div>

                    @endforeach

                </div>

            </div>

        @endif

        @if (
            ! $this->primaryCnae
            && $this->secondaryCnaes->isEmpty()
        )

            <div class="mt-3 text-center">

                <button
                    type="button"
                    wire:click="toggleCnaeForm"
                    class="text-sm font-medium text-blue-600 hover:text-blue-700"
                >
                    + Cadastrar primeiro CNAE
                </button>

            </div>

        @endif

    </div>

</section>

</div>
