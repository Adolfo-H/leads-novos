<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CommercialManagementMetricsService
{
    /**
     * @return array{
     *     summary: array{
     *         active_total: int,
     *         assigned_total: int,
     *         unassigned_total: int,
     *         new_total: int,
     *         contacting_total: int,
     *         waiting_total: int,
     *         overdue_total: int,
     *         due_today_total: int,
     *         future_total: int,
     *         opportunities_total: int,
     *         unscheduled_total: int,
     *         stale_total: int,
     *         attention_total: int
     *     },
     *     sellers: list<array{
     *         user_id: int,
     *         name: string,
     *         email: string,
     *         active_total: int,
     *         new_total: int,
     *         contacting_total: int,
     *         waiting_total: int,
     *         overdue_total: int,
     *         due_today_total: int,
     *         future_total: int,
     *         opportunities_total: int,
     *         unscheduled_total: int,
     *         stale_total: int,
     *         attention_total: int
     *     }>
     * }
     */
    public function dashboard(): array
    {
        return [
            'summary' => $this->summary(),

            'sellers' => $this->sellers(),
        ];
    }

    /**
     * @return array{
     *     active_total: int,
     *     assigned_total: int,
     *     unassigned_total: int,
     *     new_total: int,
     *     contacting_total: int,
     *     waiting_total: int,
     *     overdue_total: int,
     *     due_today_total: int,
     *     future_total: int,
     *     opportunities_total: int,
     *     unscheduled_total: int,
     *     stale_total: int,
     *     attention_total: int
     * }
     */
    public function summary(): array
    {
        $now =
            now();

        $tomorrow =
            today()
                ->addDay();

        $staleBefore =
            now()
                ->subDays(
                    $this->staleAfterDays()
                );

        $row =
            $this
                ->activeOperationalQuery()
                ->selectRaw(
                    'COUNT(*) as active_total'
                )
                ->selectRaw(
                    '
                    SUM(
                        CASE
                            WHEN ownership.assigned_user_id IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ) as assigned_total
                    '
                )
                ->selectRaw(
                    '
                    SUM(
                        CASE
                            WHEN ownership.assigned_user_id IS NULL
                            THEN 1
                            ELSE 0
                        END
                    ) as unassigned_total
                    '
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.id IS NULL
                                OR work.work_status = 'new'
                            THEN 1
                            ELSE 0
                        END
                    ) as new_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'contacting'
                            THEN 1
                            ELSE 0
                        END
                    ) as contacting_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                            THEN 1
                            ELSE 0
                        END
                    ) as waiting_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                                AND work.last_task_due_at IS NOT NULL
                                AND work.last_task_due_at < ?
                            THEN 1
                            ELSE 0
                        END
                    ) as overdue_total
                    ",
                    [
                        $now,
                    ]
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                                AND work.last_task_due_at IS NOT NULL
                                AND work.last_task_due_at >= ?
                                AND work.last_task_due_at < ?
                            THEN 1
                            ELSE 0
                        END
                    ) as due_today_total
                    ",
                    [
                        $now,
                        $tomorrow,
                    ]
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'future'
                            THEN 1
                            ELSE 0
                        END
                    ) as future_total
                    "
                )
                ->selectRaw(
                    '
                    SUM(
                        CASE
                            WHEN work.hubspot_deal_id IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ) as opportunities_total
                    '
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                                AND work.last_task_due_at IS NULL
                            THEN 1
                            ELSE 0
                        END
                    ) as unscheduled_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'contacting'
                                AND work.last_activity_at IS NOT NULL
                                AND work.last_activity_at <= ?
                            THEN 1
                            ELSE 0
                        END
                    ) as stale_total
                    ",
                    [
                        $staleBefore,
                    ]
                )
                ->first();

        $overdue =
            (int) $row->overdue_total;

        $dueToday =
            (int) $row->due_today_total;

        $unscheduled =
            (int) $row->unscheduled_total;

        $stale =
            (int) $row->stale_total;

        return [
            'active_total' => (int) $row->active_total,

            'assigned_total' => (int) $row->assigned_total,

            'unassigned_total' => (int) $row->unassigned_total,

            'new_total' => (int) $row->new_total,

            'contacting_total' => (int) $row->contacting_total,

            'waiting_total' => (int) $row->waiting_total,

            'overdue_total' => $overdue,

            'due_today_total' => $dueToday,

            'future_total' => (int) $row->future_total,

            'opportunities_total' => (int) $row->opportunities_total,

            'unscheduled_total' => $unscheduled,

            'stale_total' => $stale,

            /*
             * Situações que exigem atenção do gestor.
             *
             * As categorias são mutuamente
             * exclusivas operacionalmente:
             *
             * - waiting atrasado;
             * - waiting hoje;
             * - waiting sem prazo;
             * - contacting parado.
             */
            'attention_total' => $overdue
                + $dueToday
                + $unscheduled
                + $stale,
        ];
    }

    /**
     * @return list<array{
     *     user_id: int,
     *     name: string,
     *     email: string,
     *     active_total: int,
     *     new_total: int,
     *     contacting_total: int,
     *     waiting_total: int,
     *     overdue_total: int,
     *     due_today_total: int,
     *     future_total: int,
     *     opportunities_total: int,
     *     unscheduled_total: int,
     *     stale_total: int,
     *     attention_total: int
     * }>
     */
    public function sellers(): array
    {
        $now =
            now();

        $tomorrow =
            today()
                ->addDay();

        $staleBefore =
            now()
                ->subDays(
                    $this->staleAfterDays()
                );

        $sellers =
            $this
                ->activeOperationalQuery()
                ->join(
                    'users as owners',
                    'owners.id',
                    '=',
                    'ownership.assigned_user_id'
                )
                ->whereNotNull(
                    'owners.email_verified_at'
                )
                ->select([
                    'owners.id as user_id',
                    'owners.name',
                    'owners.email',
                ])
                ->selectRaw(
                    'COUNT(*) as active_total'
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.id IS NULL
                                OR work.work_status = 'new'
                            THEN 1
                            ELSE 0
                        END
                    ) as new_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'contacting'
                            THEN 1
                            ELSE 0
                        END
                    ) as contacting_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                            THEN 1
                            ELSE 0
                        END
                    ) as waiting_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                                AND work.last_task_due_at IS NOT NULL
                                AND work.last_task_due_at < ?
                            THEN 1
                            ELSE 0
                        END
                    ) as overdue_total
                    ",
                    [
                        $now,
                    ]
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                                AND work.last_task_due_at IS NOT NULL
                                AND work.last_task_due_at >= ?
                                AND work.last_task_due_at < ?
                            THEN 1
                            ELSE 0
                        END
                    ) as due_today_total
                    ",
                    [
                        $now,
                        $tomorrow,
                    ]
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'future'
                            THEN 1
                            ELSE 0
                        END
                    ) as future_total
                    "
                )
                ->selectRaw(
                    '
                    SUM(
                        CASE
                            WHEN work.hubspot_deal_id IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ) as opportunities_total
                    '
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'waiting'
                                AND work.last_task_due_at IS NULL
                            THEN 1
                            ELSE 0
                        END
                    ) as unscheduled_total
                    "
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN work.work_status = 'contacting'
                                AND work.last_activity_at IS NOT NULL
                                AND work.last_activity_at <= ?
                            THEN 1
                            ELSE 0
                        END
                    ) as stale_total
                    ",
                    [
                        $staleBefore,
                    ]
                )
                ->groupBy(
                    'owners.id',
                    'owners.name',
                    'owners.email'
                )
                ->orderByDesc(
                    'overdue_total'
                )
                ->orderByDesc(
                    'due_today_total'
                )
                ->orderByDesc(
                    'active_total'
                )
                ->orderBy(
                    'owners.name'
                )
                ->get()
                ->map(
                    static function (
                        object $row
                    ): array {
                        $overdue =
                            (int) $row
                                ->overdue_total;

                        $dueToday =
                            (int) $row
                                ->due_today_total;

                        $unscheduled =
                            (int) $row
                                ->unscheduled_total;

                        $stale =
                            (int) $row
                                ->stale_total;

                        return [
                            'user_id' => (int) $row->user_id,

                            'name' => (string) $row->name,

                            'email' => (string) $row->email,

                            'active_total' => (int) $row
                                ->active_total,

                            'new_total' => (int) $row
                                ->new_total,

                            'contacting_total' => (int) $row
                                ->contacting_total,

                            'waiting_total' => (int) $row
                                ->waiting_total,

                            'overdue_total' => $overdue,

                            'due_today_total' => $dueToday,

                            'future_total' => (int) $row
                                ->future_total,

                            'opportunities_total' => (int) $row
                                ->opportunities_total,

                            'unscheduled_total' => $unscheduled,

                            'stale_total' => $stale,

                            'attention_total' => $overdue
                                + $dueToday
                                + $unscheduled
                                + $stale,
                        ];
                    }
                )
                ->sort(
                    static function (
                        array $left,
                        array $right,
                    ): int {
                        $attention =
                            $right[
                                'attention_total'
                            ]
                            <=>
                            $left[
                                'attention_total'
                            ];

                        if ($attention !== 0) {
                            return $attention;
                        }

                        $load =
                            $right[
                                'active_total'
                            ]
                            <=>
                            $left[
                                'active_total'
                            ];

                        if ($load !== 0) {
                            return $load;
                        }

                        return strcasecmp(
                            (string) $left[
                                'name'
                            ],
                            (string) $right[
                                'name'
                            ]
                        );
                    }
                )
                ->values()
                ->all();

        return array_values(
            $sellers
        );
    }

    /**
     * Empresas que exigem atenção gerencial imediata.
     *
     * Ordem:
     *
     * 1. follow-up atrasado;
     * 2. tarefa para hoje;
     * 3. aguardando sem prazo;
     * 4. contato parado.
     *
     * @return list<array{
     *     company_id: int,
     *     company_name: string,
     *     owner_id: int|null,
     *     owner_name: string,
     *     score: int,
     *     reason: string,
     *     reason_key: string,
     *     detail: string,
     *     moment: string|null,
     *     filters: array<string, string>
     * }>
     */
    public function attentionQueue(
        int $limit = 20,
    ): array {
        $limit =
            min(
                100,
                max(
                    1,
                    $limit
                )
            );

        $now =
            now()
                ->toImmutable();

        $tomorrow =
            today()
                ->addDay()
                ->toImmutable();

        $staleBefore =
            now()
                ->subDays(
                    $this->staleAfterDays()
                )
                ->toImmutable();

        $rows =
            $this
                ->activeOperationalQuery()
                ->leftJoin(
                    'users as attention_owner',
                    'attention_owner.id',
                    '=',
                    'ownership.assigned_user_id'
                )
                ->where(
                    function ($query) use (
                        $now,
                        $tomorrow,
                        $staleBefore,
                    ): void {
                        /*
                         * Atrasado.
                         */
                        $query
                            ->where(
                                function ($itemQuery) use (
                                    $now
                                ): void {
                                    $itemQuery
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
                                            $now
                                        );
                                }
                            )

                            /*
                             * Para hoje.
                             */
                            ->orWhere(
                                function ($itemQuery) use (
                                    $now,
                                    $tomorrow,
                                ): void {
                                    $itemQuery
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
                                            $now
                                        )
                                        ->where(
                                            'work.last_task_due_at',
                                            '<',
                                            $tomorrow
                                        );
                                }
                            )

                            /*
                             * Aguardando sem prazo.
                             */
                            ->orWhere(
                                function ($itemQuery): void {
                                    $itemQuery
                                        ->where(
                                            'work.work_status',
                                            'waiting'
                                        )
                                        ->whereNull(
                                            'work.last_task_due_at'
                                        );
                                }
                            )

                            /*
                             * Em contato, porém parado.
                             */
                            ->orWhere(
                                function ($itemQuery) use (
                                    $staleBefore
                                ): void {
                                    $itemQuery
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
                                            $staleBefore
                                        );
                                }
                            );
                    }
                )
                ->select([
                    'companies.id as company_id',
                    'companies.corporate_name as company_name',
                    'ownership.assigned_user_id as owner_id',
                    'attention_owner.name as owner_name',
                    'sdr.score',
                    'work.work_status',
                    'work.last_task_due_at',
                    'work.last_activity_at',
                ])
                ->get();

        $items = [];

        foreach ($rows as $row) {

            $status =
                trim(
                    (string) (
                        $row->work_status
                        ?? ''
                    )
                );

            $dueAt =
                $this->parseAttentionDate(
                    $row->last_task_due_at
                    ?? null
                );

            $activityAt =
                $this->parseAttentionDate(
                    $row->last_activity_at
                    ?? null
                );

            $reasonKey = null;
            $reason = null;
            $detail = null;
            $moment = null;
            $rank = 99;
            $sortTimestamp = PHP_INT_MAX;

            if (
                $status === 'waiting'
                && $dueAt !== null
                && $dueAt->lessThan(
                    $now
                )
            ) {
                $reasonKey =
                    'overdue';

                $reason =
                    'Follow-up atrasado';

                $detail =
                    'Venceu em '
                    .$dueAt->format(
                        'd/m/Y H:i'
                    );

                $moment =
                    $dueAt
                        ->toIso8601String();

                $rank = 0;

                $sortTimestamp =
                    $dueAt
                        ->getTimestamp();

            } elseif (
                $status === 'waiting'
                && $dueAt !== null
                && $dueAt->greaterThanOrEqualTo(
                    $now
                )
                && $dueAt->lessThan(
                    $tomorrow
                )
            ) {
                $reasonKey =
                    'today';

                $reason =
                    'Retorno para hoje';

                $detail =
                    'Previsto para '
                    .$dueAt->format(
                        'H:i'
                    );

                $moment =
                    $dueAt
                        ->toIso8601String();

                $rank = 1;

                $sortTimestamp =
                    $dueAt
                        ->getTimestamp();

            } elseif (
                $status === 'waiting'
                && $dueAt === null
            ) {
                $reasonKey =
                    'unscheduled';

                $reason =
                    'Aguardando sem prazo';

                $detail =
                    'Não existe data para a próxima ação.';

                $rank = 2;

                /*
                 * Dentro de "sem prazo",
                 * maior score aparece primeiro.
                 */
                $sortTimestamp =
                    -1
                    * (int) (
                        $row->score
                        ?? 0
                    );

            } elseif (
                $status === 'contacting'
                && $activityAt !== null
                && $activityAt
                    ->lessThanOrEqualTo(
                        $staleBefore
                    )
            ) {
                $reasonKey =
                    'stale';

                $reason =
                    'Contato parado';

                $detail =
                    'Última interação em '
                    .$activityAt->format(
                        'd/m/Y'
                    );

                $moment =
                    $activityAt
                        ->toIso8601String();

                $rank = 3;

                /*
                 * Mais antigo primeiro.
                 */
                $sortTimestamp =
                    $activityAt
                        ->getTimestamp();
            }

            if ($reasonKey === null) {
                continue;
            }

            $ownerId =
                is_numeric(
                    $row->owner_id
                    ?? null
                )
                    ? (int) $row->owner_id
                    : null;

            $filters =
                match ($reasonKey) {
                    'overdue' => [
                        'workStatus' => 'waiting',

                        'followUp' => 'overdue',
                    ],

                    'today' => [
                        'workStatus' => 'waiting',

                        'followUp' => 'today',
                    ],

                    'unscheduled' => [
                        'workStatus' => 'waiting',

                        'followUp' => 'unscheduled',
                    ],

                    'stale' => [
                        'workStatus' => 'contacting',
                    ],
                };

            if ($ownerId !== null) {
                $filters[
                    'owner'
                ] =
                    (string) $ownerId;
            }

            $items[] = [
                'company_id' => (int) $row->company_id,

                'company_name' => (string) $row->company_name,

                'owner_id' => $ownerId,

                'owner_name' => trim(
                    (string) (
                        $row->owner_name
                        ?? ''
                    )
                ) !== ''
                        ? trim(
                            (string) $row->owner_name
                        )
                        : 'Sem responsável',

                'score' => (int) (
                    $row->score
                    ?? 0
                ),

                'reason' => $reason,

                'reason_key' => $reasonKey,

                'detail' => $detail,

                'moment' => $moment,

                'filters' => $filters,

                '_rank' => $rank,

                '_sort_timestamp' => $sortTimestamp,
            ];
        }

        usort(
            $items,
            static function (
                array $left,
                array $right,
            ): int {
                $rank =
                    $left[
                        '_rank'
                    ]
                    <=>
                    $right[
                        '_rank'
                    ];

                if ($rank !== 0) {
                    return $rank;
                }

                $date =
                    $left[
                        '_sort_timestamp'
                    ]
                    <=>
                    $right[
                        '_sort_timestamp'
                    ];

                if ($date !== 0) {
                    return $date;
                }

                return strcasecmp(
                    (string) $left[
                        'company_name'
                    ],
                    (string) $right[
                        'company_name'
                    ]
                );
            }
        );

        $items =
            array_slice(
                $items,
                0,
                $limit
            );

        foreach ($items as &$item) {
            unset(
                $item[
                    '_rank'
                ],
                $item[
                    '_sort_timestamp'
                ],
            );
        }

        unset(
            $item
        );

        /** @var list<array{
         *     company_id: int,
         *     company_name: string,
         *     owner_id: int|null,
         *     owner_name: string,
         *     score: int,
         *     reason: string,
         *     reason_key: string,
         *     detail: string,
         *     moment: string|null,
         *     filters: array<string, string>
         * }> $items
         */
        return $items;
    }

    private function parseAttentionDate(
        mixed $value
    ): ?CarbonImmutable {
        if (
            ! is_string(
                $value
            )
            && ! $value instanceof \DateTimeInterface
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function staleAfterDays(): int
    {
        return max(
            1,
            (int) config(
                'prospector.sdr.stale_after_days',
                7
            )
        );
    }

    private function activeOperationalQuery(): Builder
    {
        return DB::table(
            'companies'
        )
            ->leftJoin(
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
            );
    }
}
