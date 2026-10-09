<?php

namespace App\Console\Commands;

use App\Models\HubSpotTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Diagnóstico somente de leitura. O espelho local pode estar desatualizado;
 * NÃO afirma que os estados retornados ainda são os mesmos na API HubSpot.
 */
final class DiagnoseHubSpotDueTasks extends Command
{
    protected $signature = 'prospector:diagnose-hubspot-tasks {user : E-mail ou ID local do usuário} {--limit=8 : Amostras de tarefas}';

    protected $description = 'Confere tarefas locais vencidas/hoje, responsáveis, status e frescor dos dados sem consultar a API.';

    public function handle(): int
    {
        $term = trim((string) $this->argument('user'));
        $user = ctype_digit($term)
            ? User::query()->find((int) $term)
            : User::query()->where('email', $term)->first();

        if (! $user instanceof User) {
            $this->error('Usuário local não encontrado. Informe seu e-mail ou ID do Prospector.');

            return self::FAILURE;
        }

        $owners = array_values(array_unique(array_filter([
            trim((string) $user->name),
            trim((string) $user->hubspot_owner_id),
        ], static fn (string $value): bool => $value !== '')));

        if ($owners === []) {
            $this->warn('Usuário sem identificação de responsável para conciliar com HubSpot.');

            return self::SUCCESS;
        }

        $this->line('Leitura do espelho LOCAL hubspot_tasks (não é uma consulta ao HubSpot).');
        $this->line('Horário usado pelo Laravel: '.now()->format('d/m/Y H:i:s T'));
        $this->line('Último horário de hoje: antes de '.today()->addDay()->format('d/m/Y H:i:s'));

        $query = HubSpotTask::query()
            ->whereNotNull('due_at')
            ->where('due_at', '<', today()->addDay())
            ->where(function ($owner) use ($owners): void {
                foreach ($owners as $candidate) {
                    $owner->orWhereRaw('LOWER(TRIM(assigned_to)) = ?', [mb_strtolower($candidate)]);
                }
            });

        $total = 0;
        $open = 0;
        $closed = 0;
        $stale = 0;
        $overdue = 0;
        $today = 0;
        /** @var array<string, int> $statusCount */
        $statusCount = [];
        /** @var list<list<string>> $samples */
        $samples = [];
        $limit = max(0, min(30, (int) $this->option('limit')));

        foreach ($query->orderBy('due_at')->cursor() as $task) {
            // Ler a data bruta para não depender da inferência de casts
            // dinâmicos do Eloquent. Converter somente valores válidos.
            $rawDueAt = $task->getRawOriginal('due_at');
            if (! is_string($rawDueAt) || trim($rawDueAt) === '') {
                continue;
            }

            try {
                $dueAt = CarbonImmutable::parse($rawDueAt);
            } catch (\Throwable) {
                continue;
            }
            $total++;
            $normalized = mb_strtoupper(trim((string) ($task->status ?? '')));
            $statusCount[$normalized !== '' ? $normalized : '(sem status)'] =
                ($statusCount[$normalized !== '' ? $normalized : '(sem status)'] ?? 0) + 1;
            $isClosed = in_array($normalized, [
                'COMPLETED', 'DONE', 'CANCELLED', 'CANCELED', 'CLOSED',
                'CONCLUIDA', 'CONCLUÍDA', 'CONCLUIDO', 'CONCLUÍDO',
                'FINALIZADA', 'FINALIZADO', 'CANCELADO', 'CANCELADA',
            ], true);
            if ($task->is_open && $task->completed_at === null && ! $isClosed) {
                $open++;
                if ($dueAt->lt(now())) {
                    $overdue++;
                } else {
                    $today++;
                }
            } else {
                $closed++;
            }
            $updatedAt = $task->updated_at;
            if (! $updatedAt instanceof CarbonInterface
                || $updatedAt->lt(now()->subHours(48))) {
                $stale++;
            }
            if (count($samples) < $limit) {
                $samples[] = [
                    $dueAt->format('d/m/Y H:i'),
                    (string) ($task->status ?: 'não informado'),
                    $task->is_open ? 'sim' : 'não',
                    $updatedAt instanceof CarbonInterface
                        ? $updatedAt->format('d/m/Y H:i') : 'desconhecida',
                ];
            }
        }

        $this->table(['Tarefas no espelho', 'Quantidade'], [
            ['Até hoje (inclui tarefas fechadas)', $total],
            ['Abertas coerentes com status', $open],
            ['Abertas e já atrasadas', $overdue],
            ['Abertas e vencem hoje', $today],
            ['Concluídas/canceladas/inconsistentes', $closed],
            ['Sem atualização local nas últimas 48h', $stale],
        ]);
        if ($samples !== []) {
            $this->table(['Vencimento', 'Status local', 'is_open', 'Atualizado localmente'], $samples);
        }
        foreach ($statusCount as $status => $quantity) {
            $this->line("Status local {$status}: {$quantity}");
        }
        $this->warn('Esses valores são tarefas espelhadas, não necessariamente empresas únicas nem estado atual da API.');
        $this->warn('Se o HubSpot online mostrar zero e o espelho ainda mostrar tarefas, é preciso revisar a sincronização dessas tarefas.');

        return self::SUCCESS;
    }
}
