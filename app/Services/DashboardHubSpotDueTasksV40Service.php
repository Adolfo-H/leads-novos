<?php

namespace App\Services;

use App\Models\User;

/**
 * Uma tarefa = um registro. Diferente do estado comercial amplo "waiting".
 * Reutiliza HubSpotTask::dueInPeriod() — o MESMO predicado usado pelos filtros
 * de Leads, inclusive owner_id, tarefas concluídas e vencimentos por dia.
 */
final class DashboardHubSpotDueTasksV40Service
{
    /** @return array{total:int,overdue:int,today:int} */
    public function forUser(int $userId): array
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            return ['total' => 0, 'overdue' => 0, 'today' => 0];
        }

        // V55: fonte única das tarefas e do corte por segundo (mesma regra da V54).
        return app(LeadDueTaskQueueV54Service::class)->counts($user);
    }
}
