<?php

use App\Models\Company;
use App\Models\CompanySdrScore;
use App\Models\Establishment;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $priority = '';

    public string $icp = '';

    public string $crm = '';

    public string $state = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedIcp(): void
    {
        $this->resetPage();
    }

    public function updatedCrm(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search',
            'priority',
            'icp',
            'crm',
            'state',
        ]);

        $this->resetPage();
    }

    #[Computed]
    public function leads()
    {
        $search =
            trim(
                $this->search
            );

        return Company::query()
            ->select(
                'companies.*'
            )
            ->join(
                'company_sdr_scores as sdr',
                'sdr.company_id',
                '=',
                'companies.id'
            )
            /*
             * A tela Leads mostra somente
             * empresas comercialmente elegíveis.
             *
             * Cliente, oportunidade ativa e
             * bloqueios de cooldown ficam fora.
             */
            ->where(
                'sdr.is_eligible',
                true
            )
            ->with([
                'matrix',
                'icpScore',
                'crmCheck',
                'sdrScore',
                'exportIntelligence',
            ])
            ->when(
                $search !== '',
                function ($query) use ($search) {
                    $normalized =
                        mb_strtolower(
                            $search
                        );

                    $cnpj =
                        preg_replace(
                            '/[^A-Z0-9]/i',
                            '',
                            mb_strtoupper(
                                $search
                            )
                        );

                    $query->where(
                        function ($subQuery) use (
                            $normalized,
                            $cnpj
                        ) {
                            $subQuery
                                ->whereRaw(
                                    'LOWER(companies.corporate_name) LIKE ?',
                                    [
                                        '%'
                                        .$normalized
                                        .'%',
                                    ]
                                )
                                ->orWhere(
                                    'companies.cnpj_root',
                                    'like',
                                    '%'.$cnpj.'%'
                                );
                        }
                    );
                }
            )
            ->when(
                $this->priority !== '',
                fn ($query) =>
                    $query->where(
                        'sdr.priority',
                        $this->priority
                    )
            )
            ->when(
                $this->icp !== '',
                fn ($query) =>
                    $query->whereHas(
                        'icpScore',
                        fn ($icpQuery) =>
                            $icpQuery->where(
                                'grade',
                                $this->icp
                            )
                    )
            )
            ->when(
                $this->crm !== '',
                fn ($query) =>
                    $query->whereHas(
                        'crmCheck',
                        fn ($crmQuery) =>
                            $crmQuery->where(
                                'status',
                                $this->crm
                            )
                    )
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
            ->orderByDesc(
                'sdr.score'
            )
            ->orderBy(
                'companies.corporate_name'
            )
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

    #[Computed]
    public function eligibleCount(): int
    {
        return CompanySdrScore::query()
            ->where(
                'is_eligible',
                true
            )
            ->count();
    }

    #[Computed]
    public function veryHighCount(): int
    {
        return CompanySdrScore::query()
            ->where(
                'is_eligible',
                true
            )
            ->where(
                'priority',
                'very_high'
            )
            ->count();
    }

    #[Computed]
    public function highCount(): int
    {
        return CompanySdrScore::query()
            ->where(
                'is_eligible',
                true
            )
            ->where(
                'priority',
                'high'
            )
            ->count();
    }

    public function crmLabel(
        ?string $status
    ): string {
        return match ($status) {
            'prospected' =>
                'Reprospecção',

            'known' =>
                'Conhecido',

            'not_found' =>
                'Novo',

            default =>
                'Não verificado',
        };
    }

    public function priorityLabel(
        ?string $priority
    ): string {
        return match ($priority) {
            'very_high' =>
                'Muito alta',

            'high' =>
                'Alta',

            'medium' =>
                'Média',

            default =>
                'Baixa',
        };
    }
};
?>

<div class="ec-page-shell">

    <div class="ec-page-header">

        <div>

            <div class="ec-page-kicker">
                Prioridade Comercial
            </div>

            <div
                class="
                    mt-1 flex flex-wrap
                    items-center gap-3
                "
            >

                <h1 class="ec-page-title">
                    Leads
                </h1>

                <span class="ec-count-badge">
                    {{ $this->eligibleCount }}
                </span>

            </div>

            <p class="ec-page-description">
                Empresas elegíveis ordenadas pela
                prioridade calculada para o SDR.
            </p>

        </div>

    </div>


    {{-- RESUMO --}}
    <div
        class="
            grid gap-3
            md:grid-cols-3
        "
    >

        <div class="ec-intelligence-card">

            <div class="ec-intelligence-label">
                Leads elegíveis
            </div>

            <div class="ec-score-value">
                {{ $this->eligibleCount }}
            </div>

            <div class="ec-intelligence-caption">
                Disponíveis para qualificação
            </div>

        </div>


        <div class="ec-intelligence-card">

            <div class="ec-intelligence-label">
                Prioridade muito alta
            </div>

            <div class="ec-score-value">
                {{ $this->veryHighCount }}
            </div>

            <div class="ec-intelligence-caption">
                Atacar primeiro
            </div>

        </div>


        <div class="ec-intelligence-card">

            <div class="ec-intelligence-label">
                Prioridade alta
            </div>

            <div class="ec-score-value">
                {{ $this->highCount }}
            </div>

            <div class="ec-intelligence-caption">
                Segunda faixa comercial
            </div>

        </div>

    </div>


    {{-- FILTROS --}}
    <div
        class="
            mt-5 rounded-2xl
            border border-white/[0.06]
            bg-white/[0.025]
            p-4
        "
    >

        <div
            class="
                grid gap-3
                md:grid-cols-2
                xl:grid-cols-6
            "
        >

            <input
                type="search"
                wire:model.live.debounce.350ms="search"
                placeholder="Empresa ou CNPJ..."
                class="
                    rounded-xl border
                    border-white/[0.08]
                    bg-white/[0.035]
                    px-3 py-2.5
                    text-sm text-[#eef1ff]
                    outline-none
                    placeholder:text-[#66708f]
                    xl:col-span-2
                "
            >

            <select
                wire:model.live="priority"
                class="
                    rounded-xl border
                    border-white/[0.08]
                    bg-[#151a36]
                    px-3 py-2.5
                    text-sm text-[#d9ddef]
                "
            >
                <option value="">
                    Todas prioridades
                </option>

                <option value="very_high">
                    Muito alta
                </option>

                <option value="high">
                    Alta
                </option>

                <option value="medium">
                    Média
                </option>

                <option value="low">
                    Baixa
                </option>
            </select>

            <select
                wire:model.live="icp"
                class="
                    rounded-xl border
                    border-white/[0.08]
                    bg-[#151a36]
                    px-3 py-2.5
                    text-sm text-[#d9ddef]
                "
            >
                <option value="">
                    Todos ICP
                </option>

                @foreach (
                    ['A', 'B', 'C', 'D']
                    as $grade
                )
                    <option value="{{ $grade }}">
                        ICP {{ $grade }}
                    </option>
                @endforeach
            </select>

            <select
                wire:model.live="crm"
                class="
                    rounded-xl border
                    border-white/[0.08]
                    bg-[#151a36]
                    px-3 py-2.5
                    text-sm text-[#d9ddef]
                "
            >
                <option value="">
                    Todo CRM
                </option>

                <option value="not_found">
                    Novo
                </option>

                <option value="known">
                    Conhecido
                </option>

                <option value="prospected">
                    Reprospecção
                </option>
            </select>

            <select
                wire:model.live="state"
                class="
                    rounded-xl border
                    border-white/[0.08]
                    bg-[#151a36]
                    px-3 py-2.5
                    text-sm text-[#d9ddef]
                "
            >
                <option value="">
                    Todos estados
                </option>

                @foreach (
                    $this->states
                    as $stateOption
                )
                    <option
                        value="{{ $stateOption }}"
                    >
                        {{ $stateOption }}
                    </option>
                @endforeach
            </select>

        </div>


        @if (
            $search !== ''
            || $priority !== ''
            || $icp !== ''
            || $crm !== ''
            || $state !== ''
        )

            <button
                type="button"
                wire:click="clearFilters"
                class="
                    mt-3 text-xs
                    font-semibold
                    text-cyan-300
                    hover:text-cyan-200
                "
            >
                Limpar filtros
            </button>

        @endif

    </div>


    {{-- LISTA --}}
    <div
        class="
            mt-5 overflow-hidden
            rounded-2xl
            border border-white/[0.06]
            bg-white/[0.02]
        "
    >

        @forelse (
            $this->leads
            as $lead
        )

            @php
                $score =
                    $lead->sdrScore;

                $icpScore =
                    $lead->icpScore;

                $crmCheck =
                    $lead->crmCheck;

                $export =
                    $lead->exportIntelligence;

                $matrix =
                    $lead->matrix;
            @endphp

            <a
                href="{{
                    route(
                        'companies.show',
                        $lead
                    )
                }}"
                wire:navigate
                class="
                    grid gap-4
                    border-b border-white/[0.05]
                    px-5 py-4
                    transition
                    last:border-b-0
                    hover:bg-white/[0.025]
                    lg:grid-cols-[minmax(0,2fr)_110px_100px_130px_160px]
                    lg:items-center
                "
            >

                <div class="min-w-0">

                    <div
                        class="
                            truncate text-sm
                            font-semibold
                            text-[#eef1ff]
                        "
                    >
                        {{ $lead->corporate_name }}
                    </div>

                    <div
                        class="
                            mt-1 flex flex-wrap
                            items-center gap-2
                            text-xs text-[#7781a2]
                        "
                    >

                        @if ($matrix?->state)

                            <span>
                                {{ $matrix->state }}
                            </span>

                        @endif

                        @if (
                            $matrix?->municipality_name
                        )

                            <span>•</span>

                            <span>
                                {{
                                    $matrix
                                        ->municipality_name
                                }}
                            </span>

                        @endif

                    </div>

                </div>


                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            tracking-wide
                            text-[#697394]
                        "
                    >
                        Score
                    </div>

                    <div
                        class="
                            mt-1 text-lg
                            font-bold
                            text-cyan-300
                        "
                    >
                        {{
                            $score?->score
                            ?? 0
                        }}/100
                    </div>

                </div>


                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            tracking-wide
                            text-[#697394]
                        "
                    >
                        ICP
                    </div>

                    <div
                        class="
                            mt-1 text-sm
                            font-bold
                            text-[#e5e9fa]
                        "
                    >
                        {{
                            $icpScore?->grade
                            ?? '—'
                        }}
                    </div>

                </div>


                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            tracking-wide
                            text-[#697394]
                        "
                    >
                        CRM
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            font-semibold
                            text-[#cbd1e7]
                        "
                    >
                        {{
                            $this->crmLabel(
                                $crmCheck?->status
                            )
                        }}
                    </div>

                </div>


                <div>

                    <div
                        class="
                            text-[10px]
                            font-semibold
                            uppercase
                            tracking-wide
                            text-[#697394]
                        "
                    >
                        Prioridade
                    </div>

                    <div
                        class="
                            mt-1 text-sm
                            font-bold
                            {{
                                match (
                                    $score?->priority
                                ) {
                                    'very_high' =>
                                        'text-emerald-300',

                                    'high' =>
                                        'text-cyan-300',

                                    'medium' =>
                                        'text-amber-300',

                                    default =>
                                        'text-[#9ca5c5]',
                                }
                            }}
                        "
                    >
                        {{
                            $this
                                ->priorityLabel(
                                    $score?->priority
                                )
                        }}
                    </div>

                    @if (
                        $score
                        ?->is_provisional
                    )

                        <div
                            class="
                                mt-0.5 text-[10px]
                                text-[#687394]
                            "
                        >
                            score provisório
                        </div>

                    @endif

                </div>

            </a>

        @empty

            <div
                class="
                    px-6 py-14
                    text-center
                "
            >

                <div
                    class="
                        text-sm font-semibold
                        text-[#c8cee3]
                    "
                >
                    Nenhum lead elegível encontrado
                </div>

                <div
                    class="
                        mt-1 text-xs
                        text-[#707a9d]
                    "
                >
                    Ajuste os filtros ou importe
                    novas empresas.
                </div>

            </div>

        @endforelse

    </div>


    <div class="mt-5">
        {{ $this->leads->links() }}
    </div>

</div>
