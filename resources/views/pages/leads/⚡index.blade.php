<?php

use App\Contracts\CrmCompanyProvider;
use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyExportIntelligence;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadWorkState;
use App\Models\CompanySdrScore;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotRefreshRun;
use App\Models\User;
use App\Services\CrmCheckService;
use App\Services\HubSpotCompanyLinkService;
use App\Services\HubSpotLeadReprospectingActionService;
use App\Services\HubSpotLeadReprospectingService;
use App\Services\HubSpotLeadStatusSyncService;
use App\Services\HubSpotRefreshDiffService;
use App\Services\HubSpotRefreshRunService;
use App\Services\LeadOwnershipService;
use App\Services\Providers\ReceitaLocalCnpjGroupProvider;
use App\Support\Cnpj;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
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

    #[Url]
    public string $owner = '';

    #[Url]
    public string $workStatus = '';

    #[Url]
    public string $followUp = '';

    public string $dailyView = '';

    public bool $staleOnly = false;

    public bool $reprospectingReadyOnly = false;

    public bool $hubSpotOnly = false;

    public ?int $linkingHubSpotCompanyId = null;

    public string $companyLinkSearch = '';

    /**
     * @var array<string, mixed>
     */
    public array $companyLinkReceitaPreview = [];

    public string $companyLinkReceitaError = '';

    public string $commercialActionMessage = '';

    public string $commercialActionError = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $leadRefreshFeedback = [];

    public function isCommercialManager(): bool
    {
        $user =
            auth()->user();

        return $user instanceof User
            && $user
                ->isCommercialManager();
    }

    public function refreshAllHubSpotData(
        HubSpotRefreshRunService $runs,
    ): void {
        abort_unless(
            $this->isCommercialManager(),
            403
        );

        $this->commercialActionMessage = '';
        $this->commercialActionError = '';

        $active =
            $runs->active();

        if ($active !== null) {
            $this->commercialActionMessage =
                'Já existe uma atualização CRM/HubSpot em andamento.';

            return;
        }

        $companyIds =
            $this
                ->operationalLeadQuery()
                ->pluck(
                    'companies.id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->filter(
                    static fn (
                        int $id
                    ): bool => $id > 0
                )
                ->unique()
                ->values()
                ->all();

        if ($companyIds === []) {
            $this->commercialActionMessage =
                'Nenhuma empresa disponível para atualização.';

            return;
        }

        $run =
            $runs->start(
                companyIds: $companyIds,

                userId: $this->authenticatedUserId(),
            );

        $label =
            $run->total === 1
                ? 'empresa'
                : 'empresas';

        $this->commercialActionMessage =
            'Atualização CRM/HubSpot iniciada para '
            .$run->total
            .' '
            .$label
            .'. Os dados serão atualizados em segundo plano.';
    }

    #[Computed]
    public function hubSpotRefreshProgress(): ?array
    {
        $run =
            HubSpotRefreshRun::query()
                ->latest(
                    'id'
                )
                ->first();

        if ($run === null) {
            return null;
        }

        $finished =
            min(
                $run->total,
                $run->processed
                + $run->failed
            );

        $remaining =
            max(
                0,
                $run->total
                - $finished
            );

        $percent =
            $run->total > 0
                ? (int) floor(
                    (
                        $finished
                        / $run->total
                    )
                    * 100
                )
                : 100;

        $running =
            in_array(
                $run->status,
                [
                    'queued',
                    'running',
                ],
                true
            );

        return [
            'id' => $run->id,

            'status' => $run->status,

            'running' => $running,

            'total' => $run->total,

            'finished' => $finished,

            'remaining' => $remaining,

            'changed' => $run->changed,

            'unchanged' => $run->unchanged,

            'failed' => $run->failed,

            'percent' => $percent,

            'summary' => is_array(
                $run->summary
            )
                    ? $run->summary
                    : [],

            'started_at' => $run->started_at
                ?->format(
                    'd/m/Y H:i:s'
                ),

            'completed_at' => $run->completed_at
                ?->format(
                    'd/m/Y H:i:s'
                ),
        ];
    }

    private function assertCanOperateLead(
        CompanyHubSpotLead $lead
    ): void {
        if (
            $this
                ->isCommercialManager()
        ) {
            return;
        }

        $userId =
            $this
                ->authenticatedUserId();

        abort_unless(
            $userId !== null,
            403
        );

        $allowed =
            CompanyLeadWorkState::query()
                ->where(
                    'company_id',
                    $lead->company_id
                )
                ->where(
                    'assigned_user_id',
                    $userId
                )
                ->exists();

        abort_unless(
            $allowed,
            403
        );
    }

    public function updatedSearch(): void
    {
        $this->resetPage();

        $this->resetPage(
            'hubspotPage'
        );
    }

    public function updatedCompanyLinkSearch(): void
    {
        $this->companyLinkReceitaPreview = [];
        $this->companyLinkReceitaError = '';
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

        $this->resetPage(
            'hubspotPage'
        );
    }

    public function updatedState(): void
    {
        $this->resetPage();

        $this->resetPage(
            'hubspotPage'
        );
    }

    public function updatedOwner(): void
    {
        $this->dailyView = '';

        $this->resetPage();
    }

    public function updatedWorkStatus(): void
    {
        $this->dailyView = '';
        $this->staleOnly = false;
        $this->reprospectingReadyOnly = false;

        if (
            $this->workStatus
            !== 'waiting'
        ) {
            $this->followUp = '';
        }

        $this->resetPage();
    }

    public function updatedFollowUp(): void
    {
        $this->dailyView = '';
        $this->staleOnly = false;
        $this->reprospectingReadyOnly = false;

        if (
            $this->followUp
            !== ''
        ) {
            $this->workStatus =
                'waiting';
        }

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
            'owner',
            'workStatus',
            'followUp',
            'dailyView',
            'staleOnly',
            'reprospectingReadyOnly',
            'hubSpotOnly',
            'linkingHubSpotCompanyId',
            'companyLinkSearch',
        ]);

        $this->resetPage();

        $this->resetPage(
            'hubspotPage'
        );
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
            ->when(
                ! $this->isCommercialManager(),
                function ($query): void {
                    $userId =
                        $this
                            ->authenticatedUserId();

                    if ($userId === null) {
                        $query->whereRaw(
                            '1 = 0'
                        );

                        return;
                    }

                    $query->whereHas(
                        'leadWorkState',
                        fn ($ownerQuery) => $ownerQuery->where(
                            'assigned_user_id',
                            $userId
                        )
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
                                )
                                ->orWhereHas(
                                    'hubSpotCompanies',
                                    function ($hubSpotQuery) use (
                                        $normalized
                                    ): void {
                                        $hubSpotQuery->where(
                                            function ($aliasQuery) use (
                                                $normalized
                                            ): void {
                                                $aliasQuery
                                                    ->whereRaw(
                                                        "LOWER(COALESCE(name, '')) LIKE ?",
                                                        [
                                                            '%'
                                                            .$normalized
                                                            .'%',
                                                        ]
                                                    )
                                                    ->orWhereRaw(
                                                        "LOWER(COALESCE(domain, '')) LIKE ?",
                                                        [
                                                            '%'
                                                            .$normalized
                                                            .'%',
                                                        ]
                                                    );
                                            }
                                        );
                                    }
                                );
                        }
                    );
                }
            )
            ->when(
                $this->priority !== '',
                fn ($query) => $query->where(
                    'sdr.priority',
                    $this->priority
                )
            )
            ->when(
                $this->icp !== '',
                fn ($query) => $query->whereHas(
                    'icpScore',
                    fn ($icpQuery) => $icpQuery->where(
                        'grade',
                        $this->icp
                    )
                )
            )
            ->when(
                $this->crm !== '',
                function ($query): void {
                    $crmFilter =
                        trim(
                            $this->crm
                        );

                    /*
                     * stage:<nome>
                     *
                     * Filtra empresas que possuem
                     * pelo menos um negócio naquela
                     * etapa real do HubSpot.
                     */
                    if (
                        str_starts_with(
                            $crmFilter,
                            'stage:'
                        )
                    ) {
                        $stage =
                            trim(
                                substr(
                                    $crmFilter,
                                    6
                                )
                            );

                        if ($stage === '') {
                            return;
                        }

                        $query->whereHas(
                            'crmCheck',
                            fn ($crmQuery) => $crmQuery
                                ->whereJsonContains(
                                    'metadata->deal_summary->stages',
                                    $stage
                                )
                        );

                        return;
                    }

                    /*
                     * Mantém os filtros antigos:
                     *
                     * not_found
                     * known
                     * prospected
                     * opportunity
                     * client
                     */
                    $query->whereHas(
                        'crmCheck',
                        fn ($crmQuery) => $crmQuery->where(
                            'status',
                            $crmFilter
                        )
                    );
                }
            )
            ->when(
                $this->state !== '',
                fn ($query) => $query->whereHas(
                    'establishments',
                    fn ($establishmentQuery) => $establishmentQuery
                        ->where(
                            'state',
                            $this->state
                        )
                )
            )
            ->when(
                $this->owner !== '',
                function ($query): void {
                    if (
                        $this->owner
                        === 'mine'
                    ) {
                        $userId =
                            $this
                                ->authenticatedUserId();

                        if (
                            $userId === null
                        ) {
                            $query->whereRaw(
                                '1 = 0'
                            );

                            return;
                        }

                        $query->whereHas(
                            'leadWorkState',
                            fn ($ownerQuery) => $ownerQuery->where(
                                'assigned_user_id',
                                $userId
                            )
                        );

                        return;
                    }

                    if (
                        $this->owner
                        === 'unassigned'
                    ) {
                        $query->where(
                            function ($ownerQuery): void {
                                $ownerQuery
                                    ->whereDoesntHave(
                                        'leadWorkState'
                                    )
                                    ->orWhereHas(
                                        'leadWorkState',
                                        fn ($stateQuery) => $stateQuery
                                            ->whereNull(
                                                'assigned_user_id'
                                            )
                                    );
                            }
                        );

                        return;
                    }

                    if (
                        ctype_digit(
                            $this->owner
                        )
                    ) {
                        $query->whereHas(
                            'leadWorkState',
                            fn ($ownerQuery) => $ownerQuery->where(
                                'assigned_user_id',
                                (int) $this->owner
                            )
                        );
                    }
                }
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
                                    ->where(
                                        'work.work_status',
                                        'new'
                                    )
                                    ->orWhereNull(
                                        'work.id'
                                    );
                            }
                        );

                        return;
                    }

                    $query->where(
                        'work.work_status',
                        $this->workStatus
                    );
                }
            )
            ->when(
                $this->dailyView === 'today',
                function ($query): void {
                    $userId =
                        $this
                            ->authenticatedUserId();

                    if ($userId === null) {
                        $query->whereRaw(
                            '1 = 0'
                        );

                        return;
                    }

                    $query->whereHas(
                        'leadWorkState',
                        fn ($ownerQuery) => $ownerQuery->where(
                            'assigned_user_id',
                            $userId
                        )
                    );

                    $query->where(
                        function ($dailyQuery): void {
                            $dailyQuery
                                ->where(
                                    function ($waitingQuery): void {
                                        $waitingQuery
                                            ->where(
                                                'work.work_status',
                                                'waiting'
                                            )
                                            ->whereNotNull(
                                                'work.last_task_due_at'
                                            )
                                            ->where(
                                                'work.last_task_due_at',
                                                '<',
                                                now()
                                                    ->addDay()
                                                    ->startOfDay()
                                            );
                                    }
                                )
                                ->orWhere(
                                    'work.work_status',
                                    'contacting'
                                )
                                ->orWhere(
                                    function ($futureQuery): void {
                                        $futureQuery
                                            ->where(
                                                'work.work_status',
                                                'future'
                                            )
                                            ->whereNotNull(
                                                'work.last_task_due_at'
                                            )
                                            ->where(
                                                'work.last_task_due_at',
                                                '<',
                                                now()
                                                    ->addDay()
                                                    ->startOfDay()
                                            );
                                    }
                                );
                        }
                    );
                }
            )

            ->when(
                $this->followUp !== '',
                function ($query): void {
                    $query->where(
                        'work.work_status',
                        'waiting'
                    );

                    if (
                        $this->followUp
                        === 'overdue'
                    ) {
                        $query
                            ->whereNotNull(
                                'work.last_task_due_at'
                            )
                            ->where(
                                'work.last_task_due_at',
                                '<',
                                now()
                            );

                        return;
                    }

                    if (
                        $this->followUp
                        === 'today'
                    ) {
                        $query
                            ->whereNotNull(
                                'work.last_task_due_at'
                            )
                            ->where(
                                'work.last_task_due_at',
                                '>=',
                                now()
                            )
                            ->where(
                                'work.last_task_due_at',
                                '<',
                                now()
                                    ->addDay()
                                    ->startOfDay()
                            );

                        return;
                    }

                    if (
                        $this->followUp
                        === 'upcoming'
                    ) {
                        $query
                            ->whereNotNull(
                                'work.last_task_due_at'
                            )
                            ->where(
                                'work.last_task_due_at',
                                '>=',
                                now()
                                    ->addDay()
                                    ->startOfDay()
                            );

                        return;
                    }

                    if (
                        $this->followUp
                        === 'unscheduled'
                    ) {
                        $query->whereNull(
                            'work.last_task_due_at'
                        );
                    }
                }
            )

            ->when(
                $this->staleOnly,
                function ($query): void {
                    $query
                        ->where(
                            'work.work_status',
                            'contacting'
                        )
                        ->whereNotNull(
                            'work.last_activity_at'
                        )
                        ->where(
                            'work.last_activity_at',
                            '<=',
                            now()->subDays(
                                $this->staleAfterDays()
                            )
                        );
                }
            )

            ->when(
                $this->reprospectingReadyOnly,
                function ($query): void {
                    $cooldownDays =
                        max(
                            1,
                            (int) config(
                                'prospector.crm.reprospecting_after_days',
                                180
                            )
                        );

                    $query->where(
                        function ($readyQuery) use (
                            $cooldownDays
                        ): void {
                            $readyQuery
                                ->where(
                                    function ($futureQuery): void {
                                        $futureQuery
                                            ->where(
                                                'work.work_status',
                                                'future'
                                            )
                                            ->whereNotNull(
                                                'work.last_task_due_at'
                                            )
                                            ->where(
                                                'work.last_task_due_at',
                                                '<=',
                                                now()
                                            );
                                    }
                                )
                                ->orWhere(
                                    function ($refusedQuery) use (
                                        $cooldownDays
                                    ): void {
                                        $refusedQuery
                                            ->where(
                                                'work.work_status',
                                                'refused'
                                            )
                                            ->whereNotNull(
                                                'work.work_status_changed_at'
                                            )
                                            ->where(
                                                'work.work_status_changed_at',
                                                '<=',
                                                now()->subDays(
                                                    $cooldownDays
                                                )
                                            );
                                    }
                                );
                        }
                    );
                }
            )

            /*
             * Fila operacional vinda do HubSpot:
             *
             * 0 - aguardando retorno
             * 1 - em contato
             * 2 - novo
             * 3 - descartado
             */
            /*
             * Ordem operacional:
             *
             * 0 - follow-up atrasado
             * 1 - follow-up para hoje
             * 2 - follow-up futuro
             * 3 - aguardando sem prazo
             * 4 - em contato
             * 5 - novos
             * 9 - descartados
             */
            ->orderByRaw(
                "
                CASE
                    WHEN work.work_status = 'waiting'
                        AND work.last_task_due_at IS NOT NULL
                        AND work.last_task_due_at < ?
                        THEN 0

                    WHEN work.work_status = 'waiting'
                        AND work.last_task_due_at IS NOT NULL
                        AND work.last_task_due_at >= ?
                        AND work.last_task_due_at < ?
                        THEN 1

                    WHEN work.work_status = 'waiting'
                        AND work.last_task_due_at IS NOT NULL
                        AND work.last_task_due_at >= ?
                        THEN 2

                    WHEN work.work_status = 'waiting'
                        THEN 3

                    WHEN work.work_status = 'contacting'
                        THEN 4

                    WHEN work.work_status = 'new'
                        OR work.id IS NULL
                        THEN 5

                    WHEN work.work_status = 'future'
                        THEN 6

                    WHEN work.work_status = 'refused'
                        THEN 7

                    WHEN work.work_status = 'converted'
                        THEN 8

                    WHEN work.work_status = 'discarded'
                        THEN 9

                    ELSE 8
                END
                ",
                [
                    now(),
                    now(),
                    today()->addDay(),
                    today()->addDay(),
                ]
            )
            ->orderBy(
                'work.last_task_due_at'
            )
            ->orderByDesc(
                'sdr.score'
            )
            ->orderBy(
                'companies.corporate_name'
            )
            ->paginate(20);
    }

    public function openCompanyLink(
        int $hubSpotCompanyId
    ): void {
        abort_unless(
            $this->isCommercialManager(),
            403
        );

        HubSpotCompany::query()
            ->whereNull(
                'company_id'
            )
            ->findOrFail(
                $hubSpotCompanyId
            );

        $this->linkingHubSpotCompanyId =
            $hubSpotCompanyId;

        $this->companyLinkSearch = '';

        $this->companyLinkReceitaPreview = [];
        $this->companyLinkReceitaError = '';

        $this->commercialActionError = '';
        $this->commercialActionMessage = '';
    }

    public function closeCompanyLink(): void
    {
        $this->linkingHubSpotCompanyId =
            null;

        $this->companyLinkSearch = '';
    }

    #[Computed]
    public function linkingHubSpotCompany(): ?HubSpotCompany
    {
        if (
            ! $this->isCommercialManager()
            || $this->linkingHubSpotCompanyId
                === null
        ) {
            return null;
        }

        return HubSpotCompany::query()
            ->whereNull(
                'company_id'
            )
            ->find(
                $this->linkingHubSpotCompanyId
            );
    }

    /**
     * @return Collection<int, Company>
     */
    #[Computed]
    public function companyLinkCandidates(): Collection
    {
        if (
            ! $this->isCommercialManager()
            || $this->linkingHubSpotCompanyId
                === null
        ) {
            return new Collection;
        }

        $search =
            trim(
                $this->companyLinkSearch
            );

        if (
            mb_strlen(
                $search
            ) < 2
        ) {
            return new Collection;
        }

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
            )
            ?? '';

        return Company::query()
            ->with(
                'matrix'
            )
            ->where(
                function ($query) use (
                    $normalized,
                    $cnpj
                ): void {
                    $query
                        ->whereRaw(
                            "LOWER(COALESCE(corporate_name, '')) LIKE ?",
                            [
                                '%'
                                .$normalized
                                .'%',
                            ]
                        );

                    if ($cnpj !== '') {
                        $query->orWhere(
                            'cnpj_root',
                            'like',
                            '%'.$cnpj.'%'
                        );
                    }

                    $query->orWhereHas(
                        'establishments',
                        function ($establishmentQuery) use (
                            $normalized,
                            $cnpj
                        ): void {
                            $establishmentQuery
                                ->where(
                                    function ($searchQuery) use (
                                        $normalized,
                                        $cnpj
                                    ): void {
                                        $searchQuery
                                            ->whereRaw(
                                                "LOWER(COALESCE(fantasy_name, '')) LIKE ?",
                                                [
                                                    '%'
                                                    .$normalized
                                                    .'%',
                                                ]
                                            );

                                        if ($cnpj !== '') {
                                            $searchQuery
                                                ->orWhere(
                                                    'cnpj',
                                                    'like',
                                                    '%'
                                                    .$cnpj
                                                    .'%'
                                                );
                                        }
                                    }
                                );
                        }
                    );
                }
            )
            ->orderBy(
                'corporate_name'
            )
            ->limit(
                12
            )
            ->get();
    }

    public function lookupCompanyLinkReceita(
        ReceitaLocalCnpjGroupProvider $provider,
    ): void {
        abort_unless(
            $this->isCommercialManager(),
            403
        );

        $this->companyLinkReceitaPreview = [];
        $this->companyLinkReceitaError = '';

        $cnpj =
            Cnpj::normalize(
                $this->companyLinkSearch
            );

        if (! Cnpj::isValid($cnpj)) {
            $this->companyLinkReceitaError =
                'Informe um CNPJ válido com 14 caracteres.';

            return;
        }

        $root =
            Cnpj::root(
                $cnpj
            );

        $existing =
            Company::query()
                ->where(
                    'cnpj_root',
                    $root
                )
                ->first();

        if ($existing !== null) {
            $this->companyLinkReceitaError =
                'Esta raiz de CNPJ já existe no Prospector. Use a empresa encontrada acima para fazer o vínculo.';

            return;
        }

        try {
            $data =
                $provider->lookupRoot(
                    $root
                );

            $companyData =
                $data[
                    'company'
                ]
                ?? [];

            $establishments =
                $data[
                    'establishments'
                ]
                ?? [];

            if (
                ! is_array($companyData)
                || ! is_array($establishments)
            ) {
                $this->companyLinkReceitaError =
                    'A Receita retornou dados inválidos.';

                return;
            }

            $selected = null;

            foreach ($establishments as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $establishment =
                    $item[
                        'establishment'
                    ]
                    ?? null;

                if (! is_array($establishment)) {
                    continue;
                }

                $candidateCnpj =
                    Cnpj::normalize(
                        (string) (
                            $establishment[
                                'cnpj'
                            ]
                            ?? ''
                        )
                    );

                if ($candidateCnpj === $cnpj) {
                    $selected =
                        $establishment;

                    break;
                }
            }

            if ($selected === null) {
                $this->companyLinkReceitaError =
                    'O CNPJ informado não foi encontrado dentro da raiz retornada pela Receita.';

                return;
            }

            $this->companyLinkReceitaPreview = [
                'cnpj' => $cnpj,

                'cnpj_formatted' => Cnpj::format(
                    $cnpj
                ),

                'cnpj_root' => $root,

                'corporate_name' => trim(
                    (string) (
                        $companyData[
                            'corporate_name'
                        ]
                        ?? ''
                    )
                ),

                'fantasy_name' => trim(
                    (string) (
                        $selected[
                            'fantasy_name'
                        ]
                        ?? ''
                    )
                ),

                'municipality_name' => trim(
                    (string) (
                        $selected[
                            'municipality_name'
                        ]
                        ?? ''
                    )
                ),

                'state' => trim(
                    (string) (
                        $selected[
                            'state'
                        ]
                        ?? ''
                    )
                ),

                'registration_status' => trim(
                    (string) (
                        $selected[
                            'registration_status'
                        ]
                        ?? ''
                    )
                ),

                'type' => trim(
                    (string) (
                        $selected[
                            'type'
                        ]
                        ?? ''
                    )
                ),

                'establishment_count' => count(
                    $establishments
                ),
            ];
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->companyLinkReceitaError =
                'Não foi possível consultar a Receita: '
                .$exception->getMessage();
        }
    }

    public function importAndLinkHubSpotCompany(
        HubSpotCompanyLinkService $service,
    ): void {
        abort_unless(
            $this->isCommercialManager(),
            403
        );

        $this->commercialActionMessage = '';
        $this->commercialActionError = '';

        if (
            $this->linkingHubSpotCompanyId
            === null
        ) {
            $this->commercialActionError =
                'Selecione primeiro uma empresa do HubSpot.';

            return;
        }

        $cnpj =
            Cnpj::normalize(
                (string) (
                    $this->companyLinkReceitaPreview[
                        'cnpj'
                    ]
                    ?? ''
                )
            );

        if (! Cnpj::isValid($cnpj)) {
            $this->commercialActionError =
                'Consulte e valide o CNPJ na Receita antes de importar.';

            return;
        }

        $hubSpotCompany =
            HubSpotCompany::query()
                ->whereNull(
                    'company_id'
                )
                ->find(
                    $this->linkingHubSpotCompanyId
                );

        if ($hubSpotCompany === null) {
            $this->commercialActionError =
                'Este registro já foi vinculado ou não existe mais.';

            $this->closeCompanyLink();

            return;
        }

        $hubSpotName =
            trim(
                (string)
                $hubSpotCompany->name
            );

        try {
            $lead =
                $service->importAndLink(
                    hubSpotCompany: $hubSpotCompany,

                    cnpj: $cnpj,
                );

            $company =
                Company::query()
                    ->findOrFail(
                        $lead->company_id
                    );

            $this->commercialActionMessage =
                'Empresa importada e vinculada: '
                .(
                    $hubSpotName !== ''
                        ? $hubSpotName
                        : 'Empresa HubSpot'
                )
                .' → '
                .$company->corporate_name
                .'.';

            $this->closeCompanyLink();

            $this->resetPage();

            $this->resetPage(
                'hubspotPage'
            );
        } catch (DomainException $exception) {
            $this->commercialActionError =
                $exception->getMessage();
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->commercialActionError =
                'Não foi possível importar e vincular a empresa: '
                .$exception->getMessage();
        }
    }

    public function linkHubSpotCompany(
        int $companyId,
        HubSpotCompanyLinkService $service,
    ): void {
        abort_unless(
            $this->isCommercialManager(),
            403
        );

        $this->commercialActionMessage = '';
        $this->commercialActionError = '';

        if (
            $this->linkingHubSpotCompanyId
            === null
        ) {
            $this->commercialActionError =
                'Selecione primeiro uma empresa do HubSpot.';

            return;
        }

        $hubSpotCompany =
            HubSpotCompany::query()
                ->whereNull(
                    'company_id'
                )
                ->find(
                    $this->linkingHubSpotCompanyId
                );

        if ($hubSpotCompany === null) {
            $this->commercialActionError =
                'Este registro já foi vinculado ou não existe mais.';

            $this->closeCompanyLink();

            return;
        }

        $company =
            Company::query()
                ->findOrFail(
                    $companyId
                );

        $hubSpotName =
            trim(
                (string)
                $hubSpotCompany->name
            );

        try {
            $service->link(
                hubSpotCompany: $hubSpotCompany,

                company: $company,
            );

            $this->commercialActionMessage =
                'Vínculo confirmado: '
                .(
                    $hubSpotName !== ''
                        ? $hubSpotName
                        : 'Empresa HubSpot'
                )
                .' → '
                .$company->corporate_name
                .'.';

            $this->closeCompanyLink();

            $this->resetPage();

            $this->resetPage(
                'hubspotPage'
            );
        } catch (DomainException $exception) {
            $this->commercialActionError =
                $exception->getMessage();
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->commercialActionError =
                'Não foi possível concluir o vínculo da empresa.';
        }
    }

    #[Computed]
    public function hubSpotOnlyOpenCount(): int
    {
        if (
            ! $this
                ->isCommercialManager()
        ) {
            return 0;
        }

        return HubSpotCompany::query()
            ->whereNull(
                'company_id'
            )
            ->whereHas(
                'deals',
                fn ($query) => $query->where(
                    'is_closed',
                    false
                )
            )
            ->count();
    }

    #[Computed]
    public function hubSpotOnlyLeads()
    {
        $search =
            trim(
                $this->search
            );

        $query =
            HubSpotCompany::query()
                ->whereNull(
                    'company_id'
                );

        /*
         * Registros sem Company ainda não possuem
         * carteira/responsável local.
         *
         * Até criarmos esse vínculo explicitamente,
         * somente gestores podem visualizá-los.
         */
        if (
            ! $this
                ->isCommercialManager()
        ) {
            $query->whereRaw(
                '1 = 0'
            );
        }

        /*
         * No funcionamento normal não misturamos os
         * registros CRM-only com os leads fiscais.
         *
         * Eles aparecem:
         *
         * - quando o gestor ativa "CRM sem CNPJ";
         * - quando uma busca encontra um registro
         *   existente apenas no HubSpot.
         */
        if (
            ! $this->hubSpotOnly
            && $search === ''
        ) {
            $query->whereRaw(
                '1 = 0'
            );
        }

        /*
         * A visão CRM sem CNPJ representa a fila
         * comercial ainda ativa.
         */
        if ($this->hubSpotOnly) {
            $query->whereHas(
                'deals',
                fn ($dealQuery) => $dealQuery
                    ->where(
                        'is_closed',
                        false
                    )
            );
        } else {
            /*
             * Na busca textual também permitimos
             * encontrar histórico encerrado.
             */
            $query->whereHas(
                'deals'
            );
        }

        if ($search !== '') {
            $normalized =
                mb_strtolower(
                    $search
                );

            $query->where(
                function ($searchQuery) use (
                    $normalized
                ): void {
                    $searchQuery
                        ->whereRaw(
                            "LOWER(COALESCE(name, '')) LIKE ?",
                            [
                                '%'
                                .$normalized
                                .'%',
                            ]
                        )
                        ->orWhereRaw(
                            "LOWER(COALESCE(domain, '')) LIKE ?",
                            [
                                '%'
                                .$normalized
                                .'%',
                            ]
                        );
                }
            );
        }

        /*
         * Estado pode ser utilizado diretamente
         * no espelho HubSpot.
         */
        if ($this->state !== '') {
            $query->where(
                'state',
                $this->state
            );
        }

        /*
         * Se o filtro selecionado for uma etapa
         * real do HubSpot, também o aplicamos aos
         * registros sem CNPJ.
         */
        $crmFilter =
            trim(
                $this->crm
            );

        if (
            str_starts_with(
                $crmFilter,
                'stage:'
            )
        ) {
            $stage =
                trim(
                    substr(
                        $crmFilter,
                        6
                    )
                );

            if ($stage !== '') {
                $query->whereHas(
                    'deals',
                    fn ($dealQuery) => $dealQuery
                        ->where(
                            'stage_label',
                            $stage
                        )
                );
            }
        }

        return $query
            ->with([
                'deals' => fn ($dealQuery) => $dealQuery
                    ->orderBy(
                        'is_closed'
                    )
                    ->orderByDesc(
                        'last_activity_at'
                    )
                    ->orderBy(
                        'name'
                    ),

                'contacts',

                'activities' => fn ($activityQuery) => $activityQuery
                    ->where(
                        'hubspot_activities.is_deleted',
                        false
                    )
                    ->orderByDesc(
                        'occurred_at'
                    ),
            ])
            ->orderByRaw(
                "
                CASE
                    WHEN name IS NULL
                        OR TRIM(name) = ''
                        THEN 1
                    ELSE 0
                END
                "
            )
            ->orderBy(
                'name'
            )
            ->paginate(
                20,
                ['*'],
                'hubspotPage'
            );
    }

    public function applyHubSpotOnlyView(): void
    {
        abort_unless(
            $this->isCommercialManager(),
            403
        );

        $this->hubSpotOnly =
            ! $this->hubSpotOnly;

        if ($this->hubSpotOnly) {
            /*
             * Estes filtros dependem de Company,
             * ICP, score ou carteira local.
             *
             * Não devem excluir silenciosamente
             * registros CRM-only.
             */
            $this->priority = '';
            $this->icp = '';
            $this->owner = '';
            $this->workStatus = '';
            $this->followUp = '';
            $this->dailyView = '';
            $this->staleOnly = false;
            $this->reprospectingReadyOnly = false;
        }

        $this->resetPage();

        $this->resetPage(
            'hubspotPage'
        );
    }

    /**
     * Etapas reais encontradas nos negócios
     * armazenados a partir do HubSpot.
     *
     * @return list<array{
     *     label: string,
     *     count: int
     * }>
     */
    #[Computed]
    public function crmStageOptions(): array
    {
        $counts = [];

        $checks =
            CompanyCrmCheck::query()
                ->whereNotNull(
                    'metadata'
                )
                ->get([
                    'metadata',
                ]);

        foreach ($checks as $check) {
            $metadata =
                $check->metadata
                ?? [];

            /*
             * Preferimos os negócios individuais
             * porque assim contamos quantos negócios
             * existem em cada etapa.
             */
            $deals =
                data_get(
                    $metadata,
                    'deals',
                    []
                );

            $foundFromDeals =
                false;

            if (is_array($deals)) {
                foreach ($deals as $deal) {
                    if (! is_array($deal)) {
                        continue;
                    }

                    $rawStage =
                        $deal[
                            'stage_label'
                        ]
                        ?? null;

                    if (! is_scalar($rawStage)) {
                        continue;
                    }

                    $stage =
                        trim(
                            (string) $rawStage
                        );

                    if ($stage === '') {
                        continue;
                    }

                    $counts[$stage] =
                        (
                            $counts[$stage]
                            ?? 0
                        )
                        + 1;

                    $foundFromDeals =
                        true;
                }
            }

            /*
             * Compatibilidade com registros antigos:
             * se não houver deals completos,
             * usa o resumo salvo pelo CrmCheckService.
             */
            if ($foundFromDeals) {
                continue;
            }

            $stages =
                data_get(
                    $metadata,
                    'deal_summary.stages',
                    []
                );

            if (! is_array($stages)) {
                continue;
            }

            foreach ($stages as $rawStage) {
                if (! is_scalar($rawStage)) {
                    continue;
                }

                $stage =
                    trim(
                        (string) $rawStage
                    );

                if ($stage === '') {
                    continue;
                }

                $counts[$stage] =
                    (
                        $counts[$stage]
                        ?? 0
                    )
                    + 1;
            }
        }

        $options = [];

        foreach (
            $counts as $label => $count
        ) {
            $options[] = [
                'label' => $label,

                'count' => $count,
            ];
        }

        usort(
            $options,
            static fn (
                array $a,
                array $b
            ): int => strnatcasecmp(
                $a['label'],
                $b['label']
            )
        );

        return array_values(
            $options
        );
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
        return $this
            ->operationalLeadQuery()
            ->where(
                'sdr.is_eligible',
                true
            )
            ->count();
    }

    #[Computed]
    public function veryHighCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'sdr.is_eligible',
                true
            )
            ->where(
                'sdr.priority',
                'very_high'
            )
            ->count();
    }

    #[Computed]
    public function highCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'sdr.is_eligible',
                true
            )
            ->where(
                'sdr.priority',
                'high'
            )
            ->count();
    }

    public function workStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'contacting' => 'Em contato',

            'waiting' => 'Aguardando retorno',

            'future' => 'Oportunidade futura',

            'reprospecting' => 'Reprospecção',

            'refused' => 'Recusou',

            'converted' => 'Convertido',

            'discarded' => 'Descartado',

            default => 'Novo',
        };
    }

    public function workStatusClass(
        ?string $status
    ): string {
        return match ($status) {
            'contacting' => 'text-cyan-300',

            'waiting' => 'text-amber-300',

            'future' => 'text-violet-300',

            'reprospecting' => 'text-rose-300',

            'refused' => 'text-rose-300',

            'converted' => 'text-emerald-300',

            'discarded' => 'text-red-300',

            default => 'text-emerald-300',
        };
    }

    private function authenticatedUserId(): ?int
    {
        $userId =
            auth()->id();

        return is_numeric(
            $userId
        )
            ? (int) $userId
            : null;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function salesUsers()
    {
        return User::query()
            ->whereNotNull(
                'email_verified_at'
            )
            ->orderBy(
                'name'
            )
            ->get([
                'id',
                'name',
            ]);
    }

    #[Computed]
    public function myLeadsCount(): int
    {
        $userId =
            $this
                ->authenticatedUserId();

        if ($userId === null) {
            return 0;
        }

        return $this
            ->operationalLeadQuery()
            ->whereHas(
                'leadWorkState',
                fn ($query) => $query->where(
                    'assigned_user_id',
                    $userId
                )
            )
            ->count();
    }

    #[Computed]
    public function unassignedCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                function ($query): void {
                    $query
                        ->whereDoesntHave(
                            'leadWorkState'
                        )
                        ->orWhereHas(
                            'leadWorkState',
                            fn ($stateQuery) => $stateQuery
                                ->whereNull(
                                    'assigned_user_id'
                                )
                        );
                }
            )
            ->count();
    }

    public function applyOwnerView(
        string $view
    ): void {
        abort_unless(
            $this
                ->isCommercialManager(),
            403
        );

        $this->dailyView = '';

        if (
            ! in_array(
                $view,
                [
                    'mine',
                    'unassigned',
                ],
                true
            )
        ) {
            $view = '';
        }

        $this->owner =
            $this->owner === $view
                ? ''
                : $view;

        $this->resetPage();
    }

    public function assignOwner(
        int $companyId,
        string $userId,
        LeadOwnershipService $service,
    ): void {
        abort_unless(
            $this
                ->isCommercialManager(),
            403
        );

        $this->commercialActionMessage = '';
        $this->commercialActionError = '';

        $company =
            Company::query()
                ->findOrFail(
                    $companyId
                );

        $userId =
            trim(
                $userId
            );

        $owner = null;

        if ($userId !== '') {
            if (
                ! ctype_digit(
                    $userId
                )
            ) {
                $this->commercialActionError =
                    'Responsável inválido.';

                return;
            }

            $owner =
                User::query()
                    ->whereNotNull(
                        'email_verified_at'
                    )
                    ->find(
                        (int) $userId
                    );

            if ($owner === null) {
                $this->commercialActionError =
                    'Usuário não encontrado.';

                return;
            }
        }

        try {
            $state =
                $service->assign(
                    company: $company,

                    owner: $owner,
                );

            $label =
                $state
                    ->assignedUser
                    ?->name
                ?? 'Sem responsável';

            $this->commercialActionMessage =
                'Responsável atualizado: '
                .$label
                .'.';
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->commercialActionError =
                'Não foi possível atualizar o responsável.';
        }
    }

    public function claimLead(
        int $companyId,
        LeadOwnershipService $service,
    ): void {
        abort_unless(
            $this
                ->isCommercialManager(),
            403
        );

        $user =
            auth()->user();

        if (! $user instanceof User) {
            $this->commercialActionError =
                'Usuário autenticado não encontrado.';

            return;
        }

        $this->assignOwner(
            companyId: $companyId,

            userId: (string) $user->id,

            service: $service,
        );
    }

    private function operationalLeadQuery()
    {
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
            ->when(
                ! $this->isCommercialManager(),
                function ($query): void {
                    $userId =
                        $this
                            ->authenticatedUserId();

                    if ($userId === null) {
                        $query->whereRaw(
                            '1 = 0'
                        );

                        return;
                    }

                    $query->whereHas(
                        'leadWorkState',
                        fn ($ownerQuery) => $ownerQuery->where(
                            'assigned_user_id',
                            $userId
                        )
                    );
                }
            );
    }

    #[Computed]
    public function operationalCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->count();
    }

    #[Computed]
    public function newCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                function ($query): void {
                    $query
                        ->where(
                            'work.work_status',
                            'new'
                        )
                        ->orWhereNull(
                            'work.id'
                        );
                }
            )
            ->count();
    }

    #[Computed]
    public function contactingCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'contacting'
            )
            ->count();
    }

    #[Computed]
    public function waitingCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'waiting'
            )
            ->count();
    }

    #[Computed]
    public function discardedCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'discarded'
            )
            ->count();
    }

    #[Computed]
    public function futureCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'future'
            )
            ->count();
    }

    #[Computed]
    public function refusedCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'refused'
            )
            ->count();
    }

    #[Computed]
    public function convertedCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'converted'
            )
            ->count();
    }

    #[Computed]
    public function dailyQueueCount(): int
    {
        $userId =
            $this
                ->authenticatedUserId();

        if ($userId === null) {
            return 0;
        }

        return $this
            ->operationalLeadQuery()
            ->whereHas(
                'leadWorkState',
                fn ($query) => $query->where(
                    'assigned_user_id',
                    $userId
                )
            )
            ->where(
                function ($query): void {
                    $query
                        ->where(
                            function ($waitingQuery): void {
                                $waitingQuery
                                    ->where(
                                        'work.work_status',
                                        'waiting'
                                    )
                                    ->whereNotNull(
                                        'work.last_task_due_at'
                                    )
                                    ->where(
                                        'work.last_task_due_at',
                                        '<',
                                        now()
                                            ->addDay()
                                            ->startOfDay()
                                    );
                            }
                        )
                        ->orWhere(
                            'work.work_status',
                            'contacting'
                        )
                        ->orWhere(
                            function ($futureQuery): void {
                                $futureQuery
                                    ->where(
                                        'work.work_status',
                                        'future'
                                    )
                                    ->whereNotNull(
                                        'work.last_task_due_at'
                                    )
                                    ->where(
                                        'work.last_task_due_at',
                                        '<',
                                        now()
                                            ->addDay()
                                            ->startOfDay()
                                    );
                            }
                        );
                }
            )
            ->count();
    }

    public function applyDailyView(): void
    {
        $this->staleOnly = false;
        $this->reprospectingReadyOnly = false;

        if (
            $this->dailyView
            === 'today'
        ) {
            $this->dailyView = '';

            $this->resetPage();

            return;
        }

        $this->dailyView =
            'today';

        $this->owner = '';
        $this->workStatus = '';
        $this->followUp = '';

        $this->resetPage();
    }

    #[Computed]
    public function overdueCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'waiting'
            )
            ->whereNotNull(
                'work.last_task_due_at'
            )
            ->where(
                'work.last_task_due_at',
                '<',
                now()
            )
            ->count();
    }

    #[Computed]
    public function dueTodayCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'waiting'
            )
            ->whereNotNull(
                'work.last_task_due_at'
            )
            ->where(
                'work.last_task_due_at',
                '>=',
                now()
            )
            ->where(
                'work.last_task_due_at',
                '<',
                now()
                    ->addDay()
                    ->startOfDay()
            )
            ->count();
    }

    #[Computed]
    public function upcomingCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'waiting'
            )
            ->whereNotNull(
                'work.last_task_due_at'
            )
            ->where(
                'work.last_task_due_at',
                '>=',
                now()
                    ->addDay()
                    ->startOfDay()
            )
            ->count();
    }

    #[Computed]
    public function unscheduledCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'waiting'
            )
            ->whereNull(
                'work.last_task_due_at'
            )
            ->count();
    }

    #[Computed]
    public function reprospectingReadyCount(): int
    {
        $cooldownDays =
            max(
                1,
                (int) config(
                    'prospector.crm.reprospecting_after_days',
                    180
                )
            );

        return $this
            ->operationalLeadQuery()
            ->where(
                function ($query) use (
                    $cooldownDays
                ): void {
                    $query
                        ->where(
                            function ($futureQuery): void {
                                $futureQuery
                                    ->where(
                                        'work.work_status',
                                        'future'
                                    )
                                    ->whereNotNull(
                                        'work.last_task_due_at'
                                    )
                                    ->where(
                                        'work.last_task_due_at',
                                        '<=',
                                        now()
                                    );
                            }
                        )
                        ->orWhere(
                            function ($refusedQuery) use (
                                $cooldownDays
                            ): void {
                                $refusedQuery
                                    ->where(
                                        'work.work_status',
                                        'refused'
                                    )
                                    ->whereNotNull(
                                        'work.work_status_changed_at'
                                    )
                                    ->where(
                                        'work.work_status_changed_at',
                                        '<=',
                                        now()->subDays(
                                            $cooldownDays
                                        )
                                    );
                            }
                        );
                }
            )
            ->count();
    }

    public function applyReprospectingReadyView(): void
    {
        $enable =
            ! $this->reprospectingReadyOnly;

        $this->dailyView = '';
        $this->followUp = '';
        $this->workStatus = '';
        $this->staleOnly = false;

        $this->reprospectingReadyOnly =
            $enable;

        $this->resetPage();
    }

    /**
     * @return array{
     *     applicable: bool,
     *     eligible: bool,
     *     reason: string,
     *     message: string,
     *     next_allowed_at: string|null,
     *     days_remaining: int|null
     * }|null
     */
    public function reprospectingInfo(
        ?CompanyHubSpotLead $lead
    ): ?array {
        if ($lead === null) {
            return null;
        }

        $result =
            app(
                HubSpotLeadReprospectingService::class
            )->evaluate(
                $lead
            );

        return $result['applicable']
            ? $result
            : null;
    }

    public function staleAfterDays(): int
    {
        return max(
            1,
            (int) config(
                'prospector.sdr.stale_after_days',
                7
            )
        );
    }

    #[Computed]
    public function staleCount(): int
    {
        return $this
            ->operationalLeadQuery()
            ->where(
                'work.work_status',
                'contacting'
            )
            ->whereNotNull(
                'work.last_activity_at'
            )
            ->where(
                'work.last_activity_at',
                '<=',
                now()->subDays(
                    $this->staleAfterDays()
                )
            )
            ->count();
    }

    public function applyStaleView(): void
    {
        $enable =
            ! $this->staleOnly;

        $this->dailyView = '';
        $this->followUp = '';
        $this->workStatus = '';
        $this->reprospectingReadyOnly = false;

        $this->staleOnly =
            $enable;

        $this->resetPage();
    }

    public function applyFollowUpView(
        string $view
    ): void {
        $this->dailyView = '';
        $this->staleOnly = false;
        $this->reprospectingReadyOnly = false;

        $this->followUp =
            in_array(
                $view,
                [
                    'overdue',
                    'today',
                    'upcoming',
                    'unscheduled',
                ],
                true
            )
                ? $view
                : '';

        if (
            $this->followUp
            !== ''
        ) {
            $this->workStatus =
                'waiting';
        }

        $this->resetPage();
    }

    public function applyQuickView(
        string $view
    ): void {
        $this->dailyView = '';
        $this->staleOnly = false;
        $this->reprospectingReadyOnly = false;

        $this->workStatus =
            in_array(
                $view,
                [
                    'new',
                    'contacting',
                    'waiting',
                    'future',
                    'refused',
                    'converted',
                    'discarded',
                ],
                true
            )
                ? $view
                : '';

        $this->followUp = '';

        $this->resetPage();
    }

    public function resumeLead(
        int $leadId,
        HubSpotLeadReprospectingActionService $service,
    ): void {
        $this->commercialActionMessage = '';
        $this->commercialActionError = '';

        $lead =
            CompanyHubSpotLead::query()
                ->findOrFail(
                    $leadId
                );

        $this->assertCanOperateLead(
            $lead
        );

        try {
            $updated =
                $service->resume(
                    $lead
                );

            $this->commercialActionMessage =
                'Lead retomado no HubSpot. Status atual: '
                .$this->workStatusLabel(
                    $updated->work_status
                )
                .'.';
        } catch (DomainException $exception) {
            $this->commercialActionError =
                $exception->getMessage();
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->commercialActionError =
                'Não foi possível retomar o lead no HubSpot.';
        }
    }

    public function refreshHubSpotStatus(
        int $leadId,
        HubSpotLeadStatusSyncService $service,
        CrmCheckService $crmService,
        CrmCompanyProvider $crmProvider,
        HubSpotRefreshDiffService $diff,
    ): void {
        $lead =
            CompanyHubSpotLead::query()
                ->with(
                    'company'
                )
                ->findOrFail(
                    $leadId
                );

        $this->assertCanOperateLead(
            $lead
        );

        $company =
            $lead->company;

        if ($company === null) {
            return;
        }

        $companyId =
            $company->id;

        unset(
            $this->leadRefreshFeedback[
                $companyId
            ]
        );

        $before =
            $diff->snapshot(
                $companyId
            );

        try {
            if (
                trim(
                    (string)
                    $lead->hubspot_company_id
                ) !== ''
                && trim(
                    (string)
                    $lead->hubspot_deal_id
                ) !== ''
            ) {
                $service->sync(
                    $lead
                );
            }

            /*
             * Atualiza TODO o CRM:
             * - empresa;
             * - negócios;
             * - etapas;
             * - cliente/oportunidade;
             * - contatos;
             * - score/prioridade.
             */
            $crmService->check(
                $company,
                $crmProvider,
            );

            $after =
                $diff->snapshot(
                    $companyId
                );

            $changes =
                $diff->changes(
                    $before,
                    $after
                );

            $this->leadRefreshFeedback[
                $companyId
            ] = [
                'status' => 'success',

                'message' => $changes === []
                        ? 'HubSpot atualizado. Nenhuma alteração encontrada.'
                        : (
                            count(
                                $changes
                            )
                            .' alteração(ões) encontrada(s).'
                        ),

                'changes' => array_slice(
                    $changes,
                    0,
                    6
                ),

                'updated_at' => now()
                    ->format(
                        'H:i:s'
                    ),
            ];
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->leadRefreshFeedback[
                $companyId
            ] = [
                'status' => 'error',

                'message' => 'Não foi possível atualizar esta empresa: '
                    .$exception
                        ->getMessage(),

                'changes' => [],

                'updated_at' => now()
                    ->format(
                        'H:i:s'
                    ),
            ];
        }
    }

    public function crmLabel(
        ?string $status
    ): string {
        return match ($status) {
            'client' => 'Cliente',

            'opportunity' => 'Oportunidade',

            'prospected' => 'Reprospecção',

            'known' => 'Conhecido',

            'not_found' => 'Não encontrado',

            default => 'Não verificado',
        };
    }

    public function scoreQualityLabel(
        int $score
    ): string {
        return match (true) {
            $score >= 85 => 'Excelente',

            $score >= 70 => 'Muito bom',

            $score >= 50 => 'Bom',

            default => 'Baixo',
        };
    }

    public function scoreQualityClass(
        int $score
    ): string {
        return $score >= 50
            ? 'is-good'
            : 'is-low';
    }

    /**
     * Todos os negócios encontrados para
     * a empresa no HubSpot.
     *
     * Negócios abertos aparecem primeiro,
     * depois ganhos e por último os demais
     * negócios encerrados.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     stage: string,
     *     state: string,
     *     url: string|null
     * }>
     */
    public function crmDeals(
        ?CompanyCrmCheck $crm
    ): array {
        if ($crm === null) {
            return [];
        }

        $rawDeals =
            data_get(
                $crm->metadata ?? [],
                'deals',
                []
            );

        if (! is_array($rawDeals)) {
            return [];
        }

        $deals = [];

        foreach ($rawDeals as $deal) {
            if (! is_array($deal)) {
                continue;
            }

            $rawId =
                $deal['id']
                ?? null;

            $id =
                is_scalar($rawId)
                    ? trim(
                        (string) $rawId
                    )
                    : '';

            $rawStage =
                $deal['stage_label']
                ?? $deal['stage_id']
                ?? null;

            $stage =
                is_scalar($rawStage)
                    ? trim(
                        (string) $rawStage
                    )
                    : '';

            if ($stage === '') {
                $stage =
                    'Sem etapa';
            }

            $rawName =
                $deal['name']
                ?? null;

            $name =
                is_scalar($rawName)
                    ? trim(
                        (string) $rawName
                    )
                    : '';

            if ($name === '') {
                $name =
                    'Negócio HubSpot';
            }

            $isWon =
                (bool) (
                    $deal['is_closed_won']
                    ?? false
                );

            $isClosed =
                (bool) (
                    $deal['is_closed']
                    ?? false
                );

            $state =
                $isWon
                    ? 'won'
                    : (
                        $isClosed
                            ? 'closed'
                            : 'active'
                    );

            $deals[] = [
                'id' => $id,

                'name' => $name,

                'stage' => $stage,

                'state' => $state,

                'url' => $id !== ''
                        ? $this
                            ->hubSpotDealUrlById(
                                $id
                            )
                        : null,
            ];
        }

        $rank = [
            'active' => 0,
            'won' => 1,
            'closed' => 2,
        ];

        usort(
            $deals,
            static function (
                array $a,
                array $b
            ) use (
                $rank
            ): int {
                $aRank =
                    $rank[
                        $a['state']
                    ]
                    ?? 99;

                $bRank =
                    $rank[
                        $b['state']
                    ]
                    ?? 99;

                if ($aRank !== $bRank) {
                    return $aRank
                        <=>
                        $bRank;
                }

                return strcasecmp(
                    $a['stage'],
                    $b['stage']
                );
            }
        );

        return $deals;
    }

    public function hubSpotDealUrlById(
        string $dealId
    ): ?string {
        $dealId =
            trim(
                $dealId
            );

        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        if (
            $dealId === ''
            || $portalId === ''
        ) {
            return null;
        }

        return sprintf(
            'https://app.hubspot.com/contacts/%s/record/0-3/%s',
            rawurlencode(
                $portalId
            ),
            rawurlencode(
                $dealId
            ),
        );
    }

    public function priorityLabel(
        ?string $priority
    ): string {
        return match ($priority) {
            'very_high' => 'Muito alta',

            'high' => 'Alta',

            'medium' => 'Média',

            default => 'Baixa',
        };
    }

    public function hubSpotCompanyUrlById(
        ?string $companyId
    ): ?string {
        $companyId =
            trim(
                (string) $companyId
            );

        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        if (
            $portalId === ''
            || $companyId === ''
        ) {
            return null;
        }

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

    public function hubSpotDealUrl(
        ?CompanyHubSpotLead $lead
    ): ?string {
        if ($lead === null) {
            return null;
        }

        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        $dealId =
            trim(
                (string) $lead
                    ->hubspot_deal_id
            );

        if (
            $portalId === ''
            || $dealId === ''
        ) {
            return null;
        }

        return sprintf(
            'https://app.hubspot.com/contacts/%s/record/0-3/%s',
            rawurlencode(
                $portalId
            ),
            rawurlencode(
                $dealId
            ),
        );
    }

    public function nextTaskSubject(
        ?CompanyHubSpotLead $lead
    ): ?string {
        if ($lead === null) {
            return null;
        }

        $tasks =
            data_get(
                $lead->metadata ?? [],
                'hubspot_status.open_tasks',
                []
            );

        if (! is_array($tasks)) {
            return null;
        }

        $validTasks = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $subject =
                trim(
                    (string) (
                        $task['subject']
                        ?? ''
                    )
                );

            if ($subject === '') {
                continue;
            }

            $validTasks[] = [
                'subject' => $subject,

                'due_at' => is_string(
                    $task['due_at']
                    ?? null
                )
                        ? $task['due_at']
                        : null,
            ];
        }

        if ($validTasks === []) {
            return null;
        }

        usort(
            $validTasks,
            static function (
                array $a,
                array $b
            ): int {
                $aDue =
                    $a['due_at']
                    ?? '9999';

                $bDue =
                    $b['due_at']
                    ?? '9999';

                return strcmp(
                    $aDue,
                    $bDue
                );
            }
        );

        return $validTasks[0][
            'subject'
        ];
    }

    public function workContext(
        ?CompanyHubSpotLead $lead
    ): string {
        if ($lead === null) {
            return 'Ainda não enviado ao HubSpot';
        }

        if (
            $lead->work_status
            === 'waiting'
        ) {
            return $this->nextTaskSubject(
                $lead
            )
                ?? 'Follow-up pendente';
        }

        if (
            $lead->work_status
            === 'contacting'
        ) {
            if (
                $lead->last_activity_at
                !== null
            ) {
                return 'Último contato em '
                    .$lead
                        ->last_activity_at
                        ->format(
                            'd/m/Y H:i'
                        );
            }

            return 'Abordagem em andamento';
        }

        return match (
            $lead->work_status
        ) {
            'future' => 'Último contato há mais de 30 dias',

            'reprospecting' => 'Sem atividade comercial há mais de 90 dias',

            'refused' => 'Negócio marcado como recusado no HubSpot',

            'converted' => 'Negócio fechado no HubSpot',

            'discarded' => 'Negócio descartado no HubSpot',

            default => 'Ainda não houve contato',
        };
    }

    public function workContextClass(
        ?CompanyHubSpotLead $lead
    ): string {
        if ($lead === null) {
            return 'text-[#7f89aa]';
        }

        if (
            $lead->work_status
                === 'waiting'
            && $lead->last_task_due_at
                ?->isPast()
        ) {
            return 'text-red-300';
        }

        return match (
            $lead->work_status
        ) {
            'waiting' => 'text-amber-300',

            'contacting' => 'text-[#9ba5c8]',

            'future' => 'text-violet-300',

            'reprospecting' => 'text-rose-300',

            'refused' => 'text-rose-300',

            'converted' => 'text-emerald-300',

            'discarded' => 'text-[#7f89aa]',

            default => 'text-[#7f89aa]',
        };
    }

    public function nextActionLabel(
        ?CompanyHubSpotLead $lead
    ): string {
        if ($lead === null) {
            return 'Iniciar abordagem';
        }

        return match (
            $lead->work_status
        ) {
            'waiting' => $lead->last_task_due_at
                ?->isPast()
                    ? 'Retomar atrasado'
                    : 'Abrir follow-up',

            'contacting' => 'Continuar abordagem',

            'future' => 'Ver oportunidade futura',

            'reprospecting' => 'Retomar contato',

            'refused' => 'Ver recusa',

            'converted' => 'Ver negócio ganho',

            'discarded' => 'Ver histórico',

            default => 'Iniciar abordagem',
        };
    }

    public function nextActionClass(
        ?CompanyHubSpotLead $lead
    ): string {
        if ($lead === null) {
            return 'text-emerald-300 hover:text-emerald-200';
        }

        if (
            $lead->work_status
            === 'waiting'
            && $lead->last_task_due_at
                ?->isPast()
        ) {
            return 'text-red-300 hover:text-red-200';
        }

        return match (
            $lead->work_status
        ) {
            'waiting' => 'text-amber-300 hover:text-amber-200',

            'contacting' => 'text-cyan-300 hover:text-cyan-200',

            'future' => 'text-violet-300 hover:text-violet-200',

            'reprospecting' => 'text-rose-300 hover:text-rose-200',

            'refused' => 'text-rose-300 hover:text-rose-200',

            'converted' => 'text-emerald-300 hover:text-emerald-200',

            'discarded' => 'text-[#8992b1] hover:text-white',

            default => 'text-emerald-300 hover:text-emerald-200',
        };
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
                'queued' => 'Na fila',

                'processing' => 'Pesquisando',

                'failed' => 'Pesquisa falhou',

                default => 'Não pesquisada',
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
                'label' => $label,

                'detail' => trim(
                    (string) (
                        $factor[
                            'detail'
                        ]
                        ?? ''
                    )
                ),

                'points' => $points,
            ];
        }

        usort(
            $reasons,
            static fn (
                array $a,
                array $b
            ): int => $b[
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
    class="ec-page-shell leads-rf"
    wire:poll.10s="$refresh"
>

<style>
    .leads-rf {
        --rf-bg: #071b31;
        --rf-panel: #0b2845;
        --rf-panel-2: #0d3152;
        --rf-border: rgba(111, 181, 226, .16);
        --rf-border-strong: rgba(63, 213, 210, .32);
        --rf-text: #f2f7ff;
        --rf-muted: #7896b5;
        --rf-muted-2: #9cb3ca;
        --rf-cyan: #2eddd2;
        --rf-blue: #4aa8ff;
        --rf-green: #42dfac;
        --rf-yellow: #f5c54b;
        --rf-red: #ff6e7b;
        --rf-violet: #b18cff;
        color: var(--rf-text);
    }

    .leads-rf * {
        box-sizing: border-box;
    }

    .rf-shell {
        display: grid;
        gap: 12px;
    }

    .rf-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 24px;
        padding: 4px 2px 8px;
    }

    .rf-kicker {
        color: var(--rf-cyan);
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .14em;
        text-transform: uppercase;
    }

    .rf-title-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 4px;
    }

    .rf-title {
        margin: 0;
        color: white;
        font-size: clamp(30px, 3vw, 46px);
        line-height: 1;
        letter-spacing: -.035em;
        font-weight: 800;
    }

    .rf-count-pill {
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        padding: 0 10px;
        border: 1px solid rgba(46,221,210,.3);
        border-radius: 999px;
        background: rgba(46,221,210,.07);
        color: var(--rf-cyan);
        font-size: 12px;
        font-weight: 800;
    }

    .rf-subtitle {
        max-width: 680px;
        margin: 10px 0 0;
        color: var(--rf-muted-2);
        font-size: 14px;
        line-height: 1.6;
    }

    .rf-header-guide {
        max-width: 310px;
        padding: 10px 12px;
        border: 1px solid var(--rf-border);
        border-radius: 14px;
        background: linear-gradient(
            135deg,
            rgba(46,221,210,.06),
            rgba(74,168,255,.025)
        );
    }

    .rf-header-guide strong {
        display: block;
        color: var(--rf-text);
        font-size: 12px;
    }

    .rf-header-guide span {
        display: block;
        margin-top: 3px;
        color: var(--rf-muted);
        font-size: 11px;
        line-height: 1.45;
    }

    .rf-alert {
        padding: 12px 15px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
    }

    .rf-alert-ok {
        border: 1px solid rgba(66,223,172,.24);
        background: rgba(66,223,172,.07);
        color: var(--rf-green);
    }

    .rf-alert-error {
        border: 1px solid rgba(255,110,123,.24);
        background: rgba(255,110,123,.07);
        color: var(--rf-red);
    }

    .rf-kpis {
        display: grid;
        grid-template-columns:
            repeat(6, minmax(0, 1fr));
        gap: 10px;
    }

    .rf-kpi {
        position: relative;
        min-height: 92px;
        padding: 14px 15px;
        overflow: hidden;
        border: 1px solid var(--rf-border);
        border-radius: 15px;
        background:
            linear-gradient(
                145deg,
                rgba(18, 60, 96, .72),
                rgba(7, 32, 57, .94)
            );
    }

    .rf-kpi::after {
        content: "";
        position: absolute;
        width: 70px;
        height: 70px;
        top: -25px;
        right: -22px;
        border-radius: 50%;
        background: currentColor;
        opacity: .04;
    }

    .rf-kpi-label {
        color: var(--rf-muted);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .07em;
        text-transform: uppercase;
    }

    .rf-kpi-value {
        margin-top: 8px;
        color: white;
        font-size: 26px;
        line-height: 1;
        font-weight: 800;
        letter-spacing: -.03em;
    }

    .rf-kpi-caption {
        margin-top: 8px;
        color: var(--rf-muted-2);
        font-size: 10px;
        line-height: 1.35;
    }

    .rf-kpi-total {
        color: var(--rf-cyan);
    }

    .rf-kpi-high {
        color: var(--rf-red);
    }

    .rf-kpi-new {
        color: var(--rf-blue);
    }

    .rf-kpi-contacting {
        color: var(--rf-green);
    }

    .rf-kpi-waiting {
        color: var(--rf-yellow);
    }

    .rf-kpi-future {
        color: var(--rf-violet);
    }

    .rf-status-guide {
        display: grid;
        grid-template-columns:
            175px repeat(5, minmax(0, 1fr));
        border: 1px solid var(--rf-border);
        border-radius: 15px;
        overflow: hidden;
        background: rgba(7, 30, 52, .65);
    }

    .rf-guide-title,
    .rf-guide-item {
        min-height: 62px;
        padding: 11px 14px;
    }

    .rf-guide-title {
        display: flex;
        flex-direction: column;
        justify-content: center;
        border-right: 1px solid var(--rf-border);
    }

    .rf-guide-title strong {
        font-size: 12px;
    }

    .rf-guide-title span {
        margin-top: 3px;
        color: var(--rf-muted);
        font-size: 10px;
        line-height: 1.35;
    }

    .rf-guide-item {
        border-right: 1px solid rgba(111,181,226,.09);
    }

    .rf-guide-item:last-child {
        border-right: 0;
    }

    .rf-guide-item strong {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
    }

    .rf-guide-dot {
        width: 7px;
        height: 7px;
        flex: 0 0 7px;
        border-radius: 50%;
    }

    .rf-guide-item span {
        display: block;
        margin-top: 6px;
        color: var(--rf-muted);
        font-size: 10px;
        line-height: 1.35;
    }

    .rf-panel {
        border: 1px solid var(--rf-border);
        border-radius: 16px;
        background:
            linear-gradient(
                180deg,
                rgba(10, 39, 66, .9),
                rgba(6, 28, 50, .9)
            );
    }

    .rf-filters {
        padding: 14px;
    }

    .rf-filter-main {
        display: grid;
        grid-template-columns:
            minmax(220px, 1.7fr)
            repeat(4, minmax(145px, .85fr));
        gap: 9px;
    }

    .rf-filter-secondary {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(150px, 1fr))
            auto auto auto;
        align-items: center;
        gap: 9px;
        margin-top: 9px;
    }

    .rf-input,
    .rf-select {
        width: 100%;
        height: 40px;
        border: 1px solid rgba(128,184,222,.16);
        border-radius: 10px;
        outline: none;
        background: #081f37;
        color: #dcecff;
        font-size: 11px;
        transition: border-color .18s ease,
                    background .18s ease;
    }

    .rf-input {
        padding: 0 12px;
    }

    .rf-select {
        padding: 0 10px;
    }

    .rf-input:focus,
    .rf-select:focus {
        border-color: rgba(46,221,210,.55);
        background: #092742;
    }

    .rf-filter-action {
        height: 40px;
        padding: 0 13px;
        border: 1px solid var(--rf-border);
        border-radius: 10px;
        background: rgba(255,255,255,.025);
        color: var(--rf-muted-2);
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    .rf-filter-action:hover {
        border-color: var(--rf-border-strong);
        color: var(--rf-cyan);
    }

    .rf-filter-action.is-active {
        border-color: rgba(46,221,210,.34);
        background: rgba(46,221,210,.08);
        color: var(--rf-cyan);
    }

    .rf-list-panel {
        overflow: hidden;
    }

    .rf-list-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        padding: 14px 17px;
        border-bottom: 1px solid var(--rf-border);
    }

    .rf-list-toolbar strong {
        display: block;
        font-size: 12px;
    }

    .rf-list-toolbar span {
        display: block;
        margin-top: 2px;
        color: var(--rf-muted);
        font-size: 10px;
    }

    .rf-list-legend {
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--rf-muted);
        font-size: 10px;
    }

    .rf-list-scroll {
        overflow-x: auto;
    }

    .rf-list-head,
    .rf-lead-row {
        display: grid;
        grid-template-columns:
            minmax(255px, 1.45fr)
            135px
            135px
            minmax(165px, .92fr)
            minmax(205px, 1.12fr)
            150px
            170px;
        min-width: 1250px;
        gap: 14px;
        align-items: center;
    }

    .rf-list-head {
        padding: 10px 17px;
        border-bottom: 1px solid rgba(111,181,226,.09);
        background: rgba(26, 77, 113, .16);
        color: #6e91b2;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    .rf-lead-row {
        position: relative;
        min-height: 106px;
        padding: 13px 17px 13px 19px;
        border-bottom: 1px solid rgba(111,181,226,.09);
        border-left: 3px solid transparent;
        transition:
            background .16s ease,
            border-color .16s ease;
    }

    .rf-row-high {
        border-left-color: rgba(255,110,123,.72);
    }

    .rf-row-medium {
        border-left-color: rgba(245,197,75,.42);
    }

    .rf-row-low {
        border-left-color: rgba(74,168,255,.18);
    }

    .rf-row-overdue {
        background:
            linear-gradient(
                90deg,
                rgba(255,80,92,.045),
                transparent 38%
            );
    }

    .rf-lead-row:last-child {
        border-bottom: 0;
    }

    .rf-lead-row:hover {
        background: rgba(58, 151, 211, .035);
    }

    .rf-company-name {
        display: -webkit-box;
        overflow: hidden;
        min-height: 34px;
        color: #f5f9ff;
        font-size: 14px;
        font-weight: 800;
        line-height: 1.28;
        letter-spacing: -.01em;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .rf-location {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 5px;
        color: #91abc3;
        font-size: 10px;
        font-weight: 600;
    }

    .rf-company-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 9px;
    }

    .rf-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 21px;
        padding: 0 7px;
        border: 1px solid rgba(134,190,228,.14);
        border-radius: 999px;
        background: rgba(255,255,255,.025);
        color: #a8bdd1;
        font-size: 9px;
        font-weight: 700;
        white-space: nowrap;
    }

    .rf-chip-dot {
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: currentColor;
    }

    .rf-commercial-new {
        color: #78bfff;
        border-color: rgba(74,168,255,.2);
        background: rgba(74,168,255,.07);
    }

    .rf-commercial-known {
        color: #70d4ff;
        border-color: rgba(112,212,255,.2);
        background: rgba(112,212,255,.06);
    }

    .rf-commercial-client {
        color: #55e0aa;
        border-color: rgba(85,224,170,.2);
        background: rgba(85,224,170,.06);
    }

    .rf-commercial-reprospecting {
        color: #ff8090;
        border-color: rgba(255,128,144,.2);
        background: rgba(255,128,144,.06);
    }

    .rf-score-top {
        display: flex;
        align-items: baseline;
        gap: 4px;
    }

    .rf-score-number {
        color: #ffffff;
        font-size: 27px;
        line-height: 1;
        font-weight: 850;
        letter-spacing: -.045em;
    }

    .rf-score-max {
        color: var(--rf-muted);
        font-size: 10px;
        font-weight: 700;
    }

    .rf-score-bar {
        width: 90px;
        height: 4px;
        margin-top: 7px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(255,255,255,.07);
    }

    .rf-score-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(
            90deg,
            #2fd8ce,
            #59e3b2
        );
    }

    .rf-score-low .rf-score-fill {
        background: linear-gradient(
            90deg,
            #f3b94f,
            #f06c62
        );
    }

    .rf-score-info {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 8px;
    }

    .rf-score-quality {
        color: #7896b5;
        font-size: 9px;
        font-weight: 700;
        white-space: nowrap;
    }

    .rf-priority {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 0 8px;
        border-radius: 999px;
        font-size: 9px;
        font-weight: 800;
    }

    .rf-priority-high {
        border: 1px solid rgba(255,110,123,.24);
        background: rgba(255,110,123,.08);
        color: #ff8490;
    }

    .rf-priority-medium {
        border: 1px solid rgba(245,197,75,.24);
        background: rgba(245,197,75,.08);
        color: #ffd467;
    }

    .rf-priority-low {
        border: 1px solid rgba(74,168,255,.2);
        background: rgba(74,168,255,.07);
        color: #72baff;
    }

    .rf-icp {
        color: var(--rf-muted-2);
        font-size: 9px;
        font-weight: 800;
    }

    .rf-section-label {
        color: #6f97b9;
        font-size: 9px;
        font-weight: 850;
        letter-spacing: .07em;
        text-transform: uppercase;
    }

    .rf-deals {
        display: grid;
        gap: 5px;
        margin-top: 6px;
    }

    .rf-deal {
        display: flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
    }

    .rf-deal-stage {
        max-width: 150px;
        overflow: hidden;
        color: #5fe0d1;
        font-size: 9px;
        font-weight: 800;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .rf-deal-name {
        overflow: hidden;
        color: var(--rf-muted);
        font-size: 9px;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .rf-more-stages {
        display: inline-flex;
        align-items: center;
        min-height: 21px;
        margin-top: 1px;
        padding: 0 7px;
        border: 1px dashed rgba(126,168,201,.22);
        border-radius: 6px;
        background: rgba(126,168,201,.045);
        color: #8da7c0;
        font-size: 9px;
        font-weight: 750;
        cursor: help;
    }

    .rf-empty {
        margin-top: 6px;
        color: #617f9b;
        font-size: 10px;
    }

    .rf-work-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 27px;
        margin-top: 5px;
        padding: 0 9px;
        border: 1px solid rgba(124,184,222,.13);
        border-radius: 8px;
        background: rgba(255,255,255,.025);
        font-size: 10px;
        font-weight: 800;
    }

    .rf-work-new {
        color: #8ab5da;
    }

    .rf-work-contacting {
        color: #52e0c6;
    }

    .rf-work-waiting {
        color: #ffd25f;
    }

    .rf-work-future {
        color: #9eabff;
    }

    .rf-work-reprospecting {
        color: #ff8290;
    }

    .rf-work-context {
        display: -webkit-box;
        overflow: hidden;
        max-width: 230px;
        margin-top: 7px;
        color: #adc2d6;
        font-size: 10px;
        line-height: 1.45;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .rf-work-date {
        margin-top: 5px;
        color: #6787a4;
        font-size: 9px;
    }

    .rf-sync-state {
        display: flex;
        align-items: center;
        gap: 5px;
        margin-top: 6px;
        color: #6889a7;
        font-size: 8px;
        line-height: 1.35;
    }

    .rf-sync-dot {
        flex: 0 0 auto;
        width: 5px;
        height: 5px;
        border-radius: 999px;
        background: #42dfac;
        box-shadow:
            0 0 0 2px rgba(66,223,172,.08);
    }

    .rf-sync-state.is-aging {
        color: #9b8c64;
    }

    .rf-sync-state.is-aging .rf-sync-dot {
        background: #f5c54b;
        box-shadow:
            0 0 0 2px rgba(245,197,75,.08);
    }

    .rf-sync-state.is-old {
        color: #ba7680;
    }

    .rf-sync-state.is-old .rf-sync-dot {
        background: #ff6e7b;
        box-shadow:
            0 0 0 2px rgba(255,110,123,.08);
    }

    .rf-owner-select {
        width: 100%;
        height: 34px;
        margin-top: 6px;
        padding: 0 8px;
        border: 1px solid rgba(128,184,222,.15);
        border-radius: 8px;
        outline: none;
        background: #081f37;
        color: #dcecff;
        font-size: 10px;
    }

    .rf-owner-name {
        margin-top: 6px;
        color: #dbe9f7;
        font-size: 10px;
        font-weight: 700;
    }

    .rf-owner-helper {
        margin-top: 5px;
        color: #6686a4;
        font-size: 9px;
    }

    .rf-claim {
        margin-top: 6px;
        padding: 0;
        border: 0;
        background: none;
        color: var(--rf-cyan);
        font-size: 9px;
        font-weight: 800;
        cursor: pointer;
    }

    .rf-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 6px;
    }

    .rf-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 31px;
        padding: 0 10px;
        border: 1px solid rgba(128,184,222,.16);
        border-radius: 8px;
        background: rgba(255,255,255,.025);
        color: #b9cce0;
        font-size: 9px;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        transition: border-color .15s ease,
                    color .15s ease,
                    background .15s ease;
    }

    .rf-btn:hover {
        border-color: rgba(46,221,210,.35);
        color: var(--rf-cyan);
        background: rgba(46,221,210,.04);
    }

    .rf-btn-secondary {
        border-color: rgba(128,184,222,.10);
        background: transparent;
        color: #829db7;
    }

    .rf-btn-secondary:hover {
        border-color: rgba(46,221,210,.25);
        background: rgba(46,221,210,.035);
        color: #b9e7e3;
    }

    .rf-btn-primary {
        border-color: rgba(46,221,210,.28);
        background: rgba(46,221,210,.07);
        color: var(--rf-cyan);
    }

    .rf-btn-warning {
        border-color: rgba(245,197,75,.24);
        background: rgba(245,197,75,.06);
        color: #ffd469;
    }

    .rf-btn-reprospect {
        border-color: rgba(255,110,123,.24);
        background: rgba(255,110,123,.06);
        color: #ff8490;
    }

    .rf-refresh-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 31px;
        height: 31px;
        padding: 0;
        border: 1px solid rgba(128,184,222,.12);
        border-radius: 8px;
        background: transparent;
        color: #7896b5;
        font-size: 15px;
        font-weight: 800;
        cursor: pointer;
        transition:
            color .15s ease,
            border-color .15s ease,
            background .15s ease;
    }

    .rf-refresh-btn:hover {
        border-color: rgba(46,221,210,.28);
        background: rgba(46,221,210,.045);
        color: #5ee2d7;
    }

    .rf-refresh-btn:disabled {
        cursor: wait;
        opacity: .45;
    }

    .rf-mini-note {
        margin-top: 6px;
        color: #6586a3;
        font-size: 9px;
        line-height: 1.4;
    }

    .rf-empty-state {
        padding: 50px 20px;
        text-align: center;
    }

    .rf-empty-state strong {
        display: block;
        font-size: 14px;
    }

    .rf-empty-state span {
        display: block;
        margin-top: 5px;
        color: var(--rf-muted);
        font-size: 11px;
    }

    .rf-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding: 13px 17px;
        border-top: 1px solid var(--rf-border);
    }

    .rf-pagination-info {
        color: var(--rf-muted);
        font-size: 10px;
    }

    .rf-pagination-info strong {
        color: var(--rf-text);
    }

    .rf-pagination-buttons {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .rf-page-btn {
        min-width: 30px;
        height: 30px;
        padding: 0 8px;
        border: 1px solid var(--rf-border);
        border-radius: 8px;
        background: rgba(255,255,255,.025);
        color: #91abc4;
        font-size: 10px;
        font-weight: 800;
        cursor: pointer;
    }

    .rf-page-btn:hover:not(:disabled),
    .rf-page-btn.is-active {
        border-color: rgba(46,221,210,.35);
        background: rgba(46,221,210,.09);
        color: var(--rf-cyan);
    }

    .rf-page-btn:disabled {
        cursor: not-allowed;
        opacity: .3;
    }

    .rf-export-state {
        margin-top: 8px;
        color: #718da8;
        font-size: 9px;
        line-height: 1.35;
    }

    .rf-export-state strong {
        color: #91abc3;
        font-weight: 700;
    }

    .rf-crm-status {
        margin-top: 6px;
        color: #7996b1;
        font-size: 9px;
        line-height: 1.35;
    }

    .rf-stages {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 7px;
    }

    .rf-stage {
        display: inline-flex;
        align-items: center;
        min-height: 21px;
        max-width: 155px;
        padding: 0 7px;
        overflow: hidden;
        border: 1px solid rgba(46,221,210,.15);
        border-radius: 6px;
        background: rgba(46,221,210,.05);
        color: #58ddce;
        font-size: 9px;
        font-weight: 750;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .rf-overdue-badge {
        display: inline-flex;
        align-items: center;
        min-height: 20px;
        margin-left: 5px;
        padding: 0 6px;
        border: 1px solid rgba(255,110,123,.24);
        border-radius: 999px;
        background: rgba(255,110,123,.07);
        color: #ff7f8b;
        font-size: 8px;
        font-weight: 850;
        text-transform: uppercase;
    }

    .rf-commercial-box {
        margin-top: 6px;
    }

    .rf-commercial-explain {
        margin-top: 7px;
        color: #718eaa;
        font-size: 9px;
        line-height: 1.4;
    }

    .rf-next-action {
        margin-top: 6px;
        color: #ecf5ff;
        font-size: 11px;
        font-weight: 800;
        line-height: 1.35;
    }

    .rf-next-action.is-overdue {
        color: #ff7e8a;
    }

    .rf-deal-stage {
        display: inline-flex;
        align-items: center;
        max-width: 165px;
        min-height: 22px;
        padding: 0 7px;
        border: 1px solid rgba(46,221,210,.13);
        border-radius: 6px;
        background: rgba(46,221,210,.045);
        text-decoration: none;
    }

    .rf-deal-name {
        display: none;
    }

    .rf-deals {
        gap: 6px;
    }

    .rf-owner-select {
        font-size: 11px;
    }

    .rf-btn {
        font-size: 10px;
    }

    @media (max-width: 1350px) {
        .rf-kpis {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }

        .rf-status-guide {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }

        .rf-guide-title {
            grid-column: 1 / -1;
            min-height: auto;
            border-right: 0;
            border-bottom: 1px solid var(--rf-border);
        }
    }

    @media (max-width: 900px) {
        .rf-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .rf-header-guide {
            width: 100%;
            max-width: none;
        }

        .rf-kpis {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .rf-status-guide {
            grid-template-columns:
                1fr;
        }

        .rf-guide-item {
            min-height: auto;
            border-right: 0;
            border-bottom: 1px solid var(--rf-border);
        }

        .rf-filter-main,
        .rf-filter-secondary {
            grid-template-columns: 1fr;
        }

        .rf-pagination {
            align-items: flex-start;
            flex-direction: column;
        }
    }






    /* LEADS_WIDTH_ONLY_START */

    /*
     * A tela padrão possui:
     *
     * .ec-page-shell {
     *     max-width: 1380px;
     * }
     *
     * Para Leads queremos aproveitar toda a largura
     * disponível da área principal, mantendo os
     * mesmos tamanhos de fonte/componentes.
     */
    .ec-page-shell.leads-rf {
        width: 100% !important;
        max-width: none !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
    }

    .leads-rf .rf-shell {
        width: 100% !important;
        max-width: none !important;
    }

    .leads-rf .rf-header,
    .leads-rf .rf-kpis,
    .leads-rf .rf-status-guide,
    .leads-rf .rf-filters,
    .leads-rf .rf-list-panel {
        width: 100% !important;
        max-width: none !important;
    }

    /* LEADS_WIDTH_ONLY_END */


    .rf-header-sync {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        width: min(390px, 100%);
        padding: 11px 12px;
        border: 1px solid rgba(111, 181, 226, .16);
        border-radius: 14px;
        background:
            linear-gradient(
                135deg,
                rgba(46,221,210,.055),
                rgba(74,168,255,.025)
            );
    }

    .rf-header-sync-copy {
        min-width: 0;
    }

    .rf-header-sync-copy strong {
        display: block;
        color: #eef7ff;
        font-size: 11px;
        font-weight: 800;
    }

    .rf-header-sync-copy span {
        display: block;
        margin-top: 3px;
        color: #7896b5;
        font-size: 9px;
        line-height: 1.35;
    }

    .rf-sync-btn {
        flex: 0 0 auto;
        min-height: 36px;
        padding: 0 12px;
        border: 1px solid rgba(46,221,210,.30);
        border-radius: 9px;
        background: rgba(46,221,210,.08);
        color: #4de1d6;
        font-size: 10px;
        font-weight: 800;
        cursor: pointer;
        white-space: nowrap;
        transition:
            background .15s ease,
            border-color .15s ease,
            color .15s ease;
    }

    .rf-sync-btn:hover {
        border-color: rgba(46,221,210,.52);
        background: rgba(46,221,210,.13);
        color: #7cf0e7;
    }

    .rf-sync-btn:disabled {
        cursor: wait;
        opacity: .55;
    }



    /* HUBSPOT_REFRESH_PROGRESS_START */

    .rf-sync-progress {
        padding: 14px 16px;
        border: 1px solid rgba(46,221,210,.18);
        border-radius: 14px;
        background:
            linear-gradient(
                135deg,
                rgba(46,221,210,.055),
                rgba(74,168,255,.025)
            );
    }

    .rf-sync-progress-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .rf-sync-progress-title {
        color: #ecf7ff;
        font-size: 12px;
        font-weight: 800;
    }

    .rf-sync-progress-subtitle {
        margin-top: 3px;
        color: #7896b5;
        font-size: 10px;
    }

    .rf-sync-percent {
        color: #4de1d6;
        font-size: 18px;
        font-weight: 850;
    }

    .rf-progress-track {
        height: 7px;
        margin-top: 11px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(255,255,255,.065);
    }

    .rf-progress-fill {
        height: 100%;
        border-radius: inherit;
        background:
            linear-gradient(
                90deg,
                #2eddd2,
                #59e3b2
            );
        transition: width .35s ease;
    }

    .rf-sync-stats {
        display: grid;
        grid-template-columns:
            repeat(5, minmax(0, 1fr));
        gap: 8px;
        margin-top: 12px;
    }

    .rf-sync-stat {
        padding: 8px 10px;
        border: 1px solid rgba(111,181,226,.10);
        border-radius: 9px;
        background: rgba(255,255,255,.018);
    }

    .rf-sync-stat span {
        display: block;
        color: #6f91b1;
        font-size: 8px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .rf-sync-stat strong {
        display: block;
        margin-top: 3px;
        color: #edf6ff;
        font-size: 14px;
    }

    .rf-sync-summary {
        display: grid;
        grid-template-columns:
            minmax(220px,.7fr)
            minmax(0,1.3fr);
        gap: 12px;
        margin-top: 12px;
    }

    .rf-sync-summary-box {
        padding: 10px 12px;
        border: 1px solid rgba(111,181,226,.10);
        border-radius: 10px;
        background: rgba(255,255,255,.018);
    }

    .rf-sync-summary-box > strong {
        color: #dceafa;
        font-size: 10px;
    }

    .rf-sync-categories {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 8px;
    }

    .rf-sync-category {
        display: inline-flex;
        gap: 5px;
        padding: 4px 7px;
        border-radius: 999px;
        background: rgba(46,221,210,.06);
        color: #8eb5c9;
        font-size: 9px;
    }

    .rf-sync-category b {
        color: #52e0d5;
    }

    .rf-sync-company {
        padding: 7px 0;
        border-bottom: 1px solid rgba(111,181,226,.08);
    }

    .rf-sync-company:last-child {
        border-bottom: 0;
    }

    .rf-sync-company-name {
        color: #dbe9f7;
        font-size: 9px;
        font-weight: 800;
    }

    .rf-sync-change {
        margin-top: 3px;
        color: #7896b5;
        font-size: 8px;
        line-height: 1.4;
    }

    .rf-row-sync-result {
        margin-top: 7px;
        padding: 7px 8px;
        border: 1px solid rgba(46,221,210,.14);
        border-radius: 8px;
        background: rgba(46,221,210,.035);
    }

    .rf-row-sync-result.is-error {
        border-color: rgba(255,110,123,.18);
        background: rgba(255,110,123,.04);
    }

    .rf-row-sync-result strong {
        display: block;
        color: #60e2d8;
        font-size: 9px;
    }

    .rf-row-sync-result.is-error strong {
        color: #ff8994;
    }

    .rf-row-sync-result span {
        display: block;
        margin-top: 3px;
        color: #7896b5;
        font-size: 8px;
        line-height: 1.35;
    }

    @media (max-width: 1000px) {
        .rf-sync-stats {
            grid-template-columns:
                repeat(2, minmax(0,1fr));
        }

        .rf-sync-summary {
            grid-template-columns: 1fr;
        }
    }

    /* HUBSPOT_REFRESH_PROGRESS_END */

</style>


<div class="rf-shell">

    @php
        $hubSpotRefreshProgress =
            $this->hubSpotRefreshProgress;
    @endphp

    <header class="rf-header">

        <div>

            <div class="rf-kicker">
                Operação comercial
            </div>

            <div class="rf-title-row">

                <h1 class="rf-title">
                    Leads
                </h1>

                <span class="rf-count-pill">
                    {{
                        number_format(
                            $this->operationalCount,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </span>

            </div>

            <p class="rf-subtitle">
                Veja rapidamente quem priorizar,
                em que situação cada empresa está
                e qual é o próximo passo comercial.
            </p>

        </div>


        @if (
            $this->isCommercialManager()
        )

            <div class="rf-header-sync">

                <div class="rf-header-sync-copy">

                    <strong>
                        CRM / HubSpot
                    </strong>

                    <span>
                        Sincronize negócios, etapas,
                        tarefas e contatos alterados
                        no HubSpot.
                    </span>

                </div>

                <button
                    type="button"
                    wire:click="refreshAllHubSpotData"
                    wire:loading.attr="disabled"
                    wire:target="refreshAllHubSpotData"
                    @disabled(
                        $hubSpotRefreshProgress
                        && $hubSpotRefreshProgress[
                            'running'
                        ]
                    )
                    class="rf-sync-btn"
                >

                    <span
                        wire:loading
                        wire:target="refreshAllHubSpotData"
                    >
                        Preparando...
                    </span>

                    <span
                        wire:loading.remove
                        wire:target="refreshAllHubSpotData"
                    >

                        @if (
                            $hubSpotRefreshProgress
                            && $hubSpotRefreshProgress[
                                'running'
                            ]
                        )

                            Atualizando
                            {{
                                $hubSpotRefreshProgress[
                                    'percent'
                                ]
                            }}%

                        @else

                            ↻ Atualizar CRM / HubSpot

                        @endif

                    </span>

                </button>

            </div>

        @endif

    </header>


    {{-- rf-sync-progress-panel-marker --}}
    @if ($hubSpotRefreshProgress)

        <section class="rf-sync-progress">

            <div class="rf-sync-progress-head">

                <div>

                    <div class="rf-sync-progress-title">

                        @if (
                            $hubSpotRefreshProgress[
                                'running'
                            ]
                        )

                            Atualizando CRM / HubSpot

                        @else

                            Última atualização CRM / HubSpot

                        @endif

                    </div>

                    <div class="rf-sync-progress-subtitle">

                        {{
                            $hubSpotRefreshProgress[
                                'finished'
                            ]
                        }}
                        de
                        {{
                            $hubSpotRefreshProgress[
                                'total'
                            ]
                        }}
                        processadas

                        @if (
                            $hubSpotRefreshProgress[
                                'remaining'
                            ] > 0
                        )

                            · faltam
                            {{
                                $hubSpotRefreshProgress[
                                    'remaining'
                                ]
                            }}

                        @endif

                    </div>

                </div>


                <div class="rf-sync-percent">
                    {{
                        $hubSpotRefreshProgress[
                            'percent'
                        ]
                    }}%
                </div>

            </div>


            <div class="rf-progress-track">

                <div
                    class="rf-progress-fill"
                    style="
                        width:
                        {{
                            $hubSpotRefreshProgress[
                                'percent'
                            ]
                        }}%;
                    "
                ></div>

            </div>


            <div class="rf-sync-stats">

                <div class="rf-sync-stat">
                    <span>Processadas</span>
                    <strong>
                        {{
                            $hubSpotRefreshProgress[
                                'finished'
                            ]
                        }}
                    </strong>
                </div>

                <div class="rf-sync-stat">
                    <span>Faltam</span>
                    <strong>
                        {{
                            $hubSpotRefreshProgress[
                                'remaining'
                            ]
                        }}
                    </strong>
                </div>

                <div class="rf-sync-stat">
                    <span>Com alteração</span>
                    <strong>
                        {{
                            $hubSpotRefreshProgress[
                                'changed'
                            ]
                        }}
                    </strong>
                </div>

                <div class="rf-sync-stat">
                    <span>Sem alteração</span>
                    <strong>
                        {{
                            $hubSpotRefreshProgress[
                                'unchanged'
                            ]
                        }}
                    </strong>
                </div>

                <div class="rf-sync-stat">
                    <span>Falhas</span>
                    <strong>
                        {{
                            $hubSpotRefreshProgress[
                                'failed'
                            ]
                        }}
                    </strong>
                </div>

            </div>


            @if (
                ! $hubSpotRefreshProgress[
                    'running'
                ]
            )

                @php
                    $refreshSummary =
                        $hubSpotRefreshProgress[
                            'summary'
                        ];

                    $refreshCategories =
                        is_array(
                            $refreshSummary[
                                'categories'
                            ]
                            ?? null
                        )
                            ? $refreshSummary[
                                'categories'
                            ]
                            : [];

                    $refreshRecent =
                        is_array(
                            $refreshSummary[
                                'recent'
                            ]
                            ?? null
                        )
                            ? $refreshSummary[
                                'recent'
                            ]
                            : [];
                @endphp


                @if (
                    $refreshCategories !== []
                    || $refreshRecent !== []
                )

                    <div class="rf-sync-summary">

                        <div class="rf-sync-summary-box">

                            <strong>
                                O que mudou
                            </strong>

                            <div class="rf-sync-categories">

                                @foreach (
                                    $refreshCategories
                                    as $category
                                )

                                    <span class="rf-sync-category">

                                        {{
                                            $category[
                                                'label'
                                            ]
                                        }}

                                        <b>
                                            {{
                                                $category[
                                                    'count'
                                                ]
                                            }}
                                        </b>

                                    </span>

                                @endforeach

                            </div>

                        </div>


                        <div class="rf-sync-summary-box">

                            <strong>
                                Empresas com alterações recentes
                            </strong>

                            @foreach (
                                $refreshRecent
                                as $recent
                            )

                                <div class="rf-sync-company">

                                    <div class="rf-sync-company-name">
                                        {{
                                            $recent[
                                                'company'
                                            ]
                                        }}
                                    </div>

                                    @foreach (
                                        $recent[
                                            'changes'
                                        ]
                                        as $change
                                    )

                                        <div class="rf-sync-change">

                                            {{
                                                $change[
                                                    'label'
                                                ]
                                            }}:

                                            {{
                                                $change[
                                                    'before'
                                                ]
                                            }}

                                            →

                                            {{
                                                $change[
                                                    'after'
                                                ]
                                            }}

                                        </div>

                                    @endforeach

                                </div>

                            @endforeach

                        </div>

                    </div>

                @endif

            @endif

        </section>

    @endif


    @if ($commercialActionMessage !== '')

        <div class="rf-alert rf-alert-ok">
            {{ $commercialActionMessage }}
        </div>

    @endif


    @if ($commercialActionError !== '')

        <div class="rf-alert rf-alert-error">
            {{ $commercialActionError }}
        </div>

    @endif


    <section class="rf-kpis">

        <div class="rf-kpi rf-kpi-total">

            <div class="rf-kpi-label">
                Leads na operação
            </div>

            <div class="rf-kpi-value">
                {{
                    number_format(
                        $this->operationalCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </div>

            <div class="rf-kpi-caption">
                Total da fila comercial
            </div>

        </div>


        <div class="rf-kpi rf-kpi-high">

            <div class="rf-kpi-label">
                Prioridade alta
            </div>

            <div class="rf-kpi-value">
                {{
                    number_format(
                        $this->highCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </div>

            <div class="rf-kpi-caption">
                Score 75 a 100
            </div>

        </div>


        <div class="rf-kpi rf-kpi-new">

            <div class="rf-kpi-label">
                Novo
            </div>

            <div class="rf-kpi-value">
                {{
                    number_format(
                        $this->newCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </div>

            <div class="rf-kpi-caption">
                Sem chamada registrada
            </div>

        </div>


        <div class="rf-kpi rf-kpi-contacting">

            <div class="rf-kpi-label">
                Em contato
            </div>

            <div class="rf-kpi-value">
                {{
                    number_format(
                        $this->contactingCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </div>

            <div class="rf-kpi-caption">
                Atividade nos últimos 30 dias
            </div>

        </div>


        <div class="rf-kpi rf-kpi-waiting">

            <div class="rf-kpi-label">
                Aguardando retorno
            </div>

            <div class="rf-kpi-value">
                {{
                    number_format(
                        $this->waitingCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </div>

            <div class="rf-kpi-caption">
                Existe tarefa pendente
            </div>

        </div>


        <div class="rf-kpi rf-kpi-future">

            <div class="rf-kpi-label">
                Oportunidade futura
            </div>

            <div class="rf-kpi-value">
                {{
                    number_format(
                        $this->futureCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </div>

            <div class="rf-kpi-caption">
                Último contato há mais de 30 dias
            </div>

        </div>

    </section>


    <section class="rf-status-guide">

        <div class="rf-guide-title">

            <strong>
                Acompanhamento
            </strong>

            <span>
                O que cada status significa.
            </span>

        </div>


        <div class="rf-guide-item">

            <strong style="color:#78bfff">

                <i
                    class="rf-guide-dot"
                    style="background:#78bfff"
                ></i>

                Novo

            </strong>

            <span>
                Ainda não existe chamada
                registrada para a empresa.
            </span>

        </div>


        <div class="rf-guide-item">

            <strong style="color:#52e0c6">

                <i
                    class="rf-guide-dot"
                    style="background:#52e0c6"
                ></i>

                Em contato

            </strong>

            <span>
                Houve chamada ou contato
                nos últimos 30 dias.
            </span>

        </div>


        <div class="rf-guide-item">

            <strong style="color:#ffd25f">

                <i
                    class="rf-guide-dot"
                    style="background:#ffd25f"
                ></i>

                Aguardando retorno

            </strong>

            <span>
                Existe tarefa aberta
                aguardando uma ação.
            </span>

        </div>


        <div class="rf-guide-item">

            <strong style="color:#9eabff">

                <i
                    class="rf-guide-dot"
                    style="background:#9eabff"
                ></i>

                Oportunidade futura

            </strong>

            <span>
                Último contato passou
                de 30 dias.
            </span>

        </div>


        <div class="rf-guide-item">

            <strong style="color:#ff8290">

                <i
                    class="rf-guide-dot"
                    style="background:#ff8290"
                ></i>

                Reprospecção

            </strong>

            <span>
                Sem atividade comercial
                há mais de 90 dias.
            </span>

        </div>

    </section>


    <section class="rf-panel rf-filters">

        <div class="rf-filter-main">

            <input
                type="search"
                wire:model.live.debounce.350ms="search"
                class="rf-input"
                placeholder="Buscar empresa ou CNPJ..."
            >


            <select
                wire:model.live="priority"
                class="rf-select"
            >
                <option value="">
                    Todas prioridades
                </option>

                <option value="high">
                    Prioridade alta
                </option>

                <option value="medium">
                    Prioridade média
                </option>

                <option value="low">
                    Prioridade baixa
                </option>
            </select>


            <select
                wire:model.live="icp"
                class="rf-select"
            >
                <option value="">
                    Todos ICP
                </option>

                <option value="A">
                    ICP A
                </option>

                <option value="B">
                    ICP B
                </option>

                <option value="C">
                    ICP C
                </option>

                <option value="D">
                    ICP D
                </option>
            </select>


            <select
                wire:model.live="crm"
                class="rf-select"
            >
                <option value="">
                    CRM / etapa HubSpot
                </option>

                <optgroup label="Situação no CRM">

                    <option value="not_found">
                        Novo
                    </option>

                    <option value="known">
                        Conhecido
                    </option>

                    <option value="client">
                        Cliente
                    </option>

                    <option value="prospected">
                        Reprospecção
                    </option>

                </optgroup>

                @if (
                    $this->crmStageOptions
                    !== []
                )

                    <optgroup
                        label="Etapas dos negócios no HubSpot"
                    >

                        @foreach (
                            $this->crmStageOptions
                            as $option
                        )

                            <option
                                value="stage:{{ $option['label'] }}"
                            >
                                {{
                                    $option['label']
                                }}
                                ·
                                {{
                                    $option['count']
                                }}
                            </option>

                        @endforeach

                    </optgroup>

                @endif

            </select>


            <select
                wire:model.live="workStatus"
                class="rf-select"
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

                <option value="future">
                    Oportunidade futura
                </option>

                <option value="reprospecting">
                    Reprospecção
                </option>
            </select>

        </div>


        <div class="rf-filter-secondary">

            @if (
                $this->isCommercialManager()
            )

                <select
                    wire:model.live="owner"
                    class="rf-select"
                >
                    <option value="">
                        Todos responsáveis
                    </option>

                    <option value="mine">
                        Meus leads
                    </option>

                    <option value="unassigned">
                        Sem responsável
                    </option>

                    @foreach (
                        $this->salesUsers
                        as $salesUser
                    )

                        <option
                            value="{{ $salesUser->id }}"
                        >
                            {{ $salesUser->name }}
                        </option>

                    @endforeach

                </select>

            @else

                <div></div>

            @endif


            <select
                wire:model.live="state"
                class="rf-select"
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


            @if (
                $this->isCommercialManager()
            )

                <button
                    type="button"
                    wire:click="applyHubSpotOnlyView"
                    class="
                        rf-filter-action
                        {{
                            $hubSpotOnly
                                ? 'is-active'
                                : ''
                        }}
                    "
                >
                    CRM sem CNPJ
                    ·
                    {{
                        number_format(
                            $this->hubSpotOnlyOpenCount,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </button>

            @endif


            <button
                type="button"
                wire:click="applyDailyView"
                class="
                    rf-filter-action
                    {{
                        $dailyView === 'today'
                            ? 'is-active'
                            : ''
                    }}
                "
            >
                Minha fila hoje
                ·
                {{ $this->dailyQueueCount }}
            </button>


            <button
                type="button"
                wire:click="clearFilters"
                class="rf-filter-action"
            >
                Limpar filtros
            </button>

        </div>

    </section>


    @include(
        'partials.hubspot-unmatched-leads'
    )


    @if (
        ! $hubSpotOnly
        && (
            $this->leads->total() > 0
            || $this->hubSpotOnlyLeads->total() === 0
        )
    )

    <section class="rf-panel rf-list-panel">

        <div class="rf-list-toolbar">

            <div>

                <strong>
                    {{
                        number_format(
                            $this->leads->total(),
                            0,
                            ',',
                            '.'
                        )
                    }}
                    leads encontrados
                </strong>

                <span>
                    Ordenados pela necessidade
                    de atenção comercial e score.
                </span>

            </div>


            <div class="rf-list-legend">

                <span>
                    Score 50+ = bom potencial
                </span>

                <span>
                    •
                </span>

                <span>
                    Alta = 75 a 100
                </span>

            </div>

        </div>


        <div class="rf-list-scroll">

            <div class="rf-list-head">

                <div>
                    Empresa
                </div>

                <div>
                    Score / prioridade
                </div>

                <div>
                    Situação comercial
                </div>

                <div>
                    CRM / HubSpot
                </div>

                <div>
                    Acompanhamento
                </div>

                <div>
                    Responsável
                </div>

                <div>
                    Próxima ação / acesso
                </div>

            </div>


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

                    $hubSpotSyncedAt =
                        $hubSpotLead
                            ?->status_synced_at;

                    $hubSpotSyncMinutes =
                        $hubSpotSyncedAt
                            ? max(
                                0,
                                (int) floor(
                                    $hubSpotSyncedAt
                                        ->diffInSeconds(
                                            now()
                                        )
                                    / 60
                                )
                            )
                            : null;

                    $hubSpotSyncLabel =
                        match (true) {
                            $hubSpotSyncedAt === null =>
                                'HubSpot ainda não verificado',

                            $hubSpotSyncMinutes <= 1 =>
                                'HubSpot verificado agora',

                            $hubSpotSyncMinutes < 60 =>
                                'HubSpot verificado há '
                                .$hubSpotSyncMinutes
                                .' min',

                            $hubSpotSyncMinutes < 1440 =>
                                'HubSpot verificado há '
                                .max(
                                    1,
                                    (int) floor(
                                        $hubSpotSyncMinutes
                                        / 60
                                    )
                                )
                                .' h',

                            default =>
                                'HubSpot verificado em '
                                .$hubSpotSyncedAt
                                    ->format(
                                        'd/m H:i'
                                    ),
                        };

                    $hubSpotSyncClass =
                        match (true) {
                            $hubSpotSyncMinutes === null =>
                                'is-old',

                            $hubSpotSyncMinutes <= 10 =>
                                '',

                            $hubSpotSyncMinutes <= 30 =>
                                'is-aging',

                            default =>
                                'is-old',
                        };

                    $leadWorkState =
                        $lead->leadWorkState;

                    $assignedUser =
                        $leadWorkState
                            ?->assignedUser;

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

                    $displayScore =
                        max(
                            0,
                            min(
                                100,
                                $displayScore
                            )
                        );

                    $displayPriority =
                        is_string(
                            $snapshot['priority']
                                ?? null
                        )
                            ? $snapshot['priority']
                            : (
                                $score?->priority
                                ?? 'low'
                            );

                    if (
                        ! in_array(
                            $displayPriority,
                            [
                                'very_high',
                                'high',
                                'medium',
                                'low',
                            ],
                            true
                        )
                    ) {
                        $displayPriority =
                            'low';
                    }

                    $commercialStatus =
                        $hubSpotLead
                            ?->commercial_status;

                    if (
                        ! in_array(
                            $commercialStatus,
                            [
                                'new',
                                'known',
                                'client',
                                'reprospecting',
                            ],
                            true
                        )
                    ) {
                        $commercialStatus =
                            match (
                                $crmCheck?->status
                            ) {
                                'not_found' =>
                                    'new',

                                'client' =>
                                    'client',

                                'prospected' =>
                                    'reprospecting',

                                default =>
                                    'known',
                            };
                    }

                    $commercialLabel =
                        match (
                            $commercialStatus
                        ) {
                            'new' =>
                                'Novo',

                            'client' =>
                                'Cliente',

                            'reprospecting' =>
                                'Reprospecção',

                            default =>
                                'Conhecido',
                        };

                    $commercialClass =
                        match (
                            $commercialStatus
                        ) {
                            'new' =>
                                'rf-commercial-new',

                            'client' =>
                                'rf-commercial-client',

                            'reprospecting' =>
                                'rf-commercial-reprospecting',

                            default =>
                                'rf-commercial-known',
                        };

                    $currentWorkStatus =
                        $hubSpotLead
                            ?->work_status
                        ?? 'new';

                    $workClass =
                        match (
                            $currentWorkStatus
                        ) {
                            'contacting' =>
                                'rf-work-contacting',

                            'waiting' =>
                                'rf-work-waiting',

                            'future' =>
                                'rf-work-future',

                            'reprospecting' =>
                                'rf-work-reprospecting',

                            default =>
                                'rf-work-new',
                        };

                    $priorityClass =
                        match (
                            $displayPriority
                        ) {
                            'very_high' =>
                                'rf-priority-high',

                            'high' =>
                                'rf-priority-high',

                            'medium' =>
                                'rf-priority-medium',

                            default =>
                                'rf-priority-low',
                        };

                    $crmDeals =
                        $this->crmDeals(
                            $crmCheck
                        );

                    $visibleDeals =
                        array_slice(
                            $crmDeals,
                            0,
                            2
                        );

                    $hiddenDeals =
                        array_slice(
                            $crmDeals,
                            2
                        );

                    $hiddenDealCount =
                        count(
                            $hiddenDeals
                        );

                    $hiddenStagesTitle =
                        implode(
                            ' · ',
                            array_map(
                                static fn (
                                    array $deal
                                ): string => (string) (
                                    $deal[
                                        'stage'
                                    ]
                                    ?? ''
                                ),
                                $hiddenDeals
                            )
                        );

                    $dealCount =
                        count(
                            $crmDeals
                        );

                    $hubSpotUrl =
                        $this->hubSpotCompanyUrl(
                            $crmCheck
                        );

                    $hubSpotDealUrl =
                        $this->hubSpotDealUrl(
                            $hubSpotLead
                        );

                    $hubSpotActionUrl =
                        $hubSpotDealUrl
                        ?? $hubSpotUrl;

                    $reprospectingInfo =
                        $this->reprospectingInfo(
                            $hubSpotLead
                        );

                    $scoreReasons =
                        $this->scoreReasons(
                            $score
                        );
                @endphp


                <article
                    wire:key="lead-rf-{{ $lead->id }}"
                    class="
                        rf-lead-row
                        rf-row-{{ $displayPriority }}
                        {{
                            $currentWorkStatus === 'waiting'
                            && $hubSpotLead
                                ?->last_task_due_at
                                ?->isPast()
                                ? 'rf-row-overdue'
                                : ''
                        }}
                    "
                >

                    {{-- EMPRESA --}}
                    <div>

                        <div class="rf-company-name">
                            {{ $lead->corporate_name }}
                        </div>

                        <div class="rf-location">

                            @if ($matrix?->state)

                                <span>
                                    {{ $matrix->state }}
                                </span>

                            @endif

                            @if (
                                $matrix?->municipality_name
                            )

                                @if ($matrix?->state)
                                    <span>•</span>
                                @endif

                                <span>
                                    {{
                                        $matrix
                                            ->municipality_name
                                    }}
                                </span>

                            @endif

                        </div>


                        <div class="rf-export-state">

                            @if (
                                $this->exportLabel(
                                    $export
                                ) === 'Não pesquisada'
                            )

                                Exportação não pesquisada

                            @else

                                Exportação ·
                                {{
                                    $this->exportLabel(
                                        $export
                                    )
                                }}

                            @endif

                        </div>

                    </div>


                    {{-- SCORE --}}
                    <div
                        class="{{
                            $displayScore >= 50
                                ? ''
                                : 'rf-score-low'
                        }}"
                    >

                        <div class="rf-section-label">
                            Score
                        </div>

                        <div class="rf-score-top">

                            <span class="rf-score-number">
                                {{ $displayScore }}/100
                            </span>

                        </div>

                        <div class="rf-score-bar">

                            <div
                                class="rf-score-fill"
                                style="
                                    width:
                                    {{ $displayScore }}%;
                                "
                            ></div>

                        </div>

                        <div class="rf-score-info">

                            <span class="rf-score-quality">
                                {{
                                    $this
                                        ->scoreQualityLabel(
                                            $displayScore
                                        )
                                }}
                            </span>

                            <span
                                class="
                                    rf-priority
                                    {{ $priorityClass }}
                                "
                            >
                                {{
                                    $this
                                        ->priorityLabel(
                                            $displayPriority
                                        )
                                }}
                            </span>

                            <span class="rf-icp">
                                ICP
                                {{
                                    $icpScore?->grade
                                    ?? '—'
                                }}
                            </span>

                        </div>

                    </div>


                    {{-- SITUACAO COMERCIAL --}}
                    <div>

                        <div class="rf-section-label">
                            Situação comercial
                        </div>

                        <div class="rf-commercial-box">

                            <span
                                class="
                                    rf-chip
                                    {{ $commercialClass }}
                                "
                            >
                                <i class="rf-chip-dot"></i>

                                {{ $commercialLabel }}
                            </span>

                        </div>

                        <div class="rf-commercial-explain">

                            {{
                                match (
                                    $commercialStatus
                                ) {
                                    'new' =>
                                        'Nova na operação.',

                                    'client' =>
                                        'Já é cliente.',

                                    'reprospecting' =>
                                        'Disponível para nova abordagem.',

                                    default =>
                                        'Já conhecida no CRM.',
                                }
                            }}

                        </div>

                    </div>


                    {{-- CRM --}}
                    <div>

                        <div class="rf-section-label">
                            CRM / HubSpot
                        </div>


                        <div class="rf-crm-status">

                            {{
                                $this->crmLabel(
                                    $crmCheck?->status
                                )
                            }}

                            @if ($dealCount > 0)

                                ·
                                {{
                                    $dealCount
                                    .' '
                                    .(
                                        $dealCount === 1
                                            ? 'negócio'
                                            : 'negócios'
                                    )
                                }}

                            @endif

                        </div>

                        @if ($visibleDeals !== [])

                            <div class="rf-stages">

                                @foreach (
                                    $visibleDeals
                                    as $deal
                                )

                                    <div
                                        class="rf-deal"
                                        title="{{
                                            $deal['name']
                                        }}"
                                    >

                                        @if (
                                            $deal['url']
                                            ?? null
                                        )

                                            <a
                                                href="{{
                                                    $deal[
                                                        'url'
                                                    ]
                                                }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="rf-stage"
                                            >
                                                {{
                                                    $deal[
                                                        'stage'
                                                    ]
                                                }}
                                            </a>

                                        @else

                                            <span
                                                class="rf-stage"
                                            >
                                                {{
                                                    $deal[
                                                        'stage'
                                                    ]
                                                }}
                                            </span>

                                        @endif


                                    </div>

                                @endforeach

                                @if (
                                    $hiddenDealCount > 0
                                )

                                    <div
                                        class="rf-more-stages"
                                        title="{{
                                            $hiddenStagesTitle
                                        }}"
                                    >
                                        +{{ $hiddenDealCount }}
                                        {{
                                            $hiddenDealCount === 1
                                                ? 'etapa'
                                                : 'etapas'
                                        }}
                                    </div>

                                @endif

                            </div>

                        @else

                            <div class="rf-empty">
                                Sem negócio associado
                            </div>

                        @endif

                    </div>


                    {{-- ACOMPANHAMENTO --}}
                    <div>

                        <div class="rf-section-label">
                            Acompanhamento
                        </div>

                        <div
                            style="
                                display:flex;
                                align-items:center;
                                flex-wrap:wrap;
                            "
                        >

                            <div
                                class="
                                    rf-work-badge
                                    {{ $workClass }}
                                "
                            >
                                {{
                                    $this
                                        ->workStatusLabel(
                                            $currentWorkStatus
                                        )
                                }}
                            </div>

                            @if (
                                $currentWorkStatus === 'waiting'
                                && $hubSpotLead
                                    ?->last_task_due_at
                                    ?->isPast()
                            )

                                <span class="rf-overdue-badge">
                                    Atrasado
                                </span>

                            @endif

                        </div>


                        <div class="rf-work-context">
                            {{
                                $this->workContext(
                                    $hubSpotLead
                                )
                            }}
                        </div>


                        @if ($hubSpotLead)

                            <div
                                class="
                                    rf-sync-state
                                    {{ $hubSpotSyncClass }}
                                "
                                title="{{
                                    $hubSpotSyncedAt
                                        ?->format(
                                            'd/m/Y H:i:s'
                                        )
                                    ?? 'Nunca sincronizado'
                                }}"
                            >
                                <span class="rf-sync-dot"></span>

                                <span>
                                    {{
                                        $hubSpotSyncLabel
                                    }}
                                </span>
                            </div>

                        @endif


                        @if ($reprospectingInfo)

                            <div
                                class="
                                    rf-work-context
                                    {{
                                        $reprospectingInfo[
                                            'eligible'
                                        ]
                                            ? 'text-emerald-300'
                                            : 'text-amber-300'
                                    }}
                                "
                                style="
                                    margin-top:6px;
                                    font-weight:700;
                                "
                            >
                                {{
                                    $reprospectingInfo[
                                        'message'
                                    ]
                                }}
                            </div>

                        @endif


                        @if (
                            $hubSpotLead
                                ?->last_task_due_at
                        )

                            <div class="rf-work-date">

                                Tarefa:
                                {{
                                    $hubSpotLead
                                        ->last_task_due_at
                                        ->format(
                                            'd/m/Y H:i'
                                        )
                                }}

                            </div>

                        @elseif (
                            $hubSpotLead
                                ?->last_activity_at
                        )

                            <div class="rf-work-date">

                                Última interação:
                                {{
                                    $hubSpotLead
                                        ->last_activity_at
                                        ->format(
                                            'd/m/Y'
                                        )
                                }}

                            </div>

                        @endif

                    </div>


                    {{-- RESPONSAVEL --}}
                    <div>

                        <div class="rf-section-label">
                            Responsável
                        </div>


                        @if (
                            $this->isCommercialManager()
                        )

                            <select
                                wire:key="
                                    owner-rf-{{ $lead->id }}-{{
                                        $assignedUser?->id
                                        ?? 'none'
                                    }}
                                "
                                wire:change="
                                    assignOwner(
                                        {{ $lead->id }},
                                        $event.target.value
                                    )
                                "
                                class="rf-owner-select"
                            >

                                <option
                                    value=""
                                    @selected(
                                        $assignedUser
                                        === null
                                    )
                                >
                                    Sem responsável
                                </option>

                                @foreach (
                                    $this->salesUsers
                                    as $salesUser
                                )

                                    <option
                                        value="{{
                                            $salesUser->id
                                        }}"
                                        @selected(
                                            $assignedUser?->id
                                            === $salesUser->id
                                        )
                                    >
                                        {{
                                            $salesUser->name
                                        }}
                                    </option>

                                @endforeach

                            </select>

                        @else

                            <div class="rf-owner-name">
                                {{
                                    $assignedUser?->name
                                    ?? 'Sem responsável'
                                }}
                            </div>

                        @endif


                        @if (
                            $this->isCommercialManager()
                            && $assignedUser === null
                        )

                            <button
                                type="button"
                                wire:click="
                                    claimLead(
                                        {{ $lead->id }}
                                    )
                                "
                                wire:loading.attr="disabled"
                                wire:target="
                                    claimLead(
                                        {{ $lead->id }}
                                    )
                                "
                                class="rf-claim"
                            >
                                Assumir lead
                            </button>

                        @elseif (
                            $assignedUser !== null
                        )

                            <div class="rf-owner-helper">
                                Carteira de
                                {{ $assignedUser->name }}
                            </div>

                        @endif

                    </div>


                    {{-- ACOES --}}
                    <div>

                        <div class="rf-section-label">
                            Próxima ação
                        </div>

                        @if ($hubSpotLead)

                            <div
                                class="
                                    rf-next-action
                                    {{
                                        $hubSpotLead
                                            ?->work_status
                                            === 'waiting'
                                        && $hubSpotLead
                                            ?->last_task_due_at
                                            ?->isPast()
                                                ? 'is-overdue'
                                                : ''
                                    }}
                                "
                            >
                                {{
                                    $currentWorkStatus === 'waiting'
                                    && $hubSpotLead
                                        ?->last_task_due_at
                                        ?->isPast()
                                            ? 'Retomar contato'
                                            : $this
                                                ->nextActionLabel(
                                                    $hubSpotLead
                                                )
                                }}
                            </div>

                        @endif

                        @if (
                            $hubSpotLead
                                ?->last_task_due_at
                        )

                            <div class="rf-mini-note">

                                {{
                                    $hubSpotLead
                                        ->last_task_due_at
                                        ->isPast()
                                            ? 'Vencida em '
                                            : 'Prevista para '
                                }}

                                {{
                                    $hubSpotLead
                                        ->last_task_due_at
                                        ->format(
                                            'd/m/Y H:i'
                                        )
                                }}

                            </div>

                        @endif


                        <div class="rf-actions">

                            <a
                                href="{{
                                    route(
                                        'companies.show',
                                        $lead
                                    )
                                }}"
                                wire:navigate
                                class="
                                    rf-btn
                                    rf-btn-primary
                                "
                            >
                                Abrir dossiê
                            </a>


                            @if ($hubSpotActionUrl)

                                <a
                                    href="{{
                                        $hubSpotActionUrl
                                    }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="
                                        rf-btn
                                        rf-btn-secondary
                                    "
                                >
                                    HubSpot ↗
                                </a>

                            @endif



@if (
                            $hubSpotLead
                            && $hubSpotLead
                                ->hubspot_company_id
                            && $hubSpotLead
                                ->hubspot_deal_id
                        )

                            <button
                                type="button"
                                wire:click="
                                    refreshHubSpotStatus(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                                wire:loading.attr="disabled"
                                wire:target="
                                    refreshHubSpotStatus(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                                class="rf-refresh-btn"
                            title="Atualizar dados do HubSpot"
                            aria-label="Atualizar dados do HubSpot"
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="
                                        refreshHubSpotStatus(
                                            {{ $hubSpotLead->id }}
                                        )
                                    "
                                    aria-hidden="true"
                                >
                                    ↻
                                </span>

                                <span
                                    wire:loading
                                    wire:target="
                                        refreshHubSpotStatus(
                                            {{ $hubSpotLead->id }}
                                        )
                                    "
                                    aria-hidden="true"
                                >
                                    …
                                </span>

                                <span class="sr-only">
                                    Atualizar HubSpot
                                </span>
                            </button>

                        @endif

</div>


                        {{-- rf-individual-refresh-feedback-marker --}}

                        @php
                            $individualRefresh =
                                $leadRefreshFeedback[
                                    $lead->id
                                ]
                                ?? null;
                        @endphp

                        @if ($individualRefresh)

                            <div
                                class="
                                    rf-row-sync-result
                                    {{
                                        $individualRefresh[
                                            'status'
                                        ] === 'error'
                                            ? 'is-error'
                                            : ''
                                    }}
                                "
                            >

                                <strong>
                                    {{
                                        $individualRefresh[
                                            'message'
                                        ]
                                    }}
                                </strong>

                                <span>
                                    Atualizado às
                                    {{
                                        $individualRefresh[
                                            'updated_at'
                                        ]
                                    }}
                                </span>

                                @foreach (
                                    $individualRefresh[
                                        'changes'
                                    ]
                                    as $change
                                )

                                    <span>

                                        {{
                                            $change[
                                                'label'
                                            ]
                                        }}:

                                        {{
                                            $change[
                                                'before'
                                            ]
                                        }}

                                        →

                                        {{
                                            $change[
                                                'after'
                                            ]
                                        }}

                                    </span>

                                @endforeach

                            </div>

                        @endif


                        @if (
                            $reprospectingInfo
                            && $reprospectingInfo[
                                'eligible'
                            ]
                            && $hubSpotLead
                        )

                            <button
                                type="button"
                                wire:click="
                                    resumeLead(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                                wire:confirm="
                                    Retomar este lead no HubSpot?
                                "
                                wire:loading.attr="disabled"
                                wire:target="
                                    resumeLead(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                                class="
                                    rf-btn
                                    rf-btn-reprospect
                                "
                                style="margin-top:6px"
                            >
                                Retomar lead
                            </button>

                        @endif





                        @if (
                            $scoreReasons !== []
                        )

                            <div
                                class="rf-mini-note"
                                title="{{
                                    collect(
                                        $scoreReasons
                                    )
                                        ->map(
                                            fn ($reason) =>
                                                $reason[
                                                    'label'
                                                ]
                                                .' +'
                                                .$reason[
                                                    'points'
                                                ]
                                        )
                                        ->implode(
                                            ' · '
                                        )
                                }}"
                            >
                                @foreach (
                                    array_slice(
                                        $scoreReasons,
                                        0,
                                        2
                                    )
                                    as $reason
                                )

                                    <span>
                                        {{
                                            $reason[
                                                'label'
                                            ]
                                        }}
                                        +{{
                                            $reason[
                                                'points'
                                            ]
                                        }}
                                    </span>

                                    @if (! $loop->last)
                                        ·
                                    @endif

                                @endforeach
                            </div>

                        @endif

                    </div>

                </article>


            @empty

                <div class="rf-empty-state">

                    <strong>
                        Nenhum lead encontrado
                    </strong>

                    <span>
                        Ajuste os filtros para
                        ampliar a busca.
                    </span>

                </div>

            @endforelse

        </div>


        @if (
            $this->leads->hasPages()
        )

            <div class="rf-pagination">

                <div class="rf-pagination-info">

                    Exibindo

                    <strong>
                        {{
                            $this->leads
                                ->firstItem()
                        }}
                    </strong>

                    a

                    <strong>
                        {{
                            $this->leads
                                ->lastItem()
                        }}
                    </strong>

                    de

                    <strong>
                        {{
                            number_format(
                                $this->leads
                                    ->total(),
                                0,
                                ',',
                                '.'
                            )
                        }}
                    </strong>

                    leads

                </div>


                <div class="rf-pagination-buttons">

                    <button
                        type="button"
                        wire:click="previousPage"
                        @disabled(
                            $this->leads
                                ->onFirstPage()
                        )
                        class="rf-page-btn"
                    >
                        ‹
                    </button>


                    @php
                        $currentPage =
                            $this->leads
                                ->currentPage();

                        $lastPage =
                            $this->leads
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
                            wire:click="
                                gotoPage(
                                    {{ $page }}
                                )
                            "
                            class="
                                rf-page-btn
                                {{
                                    $page
                                    === $currentPage
                                        ? 'is-active'
                                        : ''
                                }}
                            "
                        >
                            {{ $page }}
                        </button>

                    @endfor


                    <button
                        type="button"
                        wire:click="nextPage"
                        @disabled(
                            ! $this->leads
                                ->hasMorePages()
                        )
                        class="rf-page-btn"
                    >
                        ›
                    </button>

                </div>

            </div>

        @endif

    </section>

    @endif

</div>

</div>
