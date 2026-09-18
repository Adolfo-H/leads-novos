<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class LeadAutoDistributionService
{
    public function __construct(
        private readonly LeadOwnershipService $ownership,
    ) {}

    public function candidatesCount(): int
    {
        return $this
            ->candidateQuery()
            ->count();
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @return array{
     *     available_before: int,
     *     distributed: int,
     *     remaining: int,
     *     requested_limit: int,
     *     users: list<array{
     *         user_id: int,
     *         name: string,
     *         starting_load: int,
     *         assigned_now: int,
     *         ending_load: int
     *     }>
     * }
     */
    public function distribute(
        array $userIds,
        int $limit = 50,
    ): array {
        if (
            $limit < 1
            || $limit > 500
        ) {
            throw new DomainException(
                'A quantidade deve ficar entre 1 e 500 leads.'
            );
        }

        $normalizedUserIds =
            $this->normalizeUserIds(
                $userIds
            );

        if ($normalizedUserIds === []) {
            throw new DomainException(
                'Selecione pelo menos um responsável.'
            );
        }

        $users =
            User::query()
                ->whereIn(
                    'id',
                    $normalizedUserIds
                )
                ->whereNotNull(
                    'email_verified_at'
                )
                ->orderBy(
                    'id'
                )
                ->get([
                    'id',
                    'name',
                ]);

        if (
            $users->count()
            !== count(
                $normalizedUserIds
            )
        ) {
            throw new DomainException(
                'Um ou mais responsáveis selecionados não estão disponíveis.'
            );
        }

        /** @var array<int, User> $ownersById */
        $ownersById = [];

        foreach ($users as $user) {
            $ownersById[
                (int) $user->id
            ] = $user;
        }

        $availableBefore =
            $this
                ->candidateQuery()
                ->count();

        $candidateIds =
            $this
                ->candidateQuery()
                /*
                 * Leads de maior score entram
                 * primeiro na distribuição.
                 */
                ->orderByDesc(
                    'sdr.score'
                )
                ->orderBy(
                    'companies.corporate_name'
                )
                ->limit(
                    $limit
                )
                ->pluck(
                    'companies.id'
                )
                ->map(
                    static fn ($id): int => (int) $id
                )
                ->all();

        $loads =
            $this->activeLoads(
                $normalizedUserIds
            );

        $startingLoads =
            $loads;

        $assignedNow =
            array_fill_keys(
                $normalizedUserIds,
                0
            );

        DB::transaction(
            function () use (
                $candidateIds,
                $ownersById,
                &$loads,
                &$assignedNow,
            ): void {
                foreach (
                    $candidateIds as $companyId
                ) {
                    /*
                     * O lock da empresa impede
                     * duas distribuições concorrentes
                     * de assumirem o mesmo lead.
                     */
                    $company =
                        Company::query()
                            ->whereKey(
                                $companyId
                            )
                            ->lockForUpdate()
                            ->first();

                    if ($company === null) {
                        continue;
                    }

                    /*
                     * Revalida depois do lock.
                     * O lead pode ter sido assumido
                     * desde que a lista foi montada.
                     */
                    if (
                        ! $this
                            ->isDistributable(
                                $company
                            )
                    ) {
                        continue;
                    }

                    $recipientId =
                        $this
                            ->leastLoadedUserId(
                                $loads
                            );

                    $owner =
                        $ownersById[
                            $recipientId
                        ];

                    $this->ownership
                        ->assign(
                            company: $company,

                            owner: $owner,
                        );

                    $loads[
                        $recipientId
                    ]++;

                    $assignedNow[
                        $recipientId
                    ]++;
                }
            }
        );

        $distributed =
            array_sum(
                $assignedNow
            );

        $userSummary = [];

        foreach (
            $normalizedUserIds as $userId
        ) {
            $userSummary[] = [
                'user_id' => $userId,

                'name' => $ownersById[
                        $userId
                    ]->name,

                'starting_load' => $startingLoads[
                        $userId
                    ],

                'assigned_now' => $assignedNow[
                        $userId
                    ],

                'ending_load' => $loads[
                        $userId
                    ],
            ];
        }

        return [
            'available_before' => $availableBefore,

            'distributed' => $distributed,

            'remaining' => $this
                ->candidateQuery()
                ->count(),

            'requested_limit' => $limit,

            'users' => $userSummary,
        ];
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @return list<int>
     */
    private function normalizeUserIds(
        array $userIds
    ): array {
        $normalized = [];

        foreach ($userIds as $userId) {
            if (
                is_int(
                    $userId
                )
            ) {
                $value =
                    $userId;
            } elseif (
                ctype_digit(
                    $userId
                )
            ) {
                $value =
                    (int) $userId;
            } else {
                throw new DomainException(
                    'Responsável inválido na distribuição.'
                );
            }

            if ($value < 1) {
                throw new DomainException(
                    'Responsável inválido na distribuição.'
                );
            }

            $normalized[
                $value
            ] = $value;
        }

        $normalized =
            array_values(
                $normalized
            );

        sort(
            $normalized
        );

        return $normalized;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function activeLoads(
        array $userIds
    ): array {
        $loads =
            array_fill_keys(
                $userIds,
                0
            );

        $rows =
            DB::table(
                'company_lead_work_states as ownership'
            )
                ->join(
                    'companies',
                    'companies.id',
                    '=',
                    'ownership.company_id'
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
                ->whereIn(
                    'ownership.assigned_user_id',
                    $userIds
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
                ->where(
                    function ($query): void {
                        $query
                            ->whereNull(
                                'work.id'
                            )
                            ->orWhereIn(
                                'work.work_status',
                                [
                                    'new',
                                    'contacting',
                                    'waiting',
                                    'future',
                                ]
                            );
                    }
                )
                ->select(
                    'ownership.assigned_user_id'
                )
                ->selectRaw(
                    'COUNT(*) as total'
                )
                ->groupBy(
                    'ownership.assigned_user_id'
                )
                ->get();

        foreach ($rows as $row) {
            $userId =
                (int) $row
                    ->assigned_user_id;

            if (
                array_key_exists(
                    $userId,
                    $loads
                )
            ) {
                $loads[
                    $userId
                ] =
                    (int) $row
                        ->total;
            }
        }

        return $loads;
    }

    /**
     * @param  array<int, int>  $loads
     */
    private function leastLoadedUserId(
        array $loads
    ): int {
        if ($loads === []) {
            throw new DomainException(
                'Não há responsável disponível para distribuição.'
            );
        }

        $minimumLoad =
            min(
                $loads
            );

        foreach (
            $loads as $userId => $load
        ) {
            if (
                $load
                === $minimumLoad
            ) {
                return (int) $userId;
            }
        }

        throw new DomainException(
            'Não há responsável disponível para distribuição.'
        );
    }

    private function isDistributable(
        Company $company
    ): bool {
        $state =
            $company
                ->leadWorkState()
                ->first();

        if (
            $state !== null
            && $state->assigned_user_id
                !== null
        ) {
            return false;
        }

        $score =
            $company
                ->sdrScore()
                ->first();

        /*
         * Toda empresa candidata possui SDR score
         * por causa do JOIN da candidateQuery().
         * Ainda assim revalidamos por segurança.
         */
        if ($score === null) {
            return false;
        }

        $lead =
            $company
                ->hubSpotLead()
                ->first();

        $eligible =
            (bool) $score
                ->is_eligible;

        $hasHubSpotDeal =
            $lead !== null
            && trim(
                (string) $lead
                    ->hubspot_deal_id
            ) !== '';

        if (
            ! $eligible
            && ! $hasHubSpotDeal
        ) {
            return false;
        }

        /*
         * A automação só distribui:
         *
         * - empresa ainda sem Deal;
         * - ou Deal que continua como Novo.
         *
         * Lead já trabalhado nunca troca
         * de responsável automaticamente.
         */
        return $lead === null
            || $lead->work_status
                === 'new';
    }

    private function candidateQuery(): Builder
    {
        return DB::table(
            'companies'
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
            ->leftJoin(
                'company_lead_work_states as ownership',
                'ownership.company_id',
                '=',
                'companies.id'
            )
            ->whereNull(
                'ownership.assigned_user_id'
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
            ->where(
                function ($query): void {
                    $query
                        ->whereNull(
                            'work.id'
                        )
                        ->orWhere(
                            'work.work_status',
                            'new'
                        );
                }
            );
    }
}
