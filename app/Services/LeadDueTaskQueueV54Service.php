<?php

namespace App\Services;

use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fila de TAREFAS, não de empresas. Uma empresa pode ter várias tarefas;
 * uma tarefa pode existir no HubSpot antes da vinculação fiscal ao Prospector.
 * A filtragem de proprietário é aplicada diretamente na consulta, inclusive
 * quando alguém tenta acessar outra página ou selecionar IDs via Livewire.
 */
final class LeadDueTaskQueueV54Service
{
    /**
     * @return Builder<HubSpotTask>
     */
    public function forUser(User $user, string $period = 'all'): Builder
    {
        if (! in_array($period, ['all', 'overdue', 'today'], true)) {
            abort(422, 'Período de tarefas inválido.');
        }

        $owners = array_values(array_unique(array_filter([
            trim((string) $user->hubspot_owner_id),
            trim((string) $user->name),
        ], static fn (string $value): bool => $value !== '')));

        $query = HubSpotTask::query();

        // Sem identificador do responsável, NUNCA retornar tarefas de todos.
        if ($owners === []) {
            return $query->whereRaw('1 = 0');
        }

        $now = now();
        $tomorrow = today()->addDay();

        if ($period === 'overdue') {
            // No instante exato do vencimento a tarefa já é atrasada.
            return $query->dueInPeriod($tomorrow, null, $owners)
                ->where('hubspot_tasks.due_at', '<=', $now);
        }

        if ($period === 'today') {
            // Estritamente posterior a agora, sem recontar tarefas vencidas.
            return $query->dueInPeriod($tomorrow, null, $owners)
                ->where('hubspot_tasks.due_at', '>', $now);
        }

        return $query->dueInPeriod($tomorrow, null, $owners);
    }

    /**
     * Grupos mutuamente exclusivos: <=agora = atrasada; >agora e <amanhã = hoje.
     * 'total' conta tarefas, inclusive sem CNPJ, não COUNT(DISTINCT company_id).
     *
     * @return array{total:int,overdue:int,today:int}
     */
    public function counts(User $user): array
    {
        // Evitar desvio de segundos entre duas consultas usando um único corte.
        $now = now();
        $base = $this->forUser($user);
        $total = (clone $base)->count();
        $overdue = (clone $base)->where('hubspot_tasks.due_at', '<=', $now)->count();

        return [
            'total' => $total,
            'overdue' => $overdue,
            'today' => max(0, $total - $overdue),
        ];
    }
}
