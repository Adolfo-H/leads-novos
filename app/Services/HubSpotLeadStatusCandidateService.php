<?php

namespace App\Services;

use App\Models\CompanyHubSpotLead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class HubSpotLeadStatusCandidateService
{
    /**
     * Seleciona quais leads devem ser
     * reconciliados primeiro com o HubSpot.
     *
     * Prioridade:
     *
     * 1. waiting + tarefa vencida;
     * 2. waiting;
     * 3. demais leads;
     *
     * Dentro do mesmo grupo, sincronizamos
     * primeiro quem está há mais tempo sem
     * uma consulta de status.
     *
     * @return Collection<int, CompanyHubSpotLead>
     */
    public function candidates(
        int $limit
    ): Collection {
        $limit =
            max(
                1,
                $limit
            );

        return $this
            ->query()
            ->limit(
                $limit
            )
            ->get();
    }

    /**
     * @return Builder<CompanyHubSpotLead>
     */
    public function query(): Builder
    {
        return CompanyHubSpotLead::query()
            ->whereNotNull(
                'hubspot_company_id'
            )
            ->whereNotNull(
                'hubspot_deal_id'
            )
            ->orderByRaw(
                '
                CASE
                    WHEN work_status = ?
                        AND last_task_due_at IS NOT NULL
                        AND last_task_due_at < ?
                        THEN 0

                    WHEN work_status = ?
                        THEN 1

                    ELSE 2
                END
                ',
                [
                    'waiting',
                    now(),
                    'waiting',
                ]
            )
            /*
             * NULL significa que nunca houve
             * sincronização de status.
             */
            ->orderByRaw(
                '
                CASE
                    WHEN status_synced_at IS NULL
                        THEN 0
                    ELSE 1
                END
                '
            )
            ->orderBy(
                'status_synced_at'
            )
            ->orderBy(
                'id'
            );
    }
}
