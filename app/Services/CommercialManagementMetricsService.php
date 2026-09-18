<?php

namespace App\Services;

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
     *         future_total: int
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
     *         future_total: int
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
     *     future_total: int
     * }
     */
    public function summary(): array
    {
        $now =
            now();

        $tomorrow =
            today()
                ->addDay();

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
                ->first();

        return [
            'active_total' => (int) $row->active_total,

            'assigned_total' => (int) $row->assigned_total,

            'unassigned_total' => (int) $row->unassigned_total,

            'new_total' => (int) $row->new_total,

            'contacting_total' => (int) $row->contacting_total,

            'waiting_total' => (int) $row->waiting_total,

            'overdue_total' => (int) $row->overdue_total,

            'due_today_total' => (int) $row->due_today_total,

            'future_total' => (int) $row->future_total,
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
     *     future_total: int
     * }>
     */
    public function sellers(): array
    {
        $now =
            now();

        $tomorrow =
            today()
                ->addDay();

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
                    static fn (object $row): array => [
                        'user_id' => (int) $row->user_id,

                        'name' => (string) $row->name,

                        'email' => (string) $row->email,

                        'active_total' => (int) $row->active_total,

                        'new_total' => (int) $row->new_total,

                        'contacting_total' => (int) $row->contacting_total,

                        'waiting_total' => (int) $row->waiting_total,

                        'overdue_total' => (int) $row->overdue_total,

                        'due_today_total' => (int) $row->due_today_total,

                        'future_total' => (int) $row->future_total,
                    ]
                )
                ->values()
                ->all();

        return array_values(
            $sellers
        );
    }

    private function activeOperationalQuery(): Builder
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
            /*
             * Para a visão de gestão usamos
             * somente a carga comercial ativa.
             *
             * Convertidos, recusados e descartados
             * ficam fora da carga atual.
             */
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
