<?php

namespace App\Console\Commands;

use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Models\User;
use App\Support\HubSpotDateTime;
use App\Support\HubSpotHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HUBSPOT_OWNER_TASKS_V55
 * Reconciliação somente de leitura no HubSpot, com escrita opcional no espelho
 * local. Resgata tarefas não recebidas por webhook, sem criar empresas fictícias.
 */
final class SyncHubSpotOwnerTasksV55 extends Command
{
    protected $signature = 'prospector:sync-hubspot-owner-tasks
        {user? : ID ou e-mail do usuário local; omitido verifica todos os owners configurados}
        {--apply : Autoriza escrita apenas no PostgreSQL/espelho local}
        {--date= : Dia de referência no fuso configurado (AAAA-MM-DD); padrão hoje}
        {--days-back=0 : Inclui vencimentos anteriores ao dia (0 a 365 dias)}
        {--max-pages=30 : Limite de páginas HubSpot por owner (1 a 50)}
        {--samples=15 : Máximo de tarefas ausentes exibidas por owner (0 a 40)}';

    protected $description = 'Compara tarefas do HubSpot com o espelho local e recupera as ausentes sem alterar registros no HubSpot.';

    /** @var list<string> */
    private const CLOSED = [
        'COMPLETED', 'DONE', 'CANCELLED', 'CANCELED', 'CLOSED',
        'CONCLUIDA', 'CONCLUÍDA', 'CONCLUIDO', 'CONCLUÍDO',
        'FINALIZADA', 'FINALIZADO', 'CANCELADO', 'CANCELADA',
    ];

