<?php

namespace App\Services;

use App\Models\HubSpotTask;
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

        $owners = array_values(array_unique(array_filter([
            trim((string) $user->hubspot_owner_id),
            trim((string) $user->name),
        ], static fn (string $owner): bool => $owner !== '')));
        if ($owners === []) {
            return ['total' => 0, 'overdue' => 0, 'today' => 0];
        }

        $now = now();
        $tomorrow = today()->addDay();

        // Sem consultas HubSpot durante o render do Dashboard: espelho local.
        $base = HubSpotTask::query()->dueInPeriod($tomorrow, null, $owners);

        $overdue = (clone $base)->where('hubspot_tasks.due_at', '<', $now)->count();
        $today = (clone $base)->where('hubspot_tasks.due_at', '>=', $now)->count();

        return ['total' => $overdue + $today, 'overdue' => $overdue, 'today' => $today];
    }
}
