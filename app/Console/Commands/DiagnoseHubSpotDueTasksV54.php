<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use App\Services\LeadDueTaskQueueV54Service;
use Illuminate\Console\Command;

/** Diagnóstico somente de leitura: contagem local com a MESMA regra da tela Leads. */
final class DiagnoseHubSpotDueTasksV54 extends Command
{
    protected $signature = 'prospector:check-due-tasks-v54
        {user : E-mail ou ID local do usuário}
        {--sample=12 : Quantidade máxima de exemplos}';

    protected $description = 'Compara contagens de tarefas por horário no espelho local do HubSpot, sem gravar registros.';

    public function handle(LeadDueTaskQueueV54Service $service): int
    {
        $term = trim((string) $this->argument('user'));
        $user = ctype_digit($term)
            ? User::query()->find((int) $term)
            : User::query()->where('email', $term)->first();

        if (! $user instanceof User) {
            $this->error('Usuário não encontrado; informe um e-mail ou ID local válido.');
            return self::FAILURE;
        }

        $counts = $service->counts($user);
        $this->info('Fuso horário: '.config('app.timezone').' | agora '.now()->format('d/m/Y H:i:s'));
        $this->table(['Fila de tarefas HubSpot (espelho local)', 'Quantidade'], [
            ['Atrasadas (vencimento <= agora)', $counts['overdue']],
            ['Vencem hoje (agora < vencimento < amanhã)', $counts['today']],
            ['Total de tarefas vencidas ou que vencem hoje', $counts['total']],
        ]);

        $sample = min(30, max(0, (int) $this->option('sample')));
        if ($sample > 0) {
            $tasks = $service->forUser($user)
                ->orderBy('hubspot_tasks.due_at')
                ->orderBy('hubspot_tasks.id')
                ->limit($sample)
                ->get();
            $rows = [];
            foreach ($tasks as $task) {
                // EC_PROSPECTOR_V54_3: a coluna pode ser string (análise estática)
                // ou Carbon pelo cast Eloquent; converter antes de comparar datas.
                $rawDueAt = $task->getAttribute('due_at');
                $dueAt = null;
                if ($rawDueAt instanceof DateTimeInterface) {
                    $dueAt = CarbonImmutable::instance($rawDueAt);
                } elseif (is_string($rawDueAt) && trim($rawDueAt) !== '') {
                    try {
                        $dueAt = CarbonImmutable::parse($rawDueAt);
                    } catch (\Throwable) {
                        $dueAt = null;
                    }
                }
                $rows[] = [
                    (string) $task->hubspot_id,
                    mb_strimwidth((string) ($task->title ?: 'Sem título'), 0, 58, '...'),
                    $dueAt !== null ? $dueAt->format('d/m/Y H:i') : '—',
                    $dueAt === null ? 'Sem prazo' : ($dueAt->lte(now()) ? 'Atrasada' : 'Vence hoje'),
                ];
            }
            $this->table(['ID HubSpot', 'Título', 'Prazo', 'Situação'], $rows);
        }

        $this->warn('Leitura somente do banco local. Se o HubSpot online mostrar mais tarefas, verifique a sincronização do espelho.');
        return self::SUCCESS;
    }
}