    public function handle(): int
    {
        if (trim((string) config('services.hubspot.access_token')) === '') {
            $this->error('HUBSPOT_ACCESS_TOKEN não configurado. Nenhum dado alterado.');

            return self::FAILURE;
        }

        $tz = trim((string) config('app.timezone', 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        $reference = $this->referenceDate($tz);
        if ($reference === null) {
            return self::FAILURE;
        }

        $daysBack = max(0, min(365, (int) $this->option('days-back')));
        $maxPages = max(1, min(50, (int) $this->option('max-pages')));
        $samples = max(0, min(40, (int) $this->option('samples')));
        $begin = $reference->subDays($daysBack);
        $end = $reference->addDay();
        $apply = (bool) $this->option('apply');

        $selector = trim((string) ($this->argument('user') ?? ''));
        $users = User::query()
            ->whereNotNull('hubspot_owner_id')
            ->where('hubspot_owner_id', '<>', '');
        if ($selector !== '') {
            $users->where(function ($query) use ($selector): void {
                if (ctype_digit($selector)) {
                    $query->whereKey((int) $selector);
                } else {
                    $query->where('email', $selector);
                }
            });
        }

        /** @var EloquentCollection<int, User> $ownerUsers */
        $ownerUsers = $users->get()->unique('hubspot_owner_id')->values();
        if ($ownerUsers->isEmpty()) {
            $this->error('Nenhum usuário local encontrado com hubspot_owner_id.');

            return self::FAILURE;
        }

        $this->line('HubSpot: APENAS leitura | Banco local: '.($apply ? 'aplicação autorizada' : 'simulação'));
        $this->line('Fuso: '.$tz.' | janela: '.$begin->format('d/m/Y 00:00').' até antes de '.$end->format('d/m/Y 00:00'));
        $api = rtrim((string) config('services.hubspot.base_url', 'https://api.hubapi.com'), '/');
        $client = HubSpotHttpClient::make(asJson: true, retryMethods: ['GET', 'POST']);
        $failed = false;

        foreach ($ownerUsers as $user) {
            $ownerId = trim((string) $user->hubspot_owner_id);
            try {
                // Busca todas as páginas ANTES de qualquer gravação.
                // Resposta incompleta ou erro HTTP: nenhuma escrita para este owner.
                $records = $this->fetchRecords($client, $api, $ownerId, $begin, $end, $maxPages);
                $ids = array_keys($records);
                $existing = $ids === []
                    ? collect()
                    : HubSpotTask::query()->whereIn('hubspot_id', $ids)->get()->keyBy('hubspot_id');

                $open = array_filter($records, static fn (array $r): bool => $r['is_open']);
                $missing = array_filter($open, static fn (array $r): bool => ! $existing->has($r['id']));
                $remoteClosed = count($records) - count($open);

                // A diferença de 11 no HubSpot e 10 no Prospector pode ser
                // uma tarefa ausente OU já existente com owner/status/prazo
                // divergente. Diagnosticar pela mesma query que a V54 utiliza.
                $localIds = $open === [] ? [] : HubSpotTask::query()
                    ->dueInPeriod($end, $begin, [$ownerId, trim((string) $user->name)])
                    ->whereIn('hubspot_tasks.hubspot_id', array_keys($open))
                    ->pluck('hubspot_tasks.hubspot_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();
                $localSet = array_fill_keys($localIds, true);
                $difference = array_filter($open, static fn (array $r): bool => ! isset($localSet[$r['id']]));

                $this->info($user->name.' (owner '.$ownerId.'): '.count($open).' abertas no HubSpot, '
                    .count($localIds).' elegíveis na V54, '.count($difference).' divergentes ('
                    .count($missing).' IDs inexistentes); '.$remoteClosed.' concluídas/canceladas na janela.');
                foreach (array_slice($difference, 0, $samples) as $item) {
                    $label = $existing->has($item['id']) ? 'EXISTE, MAS FORA DA FILA' : 'AUSENTE LOCALMENTE';
                    $this->line('  '.$label.' #'.$item['id'].' | '
                        .$item['due_at']->format('d/m/Y H:i').' | '
                        .mb_substr($item['title'], 0, 95));
                }

                if ($apply) {
                    // Uma transação por owner, para não deixar importação parcial
                    // em caso de exceção de banco.
                    DB::transaction(function () use ($records, $user): void {
                        foreach ($records as $item) {
                            $this->saveLocal($item, $user);
                        }
                    });

                    // Só complementa associações para as novas tarefas (se a
                    // empresa/negócio já existir no espelho). Falha de API aqui
                    // não apaga registros ou vínculos existentes.
                    $linked = 0;
                    foreach (array_slice($missing, 0, 50) as $item) {
                        if ($this->attachExistingAssociations($client, $api, $item['id'])) {
                            $linked++;
                        }
                    }
                    $this->info('  Aplicados '.count($records).' registros locais (incluindo fechamentos); '
                        .$linked.' novos vínculos identificados.');
                    if (count($missing) > 50) {
                        $this->warn('  Mais de 50 ausentes: associações adicionais poderão ser atualizadas pelos webhooks.');
                    }
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error('Falha no owner '.$ownerId.': '.$e->getMessage());
            }
        }

        if (! $apply) {
            $this->comment('Simulação concluída. Para recuperar faltantes, repita com --apply.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function referenceDate(string $timezone): ?CarbonImmutable
    {
        $value = trim((string) $this->option('date'));
        if ($value === '') {
            return CarbonImmutable::now($timezone)->startOfDay();
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match) !== 1
            || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            $this->error('Data inválida. Informe --date=AAAA-MM-DD.');

            return null;
        }

        return CarbonImmutable::create((int) $match[1], (int) $match[2], (int) $match[3], 0, 0, 0, $timezone);
    }

    /**
     * @return array<string, array{id:string,status:string,is_open:bool,due_at:CarbonImmutable,title:string,properties:array<string,mixed>,created_at:CarbonImmutable|null,updated_at:CarbonImmutable|null}>
     */
    private function fetchRecords(
        PendingRequest $client,
        string $api,
        string $ownerId,
        CarbonImmutable $begin,
        CarbonImmutable $end,
        int $maxPages,
    ): array {
        $byId = [];
        $after = null;
        $cursors = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $payload = [
                'filterGroups' => [[
                    'filters' => [
                        ['propertyName' => 'hubspot_owner_id', 'operator' => 'EQ', 'value' => $ownerId],
                        ['propertyName' => 'hs_timestamp', 'operator' => 'GTE', 'value' => (string) $begin->getTimestampMs()],
                        ['propertyName' => 'hs_timestamp', 'operator' => 'LT', 'value' => (string) $end->getTimestampMs()],
                    ],
                ]],
                'properties' => [
                    'hubspot_owner_id', 'hs_timestamp', 'hs_task_subject', 'hs_task_body',
                    'hs_task_status', 'hs_task_type', 'hs_task_priority',
                ],
                'sorts' => ['hs_timestamp'],
                'limit' => 200,
            ];
            if ($after !== null) {
                $payload['after'] = $after;
            }
            $response = $client->post($api.'/crm/v3/objects/tasks/search', $payload);
            if (! $response->successful()) {
                throw new RuntimeException('Consulta HubSpot falhou: HTTP '.$response->status());
            }

            $data = $response->json();
            if (! is_array($data) || ! is_array($data['results'] ?? null)) {
                throw new RuntimeException('HubSpot retornou página inválida.');
            }
            foreach ($data['results'] as $record) {
                if (! is_array($record) || ($record['archived'] ?? false) === true) {
                    continue;
                }
                $properties = $record['properties'] ?? null;
                if (! is_array($properties)) {
                    continue;
                }
                $id = trim((string) ($record['id'] ?? ''));
                if ($id === '' || trim((string) ($properties['hubspot_owner_id'] ?? '')) !== $ownerId) {
                    continue;
                }
                $due = HubSpotDateTime::parse($properties['hs_timestamp'] ?? null);
                if ($due === null || $due->lt($begin) || $due->gte($end)) {
                    continue;
                }
                $status = mb_strtoupper(trim((string) ($properties['hs_task_status'] ?? '')));
                if ($status === '') {
                    // Sem status confirmado, não inferir que a tarefa está aberta.
                    continue;
                }
                $byId[$id] = [
                    'id' => $id,
                    'status' => $status,
                    'is_open' => ! in_array($status, self::CLOSED, true),
                    'due_at' => $due,
                    'title' => trim((string) ($properties['hs_task_subject'] ?? '')) ?: 'Tarefa HubSpot',
                    'properties' => $properties,
                    'created_at' => HubSpotDateTime::parse($record['createdAt'] ?? null),
                    'updated_at' => HubSpotDateTime::parse($record['updatedAt'] ?? null),
                ];
            }

            $next = data_get($data, 'paging.next.after');
            $next = is_scalar($next) ? trim((string) $next) : '';
            if ($next === '') {
                return $byId;
            }
            if (isset($cursors[$next]) || $next === $after) {
                throw new RuntimeException('Cursor HubSpot repetido: paginação incompleta.');
            }
            $cursors[$next] = true;
            $after = $next;
        }

        throw new RuntimeException('HubSpot ainda tem páginas após --max-pages: nada foi gravado para este owner.');
    }

    /**
     * @param  array{id:string,status:string,is_open:bool,due_at:CarbonImmutable,title:string,properties:array<string,mixed>,created_at:CarbonImmutable|null,updated_at:CarbonImmutable|null}  $item
     */
    private function saveLocal(array $item, User $user): void
    {
        $task = HubSpotTask::query()->where('hubspot_id', $item['id'])->lockForUpdate()->first();
        if ($task === null && ! $item['is_open']) {
            // Não importa tarefas concluídas sem correspondente local.
            return;
        }
        $task ??= HubSpotTask::query()->firstOrCreate(
            ['hubspot_id' => $item['id']],
            ['is_open' => false, 'is_overdue' => false]
        );

        $rawProperties = $task->getAttribute('raw_properties');
        /** @var array<string, mixed> $previous */
        $previous = is_array($rawProperties) ? $rawProperties : [];
        $remotePrevious = HubSpotDateTime::parse($previous['prospector_v55_remote_updated_at'] ?? null);
        $remoteCurrent = $item['updated_at'];
        if ($remotePrevious !== null && ($remoteCurrent === null || $remoteCurrent->lt($remotePrevious))) {
            return; // não sobrepor um instantâneo remoto mais novo.
        }

        $props = $item['properties'];
        $completedAt = $item['is_open'] ? null : ($remoteCurrent ?? now());
        $task->forceFill([
            'title' => $item['title'],
            'status' => $item['status'],
            'type' => $props['hs_task_type'] ?? $task->type,
            'notes' => $props['hs_task_body'] ?? $task->notes,
            'assigned_to' => $user->name,
            'due_at' => $item['due_at'],
            'is_open' => $item['is_open'],
            'is_overdue' => $item['is_open'] && $item['due_at']->lte(now()),
            'completed_at' => $completedAt,
            'hubspot_created_at' => $item['created_at'] ?? $task->hubspot_created_at,
            'raw_properties' => array_merge($previous, $props, [
                'hubspot_owner_id' => (string) $user->hubspot_owner_id,
                'prospector_v55_remote_updated_at' => $remoteCurrent?->toIso8601String()
                    ?? ($previous['prospector_v55_remote_updated_at'] ?? null),
                'prospector_v55_synced_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    /** Preenche vínculos locais existentes; não remove associações nem cria Company por inferência. */
    private function attachExistingAssociations(PendingRequest $client, string $api, string $hubspotId): bool
    {
        $task = HubSpotTask::query()->where('hubspot_id', $hubspotId)->first();
        if ($task === null || $task->companies()->exists() || $task->deals()->exists()) {
            return false;
        }

        $linked = false;
        foreach (['companies' => HubSpotCompany::class, 'deals' => HubSpotDeal::class] as $kind => $class) {
            try {
                $response = $client->get(
                    $api.'/crm/v4/objects/tasks/'.rawurlencode($hubspotId).'/associations/'.$kind,
                    ['limit' => 100],
                );
                if (! $response->successful()) {
                    continue;
                }
                $payload = $response->json('results');
                if (! is_array($payload)) {
                    continue;
                }
                $ids = [];
                foreach ($payload as $assoc) {
                    if (is_array($assoc) && is_scalar($assoc['toObjectId'] ?? null)) {
                        $ids[] = (string) $assoc['toObjectId'];
                    }
                }
                if ($ids === []) {
                    continue;
                }
                $local = $class::query()->whereIn('hubspot_id', $ids)->pluck('id')->all();
                if ($local === []) {
                    continue;
                }
                if ($kind === 'companies') {
                    $task->companies()->syncWithoutDetaching($local);
                } else {
                    $task->deals()->syncWithoutDetaching($local);
                }
                $linked = true;
            } catch (Throwable) {
                // Falha parcial de associação jamais pode apagar a tarefa recuperada.
            }
        }

        return $linked;
    }
}
