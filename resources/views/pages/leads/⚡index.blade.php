<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyExportIntelligence;
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

    public string $workStatus = '';

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

    public function updatedWorkStatus(): void
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
            'workStatus',
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
            ->leftJoin(
                'company_hubspot_leads as work',
                'work.company_id',
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
                function ($query): void {
                    $query
                        ->where(
                            'sdr.is_eligible',
                            true
                        )
                        ->orWhereNotNull(
                            'work.hubspot_deal_id'
                        );
                }
            )
            ->with([
                'matrix',
                'icpScore',
                'crmCheck',
                'sdrScore',
                'exportIntelligence',
                'hubSpotLead',
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
            ->when(
                $this->workStatus !== '',
                fn ($query) =>
                    $query->where(
                        'work.work_status',
                        $this->workStatus
                    )
            )
            /*
             * Fila operacional vinda do HubSpot:
             *
             * 0 - aguardando retorno
             * 1 - em contato
             * 2 - novo
             * 3 - descartado
             */
            ->orderByRaw(
                "
                CASE
                    WHEN work.work_status = 'waiting'
                        THEN 0
                    WHEN work.work_status = 'contacting'
                        THEN 1
                    WHEN work.work_status = 'new'
                        THEN 2
                    WHEN work.work_status = 'discarded'
                        THEN 3
                    ELSE 4
                END
                "
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

    public function workStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'contacting' =>
                'Em contato',

            'waiting' =>
                'Aguardando retorno',

            'discarded' =>
                'Descartado',

            default =>
                'Novo',
        };
    }

    public function workStatusClass(
        ?string $status
    ): string {
        return match ($status) {
            'contacting' =>
                'text-cyan-300',

            'waiting' =>
                'text-amber-300',

            'discarded' =>
                'text-red-300',

            default =>
                'text-emerald-300',
        };
    }

    #[Computed]
    public function newCount(): int
    {
        return CompanyHubSpotLead::query()
            ->where(
                'work_status',
                'new'
            )
            ->count();
    }

    #[Computed]
    public function contactingCount(): int
    {
        return CompanyHubSpotLead::query()
            ->where(
                'work_status',
                'contacting'
            )
            ->count();
    }

    #[Computed]
    public function waitingCount(): int
    {
        return CompanyHubSpotLead::query()
            ->where(
                'work_status',
                'waiting'
            )
            ->count();
    }

    #[Computed]
    public function discardedCount(): int
    {
        return CompanyHubSpotLead::query()
            ->where(
                'work_status',
                'discarded'
            )
            ->count();
    }

    public function applyQuickView(
        string $view
    ): void {
        $this->workStatus =
            in_array(
                $view,
                [
                    'new',
                    'contacting',
                    'waiting',
                    'discarded',
                ],
                true
            )
                ? $view
                : '';

        $this->resetPage();
    }

    public function crmLabel(
        ?string $status
    ): string {
        return match ($status) {
            'client' =>
                'Cliente',

            'opportunity' =>
                'Oportunidade',

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

    public function hubSpotCompanyUrl(
        ?CompanyCrmCheck $crm
    ): ?string {
        if ($crm === null) {
            return null;
        }

        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        $companyId =
            trim(
                (string) $crm
                    ->external_id
            );

        if (
            $portalId !== ''
            && $companyId !== ''
        ) {
            return sprintf(
                'https://app.hubspot.com/contacts/%s/record/0-2/%s',
                rawurlencode(
                    $portalId
                ),
                rawurlencode(
                    $companyId
                ),
            );
        }

        $externalUrl =
            trim(
                (string) $crm
                    ->external_url
            );

        if (
            $externalUrl !== ''
            && filter_var(
                $externalUrl,
                FILTER_VALIDATE_URL
            ) !== false
        ) {
            return $externalUrl;
        }

        return null;
    }

    public function exportLabel(
        ?CompanyExportIntelligence $export
    ): string {
        if ($export === null) {
            return 'Não pesquisada';
        }

        if (
            $export->research_status
            !== 'completed'
        ) {
            return match (
                $export->research_status
            ) {
                'queued' =>
                    'Na fila',

                'processing' =>
                    'Pesquisando',

                'failed' =>
                    'Pesquisa falhou',

                default =>
                    'Não pesquisada',
            };
        }

        if (
            $export->direct_status === 'yes'
            || $export->indirect_status === 'yes'
            || $export->trading_status === 'yes'
        ) {
            return 'Atuação identificada';
        }

        return 'Não comprovada';
    }

    /**
     * @return list<array{
     *     label: string,
     *     detail: string,
     *     points: int
     * }>
     */
    public function scoreReasons(
        ?CompanySdrScore $score
    ): array {
        $factors =
            $score
                ?->factors;

        if (! is_array($factors)) {
            return [];
        }

        $reasons = [];

        foreach ($factors as $factor) {
            if (! is_array($factor)) {
                continue;
            }

            $points =
                is_numeric(
                    $factor[
                        'points'
                    ]
                    ?? null
                )
                    ? (int) $factor[
                        'points'
                    ]
                    : 0;

            if ($points <= 0) {
                continue;
            }

            $label =
                trim(
                    (string) (
                        $factor[
                            'label'
                        ]
                        ?? ''
                    )
                );

            if ($label === '') {
                continue;
            }

            $reasons[] = [
                'label' =>
                    $label,

                'detail' =>
                    trim(
                        (string) (
                            $factor[
                                'detail'
                            ]
                            ?? ''
                        )
                    ),

                'points' =>
                    $points,
            ];
        }

        usort(
            $reasons,
            static fn (
                array $a,
                array $b
            ): int =>
                $b[
                    'points'
                ]
                <=>
                $a[
                    'points'
                ]
        );

        return array_slice(
            $reasons,
            0,
            3
        );
    }

};
?>

<div
    class="ec-page-shell"
    wire:poll.15s="$refresh"
>

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


    {{-- PAINEL DIÁRIO --}}
    <section class="mt-5">

        <div
            class="
                mb-3 flex flex-wrap
                items-end justify-between
                gap-3
            "
        >
            <div>
                <div class="ec-page-kicker">
                    Operação SDR
                </div>

                <div
                    class="
                        mt-1 text-sm
                        font-semibold
                        text-[#eef1ff]
                    "
                >
                    O que precisa da sua atenção
                </div>
            </div>
                


            


            <button
                type="button"
                wire:click="applyQuickView('new')"
                class="
                    ec-intelligence-card
                    text-left transition
                    hover:border-cyan-300/20
                    hover:bg-cyan-300/[0.03]
                "
            >
                <div class="ec-intelligence-label">
                    Novos
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold text-cyan-300
                    "
                >
                    {{ $this->newCount }}
                </div>

                <div class="ec-intelligence-caption">
                    Ainda não trabalhados
                </div>
            </button>


            <button
                type="button"
                wire:click="applyQuickView('contacting')"
                class="
                    ec-intelligence-card
                    text-left transition
                    hover:bg-white/[0.04]
                "
            >
                <div class="ec-intelligence-label">
                    Em contato
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold text-[#eef1ff]
                    "
                >
                    {{ $this->contactingCount }}
                </div>

                <div class="ec-intelligence-caption">
                    Abordagem em andamento
                </div>
            </button>


            <button
                type="button"
                wire:click="applyQuickView('waiting')"
                class="
                    ec-intelligence-card
                    text-left transition
                    hover:bg-white/[0.04]
                "
            >
                <div class="ec-intelligence-label">
                    Aguardando retorno
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold text-[#eef1ff]
                    "
                >
                    {{ $this->waitingCount }}
                </div>

                <div class="ec-intelligence-caption">
                    Follow-up pendente
                </div>
            </button>


            <button
                type="button"
                wire:click="applyQuickView('discarded')"
                class="
                    ec-intelligence-card
                    text-left transition
                    hover:border-emerald-300/20
                    hover:bg-emerald-300/[0.03]
                "
            >
                <div class="ec-intelligence-label">
                    Descartados
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold text-emerald-300
                    "
                >
                    {{ $this->discardedCount }}
                </div>

                <div class="ec-intelligence-caption">
                    Resultado comercial
                </div>
            </button>

        </div>

    </section>


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
                xl:grid-cols-8
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

                <option value="opportunity">
                    Oportunidade
                </option>

                <option value="client">
                    Cliente
                </option>
            </select>

            


            <select
                wire:model.live="workStatus"
                class="
                    rounded-xl border
                    border-white/[0.08]
                    bg-[#151a36]
                    px-3 py-2.5
                    text-sm text-[#d9ddef]
                "
            >
                <option value="">
                    Todo acompanhamento
                </option>

                <option value="new">
                    Novo
                </option>

                <option value="contacting">
                    Em contato
                </option>

                <option value="waiting">
                    Aguardando retorno
                </option>

                <option value="discarded">
                    Descartado
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
            || $workStatus !== ''
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

                $hubSpotLead =
                    $lead->hubSpotLead;

                $currentWorkStatus =
                    $hubSpotLead?->work_status
                    ?? 'new';

                $snapshot =
                    data_get(
                        $hubSpotLead?->metadata
                            ?? [],
                        'qualification_snapshot',
                        []
                    );

                if (! is_array($snapshot)) {
                    $snapshot = [];
                }

                $displayScore =
                    is_numeric(
                        $snapshot['score']
                            ?? null
                    )
                        ? (int) $snapshot['score']
                        : (int) (
                            $score?->score
                            ?? 0
                        );

                $displayPriority =
                    is_string(
                        $snapshot['priority']
                            ?? null
                    )
                        ? $snapshot['priority']
                        : $score?->priority;

            @endphp

            <div
                class="
                    grid gap-4
                    border-b border-white/[0.05]
                    px-5 py-5
                    transition
                    last:border-b-0
                    hover:bg-white/[0.025]
                    lg:grid-cols-[minmax(0,2.5fr)_100px_80px_120px_160px_140px_180px]
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

                    @php
                        $scoreReasons =
                            $this->scoreReasons(
                                $score
                            );

                        $hubSpotUrl =
                            $this->hubSpotCompanyUrl(
                                $crmCheck
                            );
                    @endphp

                    @if ($scoreReasons !== [])

                        <div
                            class="
                                mt-3 flex flex-wrap
                                gap-1.5
                            "
                        >

                            @foreach (
                                $scoreReasons
                                as $reason
                            )

                                <span
                                    class="
                                        rounded-md
                                        border border-white/[0.06]
                                        bg-white/[0.025]
                                        px-2 py-1
                                        text-[10px]
                                        text-[#9da6c5]
                                    "
                                    title="{{ $reason['detail'] }}"
                                >
                                    {{ $reason['label'] }}

                                    <strong
                                        class="text-cyan-300"
                                    >
                                        +{{ $reason['points'] }}
                                    </strong>
                                </span>

                            @endforeach

                        </div>

                    @endif

                    <div
                        class="
                            mt-3 flex flex-wrap
                            items-center gap-3
                        "
                    >

                        <a
                            href="{{
                                route(
                                    'companies.show',
                                    $lead
                                )
                            }}"
                            wire:navigate
                            class="
                                text-xs font-semibold
                                text-cyan-300
                                hover:text-cyan-200
                            "
                        >
                            Abrir dossiê →
                        </a>

                        @if ($hubSpotUrl)

                            <a
                                href="{{ $hubSpotUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="
                                    text-xs font-semibold
                                    text-[#9ba5c8]
                                    hover:text-white
                                "
                            >
                                Abrir HubSpot ↗
                            </a>

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
                            $displayScore
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
                        Exportação
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            font-semibold
                            {{
                                $this->exportLabel(
                                    $export
                                ) === 'Atuação identificada'
                                    ? 'text-emerald-300'
                                    : 'text-[#cbd1e7]'
                            }}
                        "
                    >
                        {{
                            $this->exportLabel(
                                $export
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
                                    $displayPriority
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
                                    $displayPriority
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
                        Acompanhamento
                    </div>

                    <div
                        class="
                            mt-1 inline-flex
                            rounded-lg
                            border border-white/[0.08]
                            bg-white/[0.03]
                            px-2.5 py-2
                            text-xs font-semibold
                            {{
                                $this->workStatusClass(
                                    $currentWorkStatus
                                )
                            }}
                        "
                    >
                        {{
                            $this->workStatusLabel(
                                $currentWorkStatus
                            )
                        }}
                    </div>

                                        @if (
                        $hubSpotLead
                            ?->last_activity_at
                    )

                        <div
                            class="
                                mt-3 text-[10px]
                                text-[#697394]
                            "
                        >
                            Último contato no HubSpot ·
                            {{
                                $hubSpotLead
                                    ->last_activity_at
                                    ->format(
                                        'd/m/Y H:i'
                                    )
                            }}
                        </div>

                    @endif



                </div>

            </div>

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
