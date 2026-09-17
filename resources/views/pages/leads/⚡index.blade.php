<?php

use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyExportIntelligence;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanySdrScore;
use App\Models\Establishment;
use App\Services\HubSpotLeadReprospectingActionService;
use App\Services\HubSpotLeadReprospectingService;
use App\Services\HubSpotLeadStatusSyncService;
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

    public string $dailyView = '';

    public bool $staleOnly = false;

    public bool $reprospectingReadyOnly = false;

    public string $commercialActionMessage = '';

    public string $commercialActionError = '';

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
            'workStatus',
            'followUp',
            'dailyView',
            'staleOnly',
            'reprospectingReadyOnly',
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
                fn ($query) => $query->whereHas(
                    'crmCheck',
                    fn ($crmQuery) => $crmQuery->where(
                        'status',
                        $this->crm
                    )
                )
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
            'contacting' => 'Em contato',

            'waiting' => 'Aguardando retorno',

            'future' => 'Oportunidade futura',

            'refused' => 'Recusou',

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

            'refused' => 'text-rose-300',

            'discarded' => 'text-red-300',

            default => 'text-emerald-300',
        };
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
    public function dailyQueueCount(): int
    {
        return $this
            ->operationalLeadQuery()
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
    ): void {
        $lead =
            CompanyHubSpotLead::query()
                ->findOrFail(
                    $leadId
                );

        $service->sync(
            $lead
        );
    }

    public function crmLabel(
        ?string $status
    ): string {
        return match ($status) {
            'client' => 'Cliente',

            'opportunity' => 'Oportunidade',

            'prospected' => 'Reprospecção',

            'known' => 'Conhecido',

            'not_found' => 'Novo',

            default => 'Não verificado',
        };
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
            'future' => 'Oportunidade reservada para momento futuro',

            'refused' => 'Negócio marcado como recusado no HubSpot',

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

            'refused' => 'text-rose-300',

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

            'refused' => 'Ver recusa',

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

            'refused' => 'text-rose-300 hover:text-rose-200',

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
                    {{ $this->operationalCount }}
                </span>

            </div>

            <p class="ec-page-description">
                Empresas elegíveis ordenadas pela
                prioridade calculada para o SDR.
            </p>

        </div>

    </div>


    @if ($commercialActionMessage !== '')

        <div
            class="
                mb-4 rounded-xl
                border border-emerald-300/20
                bg-emerald-300/[0.06]
                px-4 py-3
                text-sm text-emerald-300
            "
        >
            {{ $commercialActionMessage }}
        </div>

    @endif


    @if ($commercialActionError !== '')

        <div
            class="
                mb-4 rounded-xl
                border border-red-300/20
                bg-red-300/[0.06]
                px-4 py-3
                text-sm text-red-300
            "
        >
            {{ $commercialActionError }}
        </div>

    @endif


    {{-- RESUMO --}}
    <div
        class="
            grid gap-3
            md:grid-cols-3
        "
    >

        <div class="ec-intelligence-card">

            <div class="ec-intelligence-label">
                Leads na operação
            </div>

            <div class="ec-score-value">
                {{ $this->operationalCount }}
            </div>

            <div class="ec-intelligence-caption">
                Fila comercial ativa
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


    {{-- MINHA FILA HOJE --}}
    <button
        type="button"
        wire:click="applyDailyView"
        class="
            group flex w-full
            items-center justify-between
            gap-5 rounded-2xl
            border px-5 py-4
            text-left transition
            {{
                $dailyView === 'today'
                    ? 'border-cyan-300/30 bg-cyan-300/[0.08]'
                    : 'border-white/[0.06] bg-white/[0.025] hover:border-cyan-300/20 hover:bg-cyan-300/[0.03]'
            }}
        "
    >
        <div
            class="
                flex min-w-0
                items-center gap-4
            "
        >
            <div
                class="
                    flex h-10 w-10
                    shrink-0 items-center
                    justify-center
                    rounded-xl
                    border border-cyan-300/20
                    bg-cyan-300/[0.07]
                    text-lg text-cyan-300
                "
            >
                ◎
            </div>

            <div class="min-w-0">
                <div
                    class="
                        text-xs font-bold
                        uppercase tracking-wider
                        text-cyan-300
                    "
                >
                    Minha fila hoje
                </div>

                <div
                    class="
                        mt-1 text-sm
                        font-semibold
                        text-[#eef1ff]
                    "
                >
                    Atrasados, tarefas de hoje
                    e contatos em andamento
                </div>

                <div
                    class="
                        mt-1 text-[11px]
                        text-[#7882a4]
                    "
                >
                    Foque apenas no que exige
                    atenção comercial agora.
                </div>
            </div>
        </div>

        <div
            class="
                flex shrink-0
                items-center gap-3
            "
        >
            <span
                class="
                    text-2xl font-bold
                    text-cyan-300
                "
            >
                {{ $this->dailyQueueCount }}
            </span>

            <span
                class="
                    text-xs font-semibold
                    text-[#8791b2]
                    transition
                    group-hover:text-white
                "
            >
                {{
                    $dailyView === 'today'
                        ? 'Mostrar todos'
                        : 'Abrir fila'
                }}
                →
            </span>
        </div>
    </button>


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


    {{-- FOLLOW-UP --}}
    <div
        class="
            mt-1 flex flex-wrap
            items-center gap-2
        "
    >
        <span
            class="
                mr-1 text-[10px]
                font-semibold uppercase
                tracking-wider
                text-[#697394]
            "
        >
            Follow-up
        </span>

        <button
            type="button"
            wire:click="applyFollowUpView('overdue')"
            class="
                rounded-lg border px-3 py-2
                text-xs font-semibold transition
                {{
                    $followUp === 'overdue'
                        ? 'border-red-400/30 bg-red-400/10 text-red-300'
                        : 'border-white/[0.07] bg-white/[0.025] text-[#9ba5c8] hover:text-white'
                }}
            "
        >
            Atrasados · {{ $this->overdueCount }}
        </button>

        <button
            type="button"
            wire:click="applyFollowUpView('today')"
            class="
                rounded-lg border px-3 py-2
                text-xs font-semibold transition
                {{
                    $followUp === 'today'
                        ? 'border-amber-300/30 bg-amber-300/10 text-amber-300'
                        : 'border-white/[0.07] bg-white/[0.025] text-[#9ba5c8] hover:text-white'
                }}
            "
        >
            Hoje · {{ $this->dueTodayCount }}
        </button>

        <button
            type="button"
            wire:click="applyFollowUpView('upcoming')"
            class="
                rounded-lg border px-3 py-2
                text-xs font-semibold transition
                {{
                    $followUp === 'upcoming'
                        ? 'border-cyan-300/30 bg-cyan-300/10 text-cyan-300'
                        : 'border-white/[0.07] bg-white/[0.025] text-[#9ba5c8] hover:text-white'
                }}
            "
        >
            Próximos · {{ $this->upcomingCount }}
        </button>

        <button
            type="button"
            wire:click="applyFollowUpView('unscheduled')"
            class="
                rounded-lg border px-3 py-2
                text-xs font-semibold transition
                {{
                    $followUp === 'unscheduled'
                        ? 'border-white/20 bg-white/[0.07] text-white'
                        : 'border-white/[0.07] bg-white/[0.025] text-[#9ba5c8] hover:text-white'
                }}
            "
        >
            Sem prazo · {{ $this->unscheduledCount }}
        </button>

        @if ($followUp !== '')
            <button
                type="button"
                wire:click="applyFollowUpView('')"
                class="
                    px-2 py-2 text-xs
                    font-semibold text-cyan-300
                    hover:text-cyan-200
                "
            >
                Limpar prazo
            </button>
        @endif
    </div>


    {{-- DESTINO COMERCIAL / ATENÇÃO --}}
    <div
        class="
            mt-3 flex flex-wrap
            items-center gap-2
        "
    >
        <span
            class="
                mr-1 text-[10px]
                font-semibold uppercase
                tracking-wider
                text-[#697394]
            "
        >
            Destino
        </span>

        <button
            type="button"
            wire:click="applyQuickView('future')"
            class="
                rounded-lg border px-3 py-2
                text-xs font-semibold transition
                {{
                    $workStatus === 'future'
                        ? 'border-violet-300/30 bg-violet-300/10 text-violet-300'
                        : 'border-white/[0.07] bg-white/[0.025] text-[#9ba5c8] hover:text-white'
                }}
            "
        >
            Oportunidade futura · {{ $this->futureCount }}
        </button>

        <button
            type="button"
            wire:click="applyQuickView('refused')"
            class="
                rounded-lg border px-3 py-2
                text-xs font-semibold transition
                {{
                    $workStatus === 'refused'
                        ? 'border-rose-300/30 bg-rose-300/10 text-rose-300'
                        : 'border-white/[0.07] bg-white/[0.025] text-[#9ba5c8] hover:text-white'
                }}
            "
        >
            Recusou · {{ $this->refusedCount }}
        </button>

        <span
            class="
                ml-3 mr-1 text-[10px]
                font-semibold uppercase
                tracking-wider
                text-[#697394]
            "
        >
            Atenção
        </span>

        <button
            type="button"
            wire:click="applyStaleView"
            class="
                rounded-lg border px-3 py-2
                text-xs font-semibold transition
                {{
                    $staleOnly
                        ? 'border-orange-300/30 bg-orange-300/10 text-orange-300'
                        : 'border-white/[0.07] bg-white/[0.025] text-[#9ba5c8] hover:text-white'
                }}
            "
        >
            Parados {{ $this->staleAfterDays() }}+ dias
            · {{ $this->staleCount }}
        </button>
    </div>


    {{-- REPROSPECÇÃO --}}
    <button
        type="button"
        wire:click="applyReprospectingReadyView"
        class="
            mt-3 flex w-full
            items-center justify-between
            gap-4 rounded-xl
            border px-4 py-3
            text-left transition
            {{
                $reprospectingReadyOnly
                    ? 'border-emerald-300/30 bg-emerald-300/[0.08]'
                    : 'border-white/[0.06] bg-white/[0.025] hover:border-emerald-300/20'
            }}
        "
    >
        <div>
            <div
                class="
                    text-[10px]
                    font-semibold uppercase
                    tracking-wider
                    text-emerald-300
                "
            >
                Reprospecção
            </div>

            <div
                class="
                    mt-1 text-sm
                    font-semibold
                    text-[#eef1ff]
                "
            >
                Prontos para retomar
            </div>

            <div
                class="
                    mt-0.5 text-[11px]
                    text-[#7782a3]
                "
            >
                Oportunidades futuras vencidas
                e recusas com carência encerrada.
            </div>
        </div>

        <div
            class="
                text-2xl font-bold
                text-emerald-300
            "
        >
            {{ $this->reprospectingReadyCount }}
        </div>
    </button>


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

                <option value="future">
                    Oportunidade futura
                </option>

                <option value="refused">
                    Recusou
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
            || $followUp !== ''
            || $dailyView !== ''
            || $staleOnly
            || $reprospectingReadyOnly
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


    @if ($dailyView === 'today')

        <div
            class="
                mt-5 flex flex-wrap
                items-center justify-between
                gap-3 rounded-xl
                border border-cyan-300/15
                bg-cyan-300/[0.04]
                px-4 py-3
            "
        >
            <div>
                <div
                    class="
                        text-xs font-semibold
                        text-cyan-300
                    "
                >
                    Minha fila hoje
                </div>

                <div
                    class="
                        mt-0.5 text-[11px]
                        text-[#7f89aa]
                    "
                >
                    Leads novos e follow-ups
                    futuros estão ocultos.
                </div>
            </div>

            <button
                type="button"
                wire:click="applyDailyView"
                class="
                    text-xs font-semibold
                    text-[#a6aec9]
                    hover:text-white
                "
            >
                Voltar para todos
            </button>
        </div>

    @endif


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

                $reprospectingInfo =
                    $this->reprospectingInfo(
                        $hubSpotLead
                    );

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

                        $hubSpotDealUrl =
                            $this->hubSpotDealUrl(
                                $hubSpotLead
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

                    <div
                        class="
                            mt-2 max-w-[190px]
                            text-[10px]
                            leading-relaxed
                            {{
                                $this->workContextClass(
                                    $hubSpotLead
                                )
                            }}
                        "
                    >
                        {{
                            $this->workContext(
                                $hubSpotLead
                            )
                        }}
                    </div>


                    @if ($reprospectingInfo)

                        <div
                            class="
                                mt-2 text-[10px]
                                {{
                                    $reprospectingInfo[
                                        'eligible'
                                    ]
                                        ? 'font-semibold text-emerald-300'
                                        : 'text-[#7f89aa]'
                                }}
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
                        $reprospectingInfo
                        && $reprospectingInfo['eligible']
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
                                Retomar este lead e mover
                                o negócio novamente para
                                Prospects no HubSpot?
                            "
                            wire:loading.attr="disabled"
                            wire:target="
                                resumeLead(
                                    {{ $hubSpotLead->id }}
                                )
                            "
                            class="
                                mt-2 block
                                rounded-lg
                                border border-emerald-300/20
                                bg-emerald-300/[0.07]
                                px-3 py-2
                                text-[11px]
                                font-semibold
                                text-emerald-300
                                transition
                                hover:bg-emerald-300/[0.12]
                                disabled:opacity-50
                            "
                        >
                            <span
                                wire:loading.remove
                                wire:target="
                                    resumeLead(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                            >
                                Retomar lead ↻
                            </span>

                            <span
                                wire:loading
                                wire:target="
                                    resumeLead(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                            >
                                Retomando...
                            </span>
                        </button>

                    @endif


                    @if ($hubSpotDealUrl)

                        <a
                            href="{{ $hubSpotDealUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="
                                mt-2 inline-flex
                                items-center gap-1
                                text-[11px]
                                font-semibold
                                {{
                                    $this->nextActionClass(
                                        $hubSpotLead
                                    )
                                }}
                            "
                        >
                            {{
                                $this->nextActionLabel(
                                    $hubSpotLead
                                )
                            }}
                            ↗
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
                            class="
                                mt-2 block
                                text-[10px]
                                font-semibold
                                text-[#6f789a]
                                transition
                                hover:text-cyan-300
                                disabled:opacity-50
                            "
                        >
                            <span
                                wire:loading.remove
                                wire:target="
                                    refreshHubSpotStatus(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                            >
                                Atualizar HubSpot ↻
                            </span>

                            <span
                                wire:loading
                                wire:target="
                                    refreshHubSpotStatus(
                                        {{ $hubSpotLead->id }}
                                    )
                                "
                            >
                                Atualizando...
                            </span>
                        </button>

                    @endif


                    @if (
                        $hubSpotLead?->work_status
                            === 'waiting'
                        && $hubSpotLead
                            ?->last_task_due_at
                    )

                        @php
                            $taskIsOverdue =
                                $hubSpotLead
                                    ->last_task_due_at
                                    ->isPast();
                        @endphp

                        <div
                            class="
                                mt-2 text-[10px]
                                font-semibold
                                {{
                                    $taskIsOverdue
                                        ? 'text-red-300'
                                        : 'text-amber-300'
                                }}
                            "
                        >
                            {{
                                $taskIsOverdue
                                    ? 'Atrasado'
                                    : 'Próxima ação'
                            }}
                            ·
                            {{
                                $hubSpotLead
                                    ->last_task_due_at
                                    ->format(
                                        'd/m/Y H:i'
                                    )
                            }}
                        </div>

                    @endif


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
