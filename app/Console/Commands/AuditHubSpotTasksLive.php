<?php

namespace App\Console\Commands;

use App\Models\HubSpotTask;
use App\Models\User;
use App\Support\HubSpotHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Confronta somente as tarefas que geram pendências no espelho local com
 * os registros atuais da API. Nunca modifica dados locais nem o HubSpot.
 */
final class AuditHubSpotTasksLive extends Command
{
    protected $signature = 'prospector:audit-hubspot-tasks-live
        {user : E-mail ou ID do usuário no Prospector}
        {--limit=100 : Máximo de tarefas consultadas na API (1 a 200)}
        {--samples=15 : Quantidade de exemplos mostrados (0 a 30)}';

    protected $description = 'Compara as tarefas vencidas no banco com a API do HubSpot, em modo exclusivamente de leitura.';

    /**
     * @var list<string>
     */
    private const CLOSED_STATUSES = [
        'COMPLETED', 'DONE', 'CANCELLED', 'CANCELED', 'CLOSED',
        'CONCLUIDA', 'CONCLUÍDA', 'CONCLUIDO', 'CONCLUÍDO',
        'FINALIZADA', 'FINALIZADO', 'CANCELADO', 'CANCELADA',
    ];

    public function handle(): int
    {
        $term = trim((string) $this->argument('user'));
        $user = ctype_digit($term)
            ? User::query()->find((int) $term)
            : User::query()->where('email', $term)->first();

        if (! $user instanceof User) {
            $this->error('Usuário não encontrado no Prospector.');

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $maxSamples = max(0, min(30, (int) $this->option('samples')));

        $ownerName = trim((string) $user->name);
        $ownerId = trim((string) $user->hubspot_owner_id);
        $candidates = array_values(array_unique(array_filter(
            [$ownerName, $ownerId],
            static fn (string $value): bool => $value !== ''
        )));

        if ($candidates === []) {
            $this->error('Usuário sem nome ou Owner ID configurado.');

            return self::FAILURE;
        }

        $api = rtrim((string) config('services.hubspot.base_url'), '/');
        if ($api === '' || ! str_starts_with($api, 'https://')) {
            $this->error('HUBSPOT_BASE_URL deve apontar para uma URL HTTPS válida.');

            return self::FAILURE;
        }

        try {
            // Reutiliza o cliente oficial do próprio projeto (Bearer e retry seguro em GET).
            $client = HubSpotHttpClient::make();
        } catch (Throwable) {
            $this->error('Cliente HubSpot indisponível. Confira HUBSPOT_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        $until = today()->addDay();

        /** @var Builder<HubSpotTask> $query */
        $query = HubSpotTask::query()
            ->where('is_open', true)
            ->whereNull('completed_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', $until)
            ->whereRaw(
                "UPPER(TRIM(COALESCE(status, ''))) NOT IN ("
                .implode(',', array_fill(0, count(self::CLOSED_STATUSES), '?')).')',
                self::CLOSED_STATUSES
            )
            ->where(function (Builder $ownerQuery) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    $ownerQuery->orWhereRaw(
                        'LOWER(TRIM(assigned_to)) = ?',
                        [mb_strtolower($candidate)]
                    );
                }
            });

        $total = (clone $query)->count();
        $tasks = $query->orderBy('due_at')->limit($limit)->get();
        $stats = [
            'Concluída no HubSpot' => 0,
            'Transferida a outro responsável' => 0,
            'Reprogramada para outra data' => 0,
            'Aberta e com vencimento até hoje' => 0,
            'Informações remotas insuficientes' => 0,
            'Não encontrada na API (404)' => 0,
            'Erro de API / permissão' => 0,
        ];
        /** @var list<list<string>> $samples */
        $samples = [];
        $apiError = false;

        $this->line('AUDITORIA ONLINE (somente leitura): nenhuma tarefa será modificada.');
        $this->line('Horário local: '.now()->format('d/m/Y H:i:s T'));
        $this->line('Tarefas candidatas no espelho local: '.$total);
        $this->line('Consultas previstas na API: '.min($limit, $total));
        if ($ownerId === '') {
            $this->warn('Sem HubSpot Owner ID cadastrado: a atribuição atual da API não pode ser validada com segurança.');
        }

        foreach ($tasks as $task) {
            $remoteStatus = '—';
            $remoteDueLabel = '—';
            $hubspotId = trim((string) $task->hubspot_id);
            $localDue = self::parseDate($task->getRawOriginal('due_at'));
            $timezone = (string) config('app.timezone', 'UTC');
            $localDueLabel = $localDue?->setTimezone($timezone)->format('d/m/Y H:i') ?? '—';
            $type = 'Informações remotas insuficientes';

            if ($hubspotId === '') {
                $type = 'Informações remotas insuficientes';
            } else {
                try {
                    $response = $client->get(
                        $api.'/crm/v3/objects/tasks/'.rawurlencode($hubspotId),
                        ['properties' => 'hs_task_status,hs_timestamp,hubspot_owner_id']
                    );

                    if ($response->status() === 404) {
                        $type = 'Não encontrada na API (404)';
                    } elseif (! $response->successful()) {
                        $type = 'Erro de API / permissão';
                        $remoteStatus = 'HTTP '.$response->status();
                        $apiError = true;
                    } else {
                        $json = $response->json();
                        if (is_array($json)) {
                            $properties = $json['properties'] ?? null;
                            if (is_array($properties) && ($json['archived'] ?? false) !== true) {
                                $remoteStatus = mb_strtoupper(trim((string) ($properties['hs_task_status'] ?? '')));
                                $remoteOwner = trim((string) ($properties['hubspot_owner_id'] ?? ''));
                                $remoteDue = self::parseDate($properties['hs_timestamp'] ?? null);
                                $remoteDueLabel = $remoteDue?->setTimezone($timezone)->format('d/m/Y H:i') ?? '—';
                                if (in_array($remoteStatus, self::CLOSED_STATUSES, true)) {
                                    $type = 'Concluída no HubSpot';
                                } elseif ($remoteStatus === '') {
                                    $type = 'Informações remotas insuficientes';
                                } elseif ($ownerId !== '' && $remoteOwner !== '' && $remoteOwner !== $ownerId) {
                                    $type = 'Transferida a outro responsável';
                                } elseif ($remoteDue === null || $ownerId === '' || $remoteOwner === '') {
                                    $type = 'Informações remotas insuficientes';
                                } elseif ($remoteDue->greaterThanOrEqualTo(CarbonImmutable::instance($until))) {
                                    $type = 'Reprogramada para outra data';
                                } else {
                                    $type = 'Aberta e com vencimento até hoje';
                                }
                            }
                        }
                    }
                } catch (ConnectionException) {
                    $type = 'Erro de API / permissão';
                    $remoteStatus = 'sem conexão';
                    $apiError = true;
                } catch (Throwable) {
                    $type = 'Erro de API / permissão';
                    $remoteStatus = 'erro de consulta';
                    $apiError = true;
                }
            }

            $stats[$type]++;
            if (count($samples) < $maxSamples) {
                $samples[] = [
                    $hubspotId !== '' ? $hubspotId : '(sem ID)',
                    $localDueLabel,
                    $remoteStatus !== '' ? $remoteStatus : '(não informado)',
                    $remoteDueLabel,
                    $type,
                ];
            }

            // Sem token/permissão, outras chamadas não esclarecem nada.
            if ($remoteStatus === 'HTTP 401' || $remoteStatus === 'HTTP 403') {
                break;
            }
        }

        $rows = [];
        foreach ($stats as $label => $count) {
            $rows[] = [$label, $count];
        }
        $this->table(['Situação na comparação', 'Quantidade'], $rows);
        if ($samples !== []) {
            $this->table(['ID HubSpot', 'Venc. local', 'Status online', 'Venc. online', 'Diagnóstico'], $samples);
        }
        if ($total > $limit) {
            $this->warn('Há mais candidatas do que o limite. Reexecute com --limit=200 se necessário.');
        }
        $this->warn('Os números do card são empresas distintas; este relatório classifica tarefas e pode ter total diferente.');
        $this->warn('Este comando não altera o espelho local. Não use DELETE/UPDATE manual para zerar os indicadores.');

        return $apiError ? self::FAILURE : self::SUCCESS;
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return is_numeric($value)
                ? CarbonImmutable::createFromTimestampMs($value)
                : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
