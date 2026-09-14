<?php

use App\Models\Company;
use App\Models\CompanyLeadWorkState;
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

    public string $followUp = '';

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

    public function updatedFollowUp(): void
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
            'followUp',
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
                'company_lead_work_states as work',
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
                'sdr.is_eligible',
                true
            )
            ->with([
                'matrix',
                'icpScore',
                'crmCheck',
                'sdrScore',
                'exportIntelligence',
                'leadWorkState.assignedUser',
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
                function ($query): void {
                    if (
                        $this->workStatus
                        === 'new'
                    ) {
                        $query->where(
                            function ($statusQuery): void {
                                $statusQuery
                                    ->whereDoesntHave(
                                        'leadWorkState'
                                    )
                                    ->orWhereHas(
                                        'leadWorkState',
                                        fn ($workQuery) =>
                                            $workQuery->where(
                                                'status',
                                                'new'
                                            )
                                    );
                            }
                        );

                        return;
                    }

                    $query->whereHas(
                        'leadWorkState',
                        fn ($workQuery) =>
                            $workQuery->where(
                                'status',
                                $this->workStatus
                            )
                    );
                }
            )
            ->when(
                $this->followUp === 'overdue',
                fn ($query) =>
                    $query->whereHas(
                        'leadWorkState',
                        fn ($workQuery) =>
                            $workQuery
                                ->whereNotNull(
                                    'next_action_at'
                                )
                                ->where(
                                    'next_action_at',
                                    '<',
                                    now()
                                )
                    )
            )
            ->when(
                $this->followUp === 'today',
                fn ($query) =>
                    $query->whereHas(
                        'leadWorkState',
                        fn ($workQuery) =>
                            $workQuery
                                ->whereBetween(
                                    'next_action_at',
                                    [
                                        now(),

                                        now()
                                            ->endOfDay(),
                                    ]
                                )
                    )
            )
            /*
             * Fila operacional:
             *
             * 0 - retorno vencido
             * 1 - retorno ainda hoje
             * 2 - lead novo
             * 3 - em contato
             * 4 - aguardando retorno
             * 5 - demais
             * 6 - encerrados
             */
            ->orderByRaw(
                "
                CASE
                    WHEN work.next_action_at IS NOT NULL
                         AND work.next_action_at < ?
                        THEN 0

                    WHEN work.next_action_at IS NOT NULL
                         AND work.next_action_at >= ?
                         AND work.next_action_at <= ?
                        THEN 1

                    WHEN COALESCE(work.status, 'new') = 'new'
                        THEN 2

                    WHEN work.status = 'contacting'
                        THEN 3

                    WHEN work.status = 'waiting'
                        THEN 4

                    WHEN work.status IN ('converted', 'discarded')
                        THEN 6

                    ELSE 5
                END
                ",
                [
                    now(),
                    now(),
                    now()
                        ->endOfDay(),
                ]
            )
            ->orderByRaw(
                "
                CASE
                    WHEN work.next_action_at IS NULL
                        THEN 1
                    ELSE 0
                END
                "
            )
            ->orderBy(
                'work.next_action_at'
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

    public function updateWorkStatus(
        int $companyId,
        string $status
    ): void {
        $allowed = [
            'new',
            'contacting',
            'waiting',
            'discarded',
            'converted',
        ];

        if (
            ! in_array(
                $status,
                $allowed,
                true
            )
        ) {
            return;
        }

        CompanyLeadWorkState::query()
            ->updateOrCreate(
                [
                    'company_id' =>
                        $companyId,
                ],
                [
                    'status' =>
                        $status,

                    'assigned_user_id' =>
                        auth()->id(),

                    'last_action_at' =>
                        now(),
                ]
            );

        unset(
            $this->leads
        );
    }

    public function saveWorkNote(
        int $companyId,
        string $note
    ): void {
        $note =
            trim(
                $note
            );

        CompanyLeadWorkState::query()
            ->updateOrCreate(
                [
                    'company_id' =>
                        $companyId,
                ],
                [
                    'note' =>
                        $note !== ''
                            ? $note
                            : null,

                    'assigned_user_id' =>
                        auth()->id(),

                    'last_action_at' =>
                        now(),
                ]
            );

        unset(
            $this->leads
        );
    }

    public function saveNextAction(
        int $companyId,
        ?string $value
    ): void {
        $value =
            trim(
                (string) $value
            );

        $nextAction =
            null;

        if ($value !== '') {
            try {
                $nextAction =
                    \Carbon\CarbonImmutable::parse(
                        $value
                    );
            } catch (\Throwable) {
                return;
            }
        }

        CompanyLeadWorkState::query()
            ->updateOrCreate(
                [
                    'company_id' =>
                        $companyId,
                ],
                [
                    'next_action_at' =>
                        $nextAction,

                    'assigned_user_id' =>
                        auth()->id(),

                    'last_action_at' =>
                        now(),
                ]
            );

        unset(
            $this->leads
        );
    }

    public function nextActionClass(
        ?CompanyLeadWorkState $state
    ): string {
        if (
            $state?->next_action_at
            === null
        ) {
            return 'text-[#697394]';
        }

        if (
            $state->next_action_at
                ->isPast()
        ) {
            return 'text-red-300';
        }

        if (
            $state->next_action_at
                ->isToday()
        ) {
            return 'text-amber-300';
        }

        return 'text-cyan-300';
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

            'converted' =>
                'Convertido',

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

            'converted' =>
                'text-emerald-300',

            default =>
                'text-[#9ca5c5]',
        };
    }

    #[Computed]
    public function overdueCount(): int
    {
        return Company::query()
            ->whereHas(
                'sdrScore',
                fn ($query) =>
                    $query->where(
                        'is_eligible',
                        true
                    )
            )
            ->whereHas(
                'leadWorkState',
                fn ($query) =>
                    $query
                        ->whereNotNull(
                            'next_action_at'
                        )
                        ->where(
                            'next_action_at',
                            '<',
                            now()
                        )
            )
            ->count();
    }

    #[Computed]
    public function todayCount(): int
    {
        return Company::query()
            ->whereHas(
                'sdrScore',
                fn ($query) =>
                    $query->where(
                        'is_eligible',
                        true
                    )
            )
            ->whereHas(
                'leadWorkState',
                fn ($query) =>
                    $query->whereBetween(
                        'next_action_at',
                        [
                            now(),

                            now()
                                ->endOfDay(),
                        ]
                    )
            )
            ->count();
    }

    #[Computed]
    public function newCount(): int
    {
        return Company::query()
            ->whereHas(
                'sdrScore',
                fn ($query) =>
                    $query->where(
                        'is_eligible',
                        true
                    )
            )
            ->where(
                function ($query): void {
                    $query
                        ->whereDoesntHave(
                            'leadWorkState'
                        )
                        ->orWhereHas(
                            'leadWorkState',
                            fn ($workQuery) =>
                                $workQuery->where(
                                    'status',
                                    'new'
                                )
                        );
                }
            )
            ->count();
    }

    #[Computed]
    public function contactingCount(): int
    {
        return Company::query()
            ->whereHas(
                'sdrScore',
                fn ($query) =>
                    $query->where(
                        'is_eligible',
                        true
                    )
            )
            ->whereHas(
                'leadWorkState',
                fn ($query) =>
                    $query->where(
                        'status',
                        'contacting'
                    )
            )
            ->count();
    }

    #[Computed]
    public function waitingCount(): int
    {
        return Company::query()
            ->whereHas(
                'sdrScore',
                fn ($query) =>
                    $query->where(
                        'is_eligible',
                        true
                    )
            )
            ->whereHas(
                'leadWorkState',
                fn ($query) =>
                    $query->where(
                        'status',
                        'waiting'
                    )
            )
            ->count();
    }

    #[Computed]
    public function convertedCount(): int
    {
        return Company::query()
            ->whereHas(
                'sdrScore',
                fn ($query) =>
                    $query->where(
                        'is_eligible',
                        true
                    )
            )
            ->whereHas(
                'leadWorkState',
                fn ($query) =>
                    $query->where(
                        'status',
                        'converted'
                    )
            )
            ->count();
    }

    public function applyQuickView(
        string $view
    ): void {
        $this->followUp = '';
        $this->workStatus = '';

        match ($view) {
            'overdue' =>
                $this->followUp = 'overdue',

            'today' =>
                $this->followUp = 'today',

            'new' =>
                $this->workStatus = 'new',

            'contacting' =>
                $this->workStatus = 'contacting',

            'waiting' =>
                $this->workStatus = 'waiting',

            'converted' =>
                $this->workStatus = 'converted',

            default =>
                null,
        };

        $this->resetPage();
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

            @if (
                $followUp !== ''
                || $workStatus !== ''
            )
                <button
                    type="button"
                    wire:click="clearFilters"
                    class="
                        text-xs font-semibold
                        text-cyan-300
                        hover:text-cyan-200
                    "
                >
                    Ver fila completa
                </button>
            @endif
        </div>


        <div
            class="
                grid gap-3
                sm:grid-cols-2
                lg:grid-cols-3
                2xl:grid-cols-6
            "
        >

            <button
                type="button"
                wire:click="applyQuickView('overdue')"
                class="
                    ec-intelligence-card
                    text-left transition
                    hover:border-red-300/20
                    hover:bg-red-300/[0.03]
                "
            >
                <div class="ec-intelligence-label">
                    Retornos vencidos
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold text-red-300
                    "
                >
                    {{ $this->overdueCount }}
                </div>

                <div class="ec-intelligence-caption">
                    Atender primeiro
                </div>
            </button>


            <button
                type="button"
                wire:click="applyQuickView('today')"
                class="
                    ec-intelligence-card
                    text-left transition
                    hover:border-amber-300/20
                    hover:bg-amber-300/[0.03]
                "
            >
                <div class="ec-intelligence-label">
                    Ainda hoje
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold text-amber-300
                    "
                >
                    {{ $this->todayCount }}
                </div>

                <div class="ec-intelligence-caption">
                    Retornos programados
                </div>
            </button>


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
                wire:click="applyQuickView('converted')"
                class="
                    ec-intelligence-card
                    text-left transition
                    hover:border-emerald-300/20
                    hover:bg-emerald-300/[0.03]
                "
            >
                <div class="ec-intelligence-label">
                    Convertidos
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold text-emerald-300
                    "
                >
                    {{ $this->convertedCount }}
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
            </select>

            <select
                wire:model.live="followUp"
                class="
                    rounded-xl border
                    border-white/[0.08]
                    bg-[#151a36]
                    px-3 py-2.5
                    text-sm text-[#d9ddef]
                "
            >
                <option value="">
                    Todos retornos
                </option>

                <option value="today">
                    Retorno hoje
                </option>

                <option value="overdue">
                    Retorno atrasado
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

                <option value="converted">
                    Convertido
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
            || $followUp !== ''
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

                $workState =
                    $lead->leadWorkState;

                $currentWorkStatus =
                    $workState?->status
                    ?? 'new';
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

                    <select
                        wire:change="updateWorkStatus(
                            {{ $lead->id }},
                            $event.target.value
                        )"
                        class="
                            mt-1 w-full
                            rounded-lg border
                            border-white/[0.08]
                            bg-[#151a36]
                            px-2.5 py-2
                            text-xs
                            {{
                                $this->workStatusClass(
                                    $currentWorkStatus
                                )
                            }}
                        "
                    >
                        <option
                            value="new"
                            @selected(
                                $currentWorkStatus === 'new'
                            )
                        >
                            Novo
                        </option>

                        <option
                            value="contacting"
                            @selected(
                                $currentWorkStatus === 'contacting'
                            )
                        >
                            Em contato
                        </option>

                        <option
                            value="waiting"
                            @selected(
                                $currentWorkStatus === 'waiting'
                            )
                        >
                            Aguardando retorno
                        </option>

                        <option
                            value="discarded"
                            @selected(
                                $currentWorkStatus === 'discarded'
                            )
                        >
                            Descartado
                        </option>

                        <option
                            value="converted"
                            @selected(
                                $currentWorkStatus === 'converted'
                            )
                        >
                            Convertido
                        </option>
                    </select>

                    <div class="mt-3">

                        <label
                            class="
                                text-[10px]
                                font-semibold
                                uppercase
                                tracking-wide
                                text-[#697394]
                            "
                        >
                            Próxima ação
                        </label>

                        <input
                            type="datetime-local"
                            value="{{
                                $workState
                                    ?->next_action_at
                                    ?->format(
                                        'Y-m-d\\TH:i'
                                    )
                            }}"
                            wire:change="saveNextAction(
                                {{ $lead->id }},
                                $event.target.value
                            )"
                            class="
                                mt-1 w-full
                                rounded-lg border
                                border-white/[0.08]
                                bg-[#151a36]
                                px-2.5 py-2
                                text-xs text-[#d9ddef]
                            "
                        >

                        @if (
                            $workState
                                ?->next_action_at
                        )

                            <div
                                class="
                                    mt-1 text-[10px]
                                    font-semibold
                                    {{
                                        $this->nextActionClass(
                                            $workState
                                        )
                                    }}
                                "
                            >
                                {{
                                    $workState
                                        ->next_action_at
                                        ->format(
                                            'd/m/Y H:i'
                                        )
                                }}
                            </div>

                        @endif

                    </div>


                    <div class="mt-3">

                        <label
                            class="
                                text-[10px]
                                font-semibold
                                uppercase
                                tracking-wide
                                text-[#697394]
                            "
                        >
                            Observação
                        </label>

                        <textarea
                            rows="2"
                            wire:change="saveWorkNote(
                                {{ $lead->id }},
                                $event.target.value
                            )"
                            class="
                                mt-1 w-full resize-none
                                rounded-lg border
                                border-white/[0.08]
                                bg-[#151a36]
                                px-2.5 py-2
                                text-xs leading-5
                                text-[#d9ddef]
                                placeholder:text-[#596482]
                            "
                            placeholder="Ex.: retornar após validação do fiscal..."
                        >{{ $workState?->note }}</textarea>

                    </div>


                    @if ($workState?->last_action_at)

                        <div
                            class="
                                mt-2 text-[10px]
                                text-[#697394]
                            "
                        >
                            {{
                                $workState
                                    ->last_action_at
                                    ->format(
                                        'd/m/Y H:i'
                                    )
                            }}

                            @if (
                                $workState
                                    ?->assignedUser
                                    ?->name
                            )
                                ·
                                {{
                                    $workState
                                        ->assignedUser
                                        ->name
                                }}
                            @endif
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
