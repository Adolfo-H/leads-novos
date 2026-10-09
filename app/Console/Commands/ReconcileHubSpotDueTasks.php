<?php

namespace App\Console\Commands;

use App\Models\HubSpotTask;
use App\Models\User;
use App\Support\HubSpotHttpClient;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Corrige tarefas do espelho local APENAS quando a resposta atual da API
 * do HubSpot comprova status, responsável e prazo. Nenhuma chamada de escrita
 * é enviada ao HubSpot. Tarefas sem confirmação são preservadas.
 */
final class ReconcileHubSpotDueTasks extends Command
{
    protected $signature = 'prospector:reconcile-hubspot-due-tasks
        {user? : E-mail ou ID do usuário no Prospector; omitido no agendador}
        {--apply : Confirmar e aplicar alterações no espelho local}
        {--limit=200 : Máximo de tarefas por execução (1 a 200)}
        {--stale-minutes=20 : Idade mínima de atualização local; 0 força verificação}
        {--samples=12 : Exemplos no relatório (0 a 30)}';

    protected $description = 'Reconcilia com segurança os vencimentos das tarefas do HubSpot no espelho local.';

    /** @var list<string> */
    private const CLOSED = [
        'COMPLETED', 'DONE', 'CANCELLED', 'CANCELED', 'CLOSED',
        'CONCLUIDA', 'CONCLUÍDA', 'CONCLUIDO', 'CONCLUÍDO',
        'FINALIZADA', 'FINALIZADO', 'CANCELADO', 'CANCELADA',
    ];

    /** @var list<string> */
    private const OPEN = [
        'NOT_STARTED', 'IN_PROGRESS', 'WAITING', 'DEFERRED',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, min(200, (int) $this->option('limit')));
        $stale = max(0, min(10080, (int) $this->option('stale-minutes')));
        $maxSamples = max(0, min(30, (int) $this->option('samples')));
        $term = trim((string) ($this->argument('user') ?? ''));

        $user = null;
        if ($term !== '') {
            $user = ctype_digit($term)
                ? User::query()->find((int) $term)
                : User::query()->where('email', $term)->first();

            if (! $user instanceof User) {
                $this->error('Usuário não encontrado. Nenhum registro alterado.');

                return self::FAILURE;
            }
        }

        $api = rtrim((string) config('services.hubspot.base_url'), '/');
        if (! str_starts_with($api, 'https://')) {
            $this->error('URL HTTPS do HubSpot inválida. Nenhum registro alterado.');

            return self::FAILURE;
        }

        try {
            $client = HubSpotHttpClient::make();
        } catch (Throwable) {
            $this->error('Token HubSpot não configurado. Nenhum registro alterado.');

            return self::FAILURE;
        }

        /** @var Builder<HubSpotTask> $query */
        $query = HubSpotTask::query()
            ->where('is_open', true)
            ->whereNull('completed_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', today()->addDay())
            ->whereRaw(
                "UPPER(TRIM(COALESCE(status, ''))) NOT IN ("
                    .implode(',', array_fill(0, count(self::CLOSED), '?')).')',
                self::CLOSED
            );

        if ($stale > 0) {
            $query->where('updated_at', '<=', now()->subMinutes($stale));
        }

