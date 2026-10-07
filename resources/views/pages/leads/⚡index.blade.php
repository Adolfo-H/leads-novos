<?php

use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyExportIntelligence;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadWorkState;
use App\Models\CompanySdrScore;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\HubSpotCompanyLinkService;
use App\Services\HubSpotLeadReprospectingActionService;
use App\Services\HubSpotLeadReprospectingService;
use App\Services\HubSpotRealtimeHealthService;
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
     * @var array<int, int>
     */
    public array $hubSpotRefreshRequestedAt = [];

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function hubSpotRealtimeHealth(): array
    {
        return app(
            HubSpotRealtimeHealthService::class
        )->snapshot();
    }

    public function isCommercialManager(): bool
    {
        $user =
            auth()->user();

        return $user instanceof User
            && $user
                ->isCommercialManager();
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

                        /*
                         * A etapa agora é filtrada
                         * diretamente pelo mirror
                         * dos negócios do HubSpot.
                         *
                         * Assim contagem e listagem
                         * usam a mesma fonte.
                         */
                        $query->whereHas(
                            'hubSpotCompanies.commercialDeals',
                            fn ($dealQuery) => $dealQuery
                                ->where(
                                    'hubspot_deals.stage_label',
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
    /**
     * Etapas REAIS dos negócios atualmente
     * preservados no mirror do HubSpot.
     *
     * Não usamos mais CompanyCrmCheck.metadata
     * como fonte para esta visão porque ele é
     * uma projeção derivada.
     *
     * O mirror hubspot_deals é atualizado pelos
     * webhooks e representa melhor o estado atual.
     *
     * @return list<array{
     *     label: string,
     *     count: int
     * }>
     */
    #[Computed]
    public function crmStageOptions(): array
    {
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
            return [];
        }

        $rows =
            HubSpotDeal::query()
                ->whereNotNull(
                    'stage_label'
                )
                ->where(
                    'stage_label',
                    '!=',
                    ''
                )
                ->whereHas(
                    'commercialCompanies',
                    function (
                        $query
                    ) use (
                        $companyIds
                    ): void {
                        $query->whereIn(
                            'hubspot_companies.company_id',
                            $companyIds
                        );

                        $query->where(
                            function (
                                $linkQuery
                            ): void {
                                $linkQuery
                                    ->whereNull(
                                        'hubspot_companies.match_source'
                                    )
                                    ->orWhereNotIn(
                                        'hubspot_companies.match_source',
                                        HubSpotCompany::UNSAFE_FISCAL_MATCH_SOURCES
                                    );
                            }
                        );
                    }
                )
                ->select(
                    'stage_label'
                )
                ->selectRaw(
                    'COUNT(DISTINCT hubspot_deals.id) as total'
                )
                ->groupBy(
                    'stage_label'
                )
                ->orderBy(
                    'stage_label'
                )
                ->get();

        $options = [];

        foreach ($rows as $row) {
            $label =
                trim(
                    (string)
                    $row->stage_label
                );

            if ($label === '') {
                continue;
            }

            $options[] = [
                'label' => $label,

                'count' => (int)
                    $row->getAttribute(
                        'total'
                    ),
            ];
        }

        return $options;
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

    public function verifyHubSpotNow(
        int $companyId
    ): void {
        $company =
            Company::query()
                ->with(
                    'hubSpotLead'
                )
                ->findOrFail(
                    $companyId
                );

        $lead =
            $company->hubSpotLead;

        abort_unless(
            $lead instanceof CompanyHubSpotLead,
            404
        );

        $this->assertCanOperateLead(
            $lead
        );

        if (
            trim(
                (string)
                $lead->hubspot_company_id
            ) === ''
        ) {
            $this->commercialActionError =
                'Esta empresa ainda não possui '
                .'vínculo válido com o HubSpot.';

            return;
        }

        $this->commercialActionError =
            '';

        $this->hubSpotRefreshRequestedAt[
            $companyId
        ] =
            now()->getTimestamp();

        RefreshCompanyFromHubSpot::dispatch(
            $companyId
        );
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

    public function applyKpiView(
        string $view
    ): void {
        /*
         * O número exibido nos KPIs representa
         * a fila operacional completa.
         *
         * Ao clicar no card limpamos filtros
         * anteriores para que a lista corresponda
         * exatamente ao número apresentado.
         */
        $this->clearFilters();

        if ($view === 'high') {
            $this->priority = 'high';

            $this->resetPage();

            return;
        }

        if (
            in_array(
                $view,
                [
                    'new',
                    'contacting',
                    'waiting',
                    'future',
                ],
                true
            )
        ) {
            $this->workStatus =
                $view;
        }

        $this->resetPage();
    }

    public function applyCrmStageView(
        int $index
    ): void {
        $options =
            $this->crmStageOptions;

        $stage =
            $options[
                $index
            ]['label']
            ?? null;

        if (
            ! is_string($stage)
            || trim($stage) === ''
        ) {
            return;
        }

        $stage =
            trim(
                $stage
            );

        /*
         * A contagem das etapas é global.
         * Limpamos filtros anteriores para que
         * clicar numa etapa mostre sua base real.
         */
        $this->clearFilters();

        $this->crm =
            'stage:'
            .$stage;

        $this->resetPage();
    }

    public function summaryPercent(
        int $count
    ): string {
        $total =
            $this->operationalCount;

        if ($total <= 0) {
            return '0%';
        }

        return number_format(
            (
                $count
                / $total
            )
            * 100,
            1,
            ',',
            '.'
        )
            .'%';
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
            return 'text-[var(--ec-text-muted)]';
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

            'contacting' => 'text-[var(--ec-text-soft)]',

            'future' => 'text-violet-300',

            'reprospecting' => 'text-rose-300',

            'refused' => 'text-rose-300',

            'converted' => 'text-emerald-300',

            'discarded' => 'text-[var(--ec-text-muted)]',

            default => 'text-[var(--ec-text-muted)]',
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

            'discarded' => 'text-[var(--ec-text-muted)] hover:text-[var(--ec-text)]',

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


<div class="rf-shell">

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


    </header>



    @php
        $hubSpotHealth =
            $this
                ->hubSpotRealtimeHealth;
    @endphp

    <section
        class="
            rf-hubspot-health
            is-{{ $hubSpotHealth['status'] }}
        "
        wire:poll.10s="$refresh"
    >

        <div class="rf-hubspot-health-content">

            <div class="rf-hubspot-health-main">

                <span class="rf-hubspot-health-dot"></span>

                <strong>
                    {{ $hubSpotHealth['label'] }}
                </strong>

                <span>
                    Último webhook
                    {{
                        $hubSpotHealth[
                            'last_event_label'
                        ]
                    }}
                </span>

                <span>·</span>

                <span>
                    {{
                        number_format(
                            $hubSpotHealth[
                                'pending'
                            ],
                            0,
                            ',',
                            '.'
                        )
                    }}
                    pendente(s)
                </span>

                @if (
                    $hubSpotHealth[
                        'failed_recently'
                    ] > 0
                )

                    <span>
                        ·
                        {{
                            $hubSpotHealth[
                                'failed_recently'
                            ]
                        }}
                        falha(s) na última hora
                    </span>

                @endif

            </div>


            <div class="rf-hubspot-health-checks">

                <span
                    class="
                        rf-hubspot-check
                        {{
                            $hubSpotHealth[
                                'webhook_worker_online'
                            ]
                                ? 'is-ok'
                                : 'is-bad'
                        }}
                    "
                >
                    <i></i>

                    Worker webhook

                    <strong>
                        {{
                            $hubSpotHealth[
                                'webhook_worker_label'
                            ]
                        }}
                    </strong>
                </span>


                <span
                    class="
                        rf-hubspot-check
                        {{
                            $hubSpotHealth[
                                'realtime_worker_online'
                            ]
                                ? 'is-ok'
                                : 'is-bad'
                        }}
                    "
                >
                    <i></i>

                    Worker realtime

                    <strong>
                        {{
                            $hubSpotHealth[
                                'realtime_worker_label'
                            ]
                        }}
                    </strong>
                </span>


                @if (
                    $hubSpotHealth[
                        'tunnel_checked'
                    ]
                )

                    <span
                        class="
                            rf-hubspot-check
                            {{
                                $hubSpotHealth[
                                    'tunnel_online'
                                ]
                                    ? 'is-ok'
                                    : 'is-bad'
                            }}
                        "
                    >
                        <i></i>

                        Webhook público

                        <strong>
                            {{
                                $hubSpotHealth[
                                    'tunnel_label'
                                ]
                            }}
                        </strong>
                    </span>

                @else

                    <span
                        class="
                            rf-hubspot-check
                            is-unknown
                        "
                    >
                        <i></i>

                        Webhook público

                        <strong>
                            {{
                                $hubSpotHealth[
                                    'tunnel_label'
                                ]
                            }}
                        </strong>
                    </span>

                @endif

            </div>

        </div>


        @if (
            in_array(
                $hubSpotHealth[
                    'status'
                ],
                [
                    'delayed',
                    'degraded',
                ],
                true
            )
        )

            <div class="rf-hubspot-health-warning">

                @if (
                    ! $hubSpotHealth[
                        'webhook_worker_online'
                    ]
                )
                    Fila de webhooks sem heartbeat.
                @elseif (
                    ! $hubSpotHealth[
                        'realtime_worker_online'
                    ]
                )
                    Fila realtime sem heartbeat.
                @elseif (
                    $hubSpotHealth[
                        'tunnel_checked'
                    ]
                    && ! $hubSpotHealth[
                        'tunnel_online'
                    ]
                )
                    URL pública do webhook não está respondendo corretamente.
                @elseif (
                    $hubSpotHealth[
                        'oldest_pending_label'
                    ]
                )
                    Evento mais antigo pendente
                    {{
                        $hubSpotHealth[
                            'oldest_pending_label'
                        ]
                    }}.
                @else
                    Existem falhas recentes na integração HubSpot.
                @endif

            </div>

        @endif


        @if (
            $hubSpotHealth[
                'bulk_active'
            ]
        )

            <div class="rf-hubspot-bulk">

                <div class="rf-hubspot-bulk-head">

                    <span>
                        Conferência geral
                    </span>

                    <strong>
                        {{
                            $hubSpotHealth[
                                'bulk_progress'
                            ]
                        }}%
                    </strong>

                    <span>
                        {{
                            $hubSpotHealth[
                                'bulk_label'
                            ]
                        }}
                    </span>

                </div>

                <div class="rf-hubspot-bulk-track">

                    <div
                        class="rf-hubspot-bulk-bar"
                        style="
                            width:
                            {{
                                $hubSpotHealth[
                                    'bulk_progress'
                                ]
                            }}%;
                        "
                    ></div>

                </div>

            </div>

        @endif

    </section>


    @if ($commercialActionMessage !== '')

        <div
            class="rf-alert rf-alert-ok"
            role="status"
            aria-live="polite"
        >
            {{ $commercialActionMessage }}
        </div>

    @endif


    @if ($commercialActionError !== '')

        <div
            class="rf-alert rf-alert-error"
            role="alert"
            aria-live="assertive"
        >
            {{ $commercialActionError }}
        </div>

    @endif


    @include(
        'partials.leads-action-center'
    )


            @include(
        'partials.leads-crm-stage-strip'
    )


        <section class="rf-panel rf-filters">

        <div class="rf-filters-head">

            <div>

                <strong>
                    Refinar fila
                </strong>

                <span>
                    Busque e combine critérios apenas quando precisar
                    aprofundar a carteira.
                </span>

            </div>

            <button
                type="button"
                wire:click="clearFilters"
                class="rf-filters-clear"
            >
                Limpar filtros
            </button>

        </div>

        <div
            wire:loading.delay.longer
            wire:target="
                search,
                priority,
                icp,
                crm,
                state,
                owner,
                workStatus,
                followUp
            "
            class="rf-filters-loading"
            role="status"
            aria-live="polite"
        >
            <span></span>

            Atualizando fila...
        </div>


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
                    Prioridade
                </div>

                <div>
                    Perfil comercial
                </div>

                <div>
                    Pipeline
                </div>

                <div>
                    Status / prazo
                </div>

                <div>
                    Responsável
                </div>

                <div>
                    Próxima ação
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

                    $hubSpotRefreshRequestedAt =
                        $this
                            ->hubSpotRefreshRequestedAt[
                                $lead->id
                            ]
                        ?? null;

                    $hubSpotRefreshPending =
                        is_numeric(
                            $hubSpotRefreshRequestedAt
                        )
                        && (
                            $hubSpotSyncedAt === null
                            || $hubSpotSyncedAt
                                ->getTimestamp()
                                < (int)
                                    $hubSpotRefreshRequestedAt
                        );

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


                    /*
                     * Hierarquia visual da fila.
                     *
                     * Não altera classificação ou
                     * filtros: apenas transforma os
                     * dados já existentes em um resumo
                     * operacional para leitura rápida.
                     */
                    $leadIsOverdue =
                        $currentWorkStatus
                            === 'waiting'
                        && $hubSpotLead
                            ?->last_task_due_at
                            ?->isPast();

                    $leadIsDueToday =
                        $currentWorkStatus
                            === 'waiting'
                        && $hubSpotLead
                            ?->last_task_due_at
                            !== null
                        && ! $leadIsOverdue
                        && $hubSpotLead
                            ->last_task_due_at
                            ->isToday();

                    $leadCanReprospect =
                        is_array(
                            $reprospectingInfo
                        )
                        && (
                            $reprospectingInfo[
                                'eligible'
                            ]
                            ?? false
                        ) === true;

                    $leadAttentionLabel =
                        match (true) {
                            $leadIsOverdue =>
                                'Atrasado',

                            $leadIsDueToday =>
                                'Ação hoje',

                            $leadCanReprospect =>
                                'Reprospecção pronta',

                            $currentWorkStatus
                                === 'contacting' =>
                                    'Em andamento',

                            $currentWorkStatus
                                === 'waiting' =>
                                    'Aguardando retorno',

                            $currentWorkStatus
                                === 'future' =>
                                    'Agendado',

                            $currentWorkStatus
                                === 'refused' =>
                                    'Recusou',

                            $currentWorkStatus
                                === 'converted' =>
                                    'Convertido',

                            $currentWorkStatus
                                === 'discarded' =>
                                    'Descartado',

                            default =>
                                'Novo lead',
                        };

                    $leadAttentionClass =
                        match (true) {
                            $leadIsOverdue =>
                                'is-danger',

                            $leadIsDueToday =>
                                'is-warning',

                            $leadCanReprospect =>
                                'is-reprospecting',

                            $currentWorkStatus
                                === 'contacting' =>
                                    'is-progress',

                            $currentWorkStatus
                                === 'converted' =>
                                    'is-success',

                            $currentWorkStatus
                                === 'discarded' =>
                                    'is-muted',

                            default =>
                                'is-primary',
                        };

                    $leadPrimaryAction =
                        $currentWorkStatus
                            === 'waiting'
                        && $leadIsOverdue
                            ? 'Retomar contato'
                            : $this
                                ->nextActionLabel(
                                    $hubSpotLead
                                );
                @endphp


                <article
                    wire:key="lead-rf-{{ $lead->id }}"
                    class="
                        rf-lead-row
                        rf-row-{{ $displayPriority }}
                        {{
                            $leadIsOverdue
                                ? 'rf-row-overdue'
                                : ''
                        }}

                        {{
                            $leadIsDueToday
                                ? 'rf-row-due-today'
                                : ''
                        }}

                        {{
                            $leadCanReprospect
                                ? 'rf-row-reprospecting-ready'
                                : ''
                        }}
                    "
                >

                    {{-- EMPRESA --}}
                    <div
                        class="rf-col-company">

                        <div class="rf-company-name">
                            {{ $lead->corporate_name }}
                        </div>


                        <div class="rf-lead-focus">

                            <span
                                class="
                                    rf-lead-attention
                                    {{ $leadAttentionClass }}
                                "
                            >
                                <i></i>

                                {{ $leadAttentionLabel }}
                            </span>

                            <span class="rf-lead-focus-action">
                                Próximo:
                                <strong>
                                    {{ $leadPrimaryAction }}
                                </strong>
                            </span>

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
                        class="rf-col-score {{
                            $displayScore >= 50
                                ? ''
                                : 'rf-score-low'
                        }}"
                    >

                        <div class="rf-section-label">
                            Prioridade
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
                    <div
                        class="rf-col-commercial">

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
                    <div
                        class="rf-col-crm">

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
                    <div
                        class="rf-col-followup">

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
                                @if (
                                    $hubSpotRefreshPending
                                )
                                    wire:poll.2s="$refresh"
                                @endif
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

                                <button
                                    type="button"
                                    wire:click="
                                        verifyHubSpotNow(
                                            {{ $lead->id }}
                                        )
                                    "
                                    wire:loading.attr="
                                        disabled
                                    "
                                    wire:target="
                                        verifyHubSpotNow(
                                            {{ $lead->id }}
                                        )
                                    "
                                    @disabled(
                                        $hubSpotRefreshPending
                                    )
                                    class="
                                        rf-sync-refresh
                                    "
                                    title="{{
                                        $hubSpotRefreshPending
                                            ? 'Verificando no HubSpot...'
                                            : 'Verificar agora no HubSpot'
                                    }}"
                                    aria-label="
                                        Verificar agora no HubSpot
                                    "
                                >
                                    @if (
                                        $hubSpotRefreshPending
                                    )
                                        <span
                                            class="
                                                animate-pulse
                                            "
                                        >
                                            …
                                        </span>
                                    @else
                                        ↻
                                    @endif
                                </button>
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
                    <div
                        class="rf-col-owner">

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
                    <div
                        class="rf-col-action">

                        <div class="rf-section-label">
                            Próxima ação
                        </div>

                        @if ($hubSpotLead)

                            <div
                                class="
                                    rf-next-action
                                    rf-next-action-main
                                    {{
                                        $leadIsOverdue
                                            ? 'is-overdue'
                                            : ''
                                    }}
                                    {{
                                        $leadIsDueToday
                                            ? 'is-today'
                                            : ''
                                    }}
                                "
                            >

                                <span>
                                    {{
                                        $leadIsOverdue
                                            ? 'Ação atrasada'
                                            : (
                                                $leadIsDueToday
                                                    ? 'Ação para hoje'
                                                    : 'Próximo passo'
                                            )
                                    }}
                                </span>

                                <strong>
                                    {{ $leadPrimaryAction }}
                                </strong>

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


</div>


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