        if ($user instanceof User) {
            $candidates = array_values(array_unique(array_filter([
                trim((string) $user->name),
                trim((string) $user->hubspot_owner_id),
            ], static fn (string $value): bool => $value !== '')));

            if ($candidates === []) {
                $this->error('Usuário sem nome/Owner ID para identificar tarefas.');

                return self::FAILURE;
            }

            $query->where(function (Builder $ownerQuery) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    $ownerQuery->orWhereRaw(
                        'LOWER(TRIM(assigned_to)) = ?',
                        [mb_strtolower($candidate)]
                    );
                }
            });
        }

        // RECONCILIATION_CURSOR_V11
        // Cursor persistente por escopo: não deixa 50 tarefas antigas
        // (inclusive respostas 404/sem confirmação) ocultarem as demais.
        // A simulação pode ler a posição, mas nunca a modifica.
        $cursorKey = 'prospector:hubspot:due-tasks:cursor:v11:'
            .($user instanceof User ? (string) $user->id : 'all');

        $previousCursor = Cache::get($cursorKey);
        $cursorDue = '';
        $cursorId = 0;

        if (is_array($previousCursor)) {
            $dueValue = $previousCursor['due_at'] ?? null;
            $idValue = $previousCursor['id'] ?? null;

            if (is_string($dueValue) && $dueValue !== '' && is_numeric($idValue)) {
                $cursorDue = $dueValue;
                $cursorId = max(0, (int) $idValue);
            }
        }

        $hasCursor = $cursorDue !== '' && $cursorId > 0;

        $total = (clone $query)->count();

        $next = (clone $query);

        if ($hasCursor) {
            $next->where(function (Builder $after) use ($cursorDue, $cursorId): void {
                $after->where('due_at', '>', $cursorDue)
                    ->orWhere(function (Builder $sameDate) use ($cursorDue, $cursorId): void {
                        $sameDate->where('due_at', '=', $cursorDue)
                            ->where('id', '>', $cursorId);
                    });
            });
        }

        $tasks = $next->orderBy('due_at')->orderBy('id')->limit($limit)->get();

        // Ao chegar ao final, continuamos pelo início.
        // Não repetimos IDs selecionados no mesmo lote.
        if ($hasCursor && $tasks->count() < $limit) {
            $before = (clone $query)
                ->where(function (Builder $previous) use ($cursorDue, $cursorId): void {
                    $previous->where('due_at', '<', $cursorDue)
                        ->orWhere(function (Builder $sameDate) use ($cursorDue, $cursorId): void {
                            $sameDate->where('due_at', '=', $cursorDue)
                                ->where('id', '<=', $cursorId);
                        });
                })
                ->orderBy('due_at')
                ->orderBy('id')
                ->limit($limit - $tasks->count())
                ->get();

            foreach ($before as $previousTask) {
                $tasks->push($previousTask);
            }
        }
        $stats = [
            'Concluídas confirmadas' => 0,
            'Reprogramadas' => 0,
            'Transferidas de responsável' => 0,
            'Abertas sem alteração' => 0,
            'Não confirmadas (preservadas)' => 0,
            'Erros de API (preservadas)' => 0,
        ];
        /** @var list<list<string>> $samples */
        $samples = [];
        $hadError = false;
        $writes = 0;
        $lastAttemptedCursor = null;

        $this->info($apply ? 'RECONCILIAÇÃO: gravação LOCAL autorizada.' : 'SIMULAÇÃO: nenhuma gravação será realizada.');
        $this->line('Horário Laravel: '.now()->format('d/m/Y H:i:s T'));
        $this->line('Tarefas candidatas: '.$total.' | consultadas nesta rodada: '.min($total, $limit));

        foreach ($tasks as $task) {
            // Posição original, antes de atualizar a tarefa local.
            $lastAttemptedCursor = [
                'due_at' => (string) $task->getRawOriginal('due_at'),
                'id' => (int) $task->id,
            ];
            $id = trim((string) $task->hubspot_id);
            $reason = 'Não confirmadas (preservadas)';
            $status = '—';
            $remoteDue = null;
            $remoteOwnerId = '';
            $remoteUpdatedAt = null;
            $props = null;

            if ($id === '') {
                $stats[$reason]++;

                continue;
            }

            try {
                $response = $client->get(
                    $api.'/crm/v3/objects/tasks/'.rawurlencode($id),
                    ['properties' => 'hs_task_status,hs_timestamp,hubspot_owner_id']
                );
            } catch (Throwable) {
                $reason = 'Erros de API (preservadas)';
                $hadError = true;
                $stats[$reason]++;

                continue;
            }

            if (in_array($response->status(), [401, 403], true)) {
                $this->error('API HubSpot HTTP '.$response->status().'; interrompido sem alterar registros não verificados.');
                $hadError = true;
                $stats['Erros de API (preservadas)']++;
                break;
            }

            if (! $response->successful()) {
                $reason = $response->status() === 404
                    ? 'Não confirmadas (preservadas)'
                    : 'Erros de API (preservadas)';
                $hadError = $hadError || $response->status() !== 404;
                $stats[$reason]++;

                continue;
            }

            $json = $response->json();
            if (! is_array($json) || ($json['archived'] ?? false) === true) {
                $stats[$reason]++;

                continue;
            }
            $props = $json['properties'] ?? null;
            if (! is_array($props)) {
                $stats[$reason]++;

                continue;
            }

            $status = mb_strtoupper(trim((string) ($props['hs_task_status'] ?? '')));
            $remoteDue = self::parseDate($props['hs_timestamp'] ?? null);
            $remoteOwnerId = trim((string) ($props['hubspot_owner_id'] ?? ''));
            $remoteUpdatedAt = self::parseDate($json['updatedAt'] ?? null);
            $isClosed = in_array($status, self::CLOSED, true);
            $isOpen = in_array($status, self::OPEN, true);

            if (! $isClosed && (! $isOpen || $remoteDue === null || $remoteOwnerId === '')) {
                $stats[$reason]++;

                continue;
            }

            $newOwner = null;
            if ($isClosed) {
                $reason = 'Concluídas confirmadas';
            } else {
                $matchingOwner = User::query()->where('hubspot_owner_id', $remoteOwnerId)->first();
                $newOwner = $matchingOwner instanceof User
                    ? trim((string) $matchingOwner->name)
                    : $remoteOwnerId;

                $storedOwner = trim((string) $task->assigned_to);
                $wasTransferred = $storedOwner !== ''
                    && mb_strtolower($storedOwner) !== mb_strtolower($newOwner)
                    && $storedOwner !== $remoteOwnerId;

                if ($wasTransferred) {
                    $reason = 'Transferidas de responsável';
                } elseif (self::dateChanged($task->getRawOriginal('due_at'), $remoteDue)) {
                    $reason = 'Reprogramadas';
                } else {
                    $reason = 'Abertas sem alteração';
                }
            }

            $stats[$reason]++;
            if (count($samples) < $maxSamples) {
                $samples[] = [
                    $id,
                    $reason,
                    $status,
                    $remoteDue?->setTimezone((string) config('app.timezone', 'UTC'))->format('d/m/Y H:i') ?? '—',
                ];
            }

            if (! $apply) {
                continue;
            }

            // Só atualiza um espelho que continua no mesmo estado observado antes do GET.
            $saved = DB::transaction(function () use (
                $task, $status, $isClosed, $remoteDue, $remoteUpdatedAt,
                $newOwner, $remoteOwnerId
            ): bool {
                $current = HubSpotTask::query()->lockForUpdate()->find($task->id);
                if (! $current instanceof HubSpotTask || ! $current->is_open) {
                    return false;
                }
                if ($current->getRawOriginal('updated_at') !== $task->getRawOriginal('updated_at')) {
                    return false;
                }

                $previous = [
                    'status' => $current->status,
                    'is_open' => $current->is_open,
                    'due_at' => (string) $current->getRawOriginal('due_at'),
                    'assigned_to' => $current->assigned_to,
                ];
                $raw = $current->getAttribute('raw_properties');
                if (! is_array($raw)) {
                    $raw = [];
                }
                $raw['prospector_reconciliation'] = [
                    'verified_at' => now()->toIso8601String(),
                    'hubspot_updated_at' => $remoteUpdatedAt?->toIso8601String(),
                    'previous' => $previous,
                    'confirmed_status' => $status,
                    'confirmed_owner_id' => $remoteOwnerId,
                ];

                $attributes = [
                    'status' => $status,
                    'is_open' => ! $isClosed,
                    'is_overdue' => ! $isClosed && $remoteDue->isPast(),
                    'raw_properties' => $raw,
                ];
                if ($isClosed) {
                    $attributes['completed_at'] = $remoteUpdatedAt ?? now();
                }
                if ($remoteDue !== null) {
                    $attributes['due_at'] = $remoteDue;
                }
                if (! $isClosed && $newOwner !== '') {
                    $attributes['assigned_to'] = $newOwner;
                }

                $current->forceFill($attributes)->save();

                return true;
            });

            if ($saved) {
                $writes++;
            }
        }

        // Avança após a última tarefa tentada.
        // Uma resposta sem confirmação não altera o espelho.
        // Erros/404 também não bloqueiam outras tarefas.
        if ($apply && $lastAttemptedCursor !== null) {
            Cache::forever($cursorKey, $lastAttemptedCursor);
        }

        $table = [];
        foreach ($stats as $label => $value) {
            $table[] = [$label, $value];
        }
        $this->table(['Resultado da API', 'Tarefas'], $table);
        if ($samples !== []) {
            $this->table(['ID HubSpot', 'Resultado', 'Status online', 'Vencimento online'], $samples);
        }
        $this->line($apply ? 'Registros locais reconciliados: '.$writes : 'Nenhuma gravação realizada (modo simulação).');
        if ($total > $limit) {
            $this->warn('Há tarefas restantes. O agendador continuará nas próximas rodadas.');
        }
        if ($hadError) {
            $this->warn('Algumas tarefas não puderam ser verificadas e foram preservadas.');
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    private static function dateChanged(mixed $local, CarbonImmutable $remote): bool
    {
        $localDate = self::parseDate($local);

        return $localDate === null || $localDate->getTimestamp() !== $remote->getTimestamp();
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        try {
            return is_numeric($text)
                ? CarbonImmutable::createFromTimestampMs($text)
                : CarbonImmutable::parse($text);
        } catch (Throwable) {
            return null;
        }
    }
}
