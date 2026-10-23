<?php

namespace App\Console\Commands;

use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotWebhookMirrorSyncService;
use App\Support\HubSpotHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Conciliação pontual da lacuna de webhooks, sem criar eventos artificiais.
 * Pesquisa remotamente, lê Deal/Task pelo mesmo sincronizador dos webhooks e
 * atualiza SOMENTE o espelho local com autorização --apply.
 */
final class ReconcileHubSpotCommercialV40 extends Command
{
    protected $signature = 'hubspot:reconcile-commercial-v40
        {--since= : Data inicial AAAA-MM-DD (padrão: últimos 8 dias)}
        {--types=all : all, deals ou tasks}
        {--max=1500 : Limite por tipo, de 1 a 5000; aborta se ultrapassado}
        {--apply : Gravar catálogo e sincronizar espelho; padrão é apenas prévia}';

    protected $description = 'Compara HubSpot online com espelho local e reconcilia negócios/tarefas da lacuna de eventos.';

    /** @var list<string> */
    private const REQUIRED_STAGE_LABELS = [
        'Reunião Agendada', 'Aceite da proposta', 'Contrato Assinado',
    ];

    public function handle(HubSpotWebhookMirrorSyncService $mirror): int
    {
        $apply = (bool) $this->option('apply');
        $types = trim((string) $this->option('types'));
        $max = (int) $this->option('max');
        if (! in_array($types, ['all', 'deals', 'tasks'], true) || $max < 1 || $max > 5000) {
            $this->error('Parâmetros inválidos. --types=all|deals|tasks e --max entre 1 e 5000.');
            return self::FAILURE;
        }

        $date = trim((string) $this->option('since'));
        try {
            $since = $date === ''
                ? CarbonImmutable::now()->subDays(8)->startOfDay()
                : CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('app.timezone'));
            if (! $since instanceof CarbonImmutable || $since->isFuture()) {
                throw new RuntimeException('Data inicial no futuro ou inválida.');
            }
        } catch (Throwable) {
            $this->error('Data inválida. Informe --since=2026-10-16.');
            return self::FAILURE;
        }

        $api = rtrim((string) config('services.hubspot.base_url', ''), '/');
        if (! str_starts_with($api, 'https://')) {
            $this->error('HubSpot BASE URL não é HTTPS. Nenhum dado alterado.');
            return self::FAILURE;
        }

        try {
            // POST aqui é apenas SEARCH/read: jamais envia escrita ao HubSpot.
            $client = HubSpotHttpClient::make(asJson: true, retryMethods: ['GET', 'POST']);
            $stages = $this->onlineStages($client, $api);
            $dealStages = array_values(array_unique(array_map(
                static fn (array $s): string => $s['stage_id'],
                array_filter($stages, static fn (array $s): bool => in_array(
                    $s['stage_label'], self::REQUIRED_STAGE_LABELS, true
                ))
            )));

            $sinceMs = (string) ($since->getTimestamp() * 1000);
            $deals = in_array($types, ['all', 'deals'], true)
                ? $this->search($client, $api, 'deals', [
                    ['filters' => [[
                        'propertyName' => 'hs_lastmodifieddate', 'operator' => 'GTE',
                        'value' => $sinceMs,
                    ]]],
                    ...($dealStages !== [] ? [['filters' => [[
                        'propertyName' => 'dealstage', 'operator' => 'IN',
                        'values' => $dealStages,
                    ]]]] : []),
                ], $max)
                : [];

            $tasks = in_array($types, ['all', 'tasks'], true)
                ? $this->search($client, $api, 'tasks', [
                    ['filters' => [[
                        'propertyName' => 'hs_lastmodifieddate', 'operator' => 'GTE',
                        'value' => $sinceMs,
                    ]]],
                    ['filters' => [
                        ['propertyName' => 'hs_timestamp', 'operator' => 'LT',
                         'value' => (string) (today()->addDay()->getTimestamp() * 1000)],
                        ['propertyName' => 'hs_task_status', 'operator' => 'NEQ',
                         'value' => 'COMPLETED'],
                    ]],
                ], $max)
                : [];
        } catch (Throwable $e) {
            $this->error('Consulta de prévia à API falhou: '.get_class($e).'. Nenhuma escrita local iniciada.');
            return self::FAILURE;
        }

        $this->line($apply ? 'MODO APPLY: somente espelho LOCAL.' : 'PRÉVIA: nenhuma gravação.');
        $this->line('Desde: '.$since->format('d/m/Y').' | etapas do catálogo: '.count($stages));
        $this->line('Negócios remotos candidatos: '.count($deals).' | tarefas remotas candidatas: '.count($tasks));

        foreach (self::REQUIRED_STAGE_LABELS as $label) {
            $stageIds = array_values(array_map(
                static fn (array $stage): string => $stage['stage_id'],
                array_filter($stages, static fn (array $stage): bool => $stage['stage_label'] === $label)
            ));
            $online = count(array_filter($deals, static fn (array $row): bool => in_array(
                (string) ($row['properties']['dealstage'] ?? ''), $stageIds, true
            )));
            $local = $stageIds !== []
                ? HubSpotDeal::query()->whereIn('stage_id', $stageIds)->count()
                : HubSpotDeal::query()->where('stage_label', $label)->count();
            $this->line($label.': remoto amostrado por etapa='.$online.' | espelho local='.$local);
        }

        if (! $apply) {
            $this->comment('Nenhum registro alterado. Confira os números e acrescente --apply explicitamente.');
            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;
        // O catálogo é importado antes dos negócios para resolver stage_id.
        foreach ($stages as $stage) {
            try {
                $this->storeStage($stage);
            } catch (Throwable $e) {
                $failed++;
                $this->warn('Etapa não conciliada: '.$stage['stage_id'].' ('.get_class($e).').');
            }
        }

        foreach (['deal' => $deals, 'task' => $tasks] as $type => $records) {
            foreach ($records as $record) {
                $id = (string) $record['id'];
                if ($id === '') {
                    continue;
                }
                try {
                    // Instância temporária, não insere eventos na tabela de webhooks.
                    $event = new HubSpotWebhookEvent([
                        'object_type' => $type,
                        'object_id' => $id,
                        'subscription_type' => 'object.propertyChange',
                        'payload' => [],
                    ]);
                    $mirror->syncEvent($event);
                    $updated++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn('Falha ao conciliar '.$type.' '.$id.': '.get_class($e));
                }
            }
        }

        $this->line('Objetos conciliados localmente: '.$updated.' | falhas: '.$failed);
        $this->line('Etapas sincronizadas no catálogo: '.count($stages));
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{pipeline_id:string,pipeline_label:string,stage_id:string,stage_label:string,display_order:int}> */
    private function onlineStages(PendingRequest $client, string $api): array
    {
        $response = $client->get($api.'/crm/v3/pipelines/deals');
        if (! $response->successful()) {
            throw new RuntimeException('Falha lendo pipelines HTTP '.$response->status());
        }
        $stages = [];
        foreach ((array) ($response->json('results') ?? []) as $pipeline) {
            if (! is_array($pipeline)) {
                continue;
            }
            $pipelineId = trim((string) ($pipeline['id'] ?? ''));
            if ($pipelineId === '') {
                continue;
            }
            $pipelineLabel = trim((string) ($pipeline['label'] ?? $pipelineId));
            foreach ((array) ($pipeline['stages'] ?? []) as $stage) {
                if (! is_array($stage)) {
                    continue;
                }
                $id = trim((string) ($stage['id'] ?? ''));
                $label = trim((string) ($stage['label'] ?? ''));
                if ($id === '' || $label === '') {
                    continue;
                }
                $stages[] = [
                    'pipeline_id' => $pipelineId,
                    'pipeline_label' => $pipelineLabel,
                    'stage_id' => $id,
                    'stage_label' => $label,
                    'display_order' => (int) ($stage['displayOrder'] ?? 0),
                ];
            }
        }
        return $stages;
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return list<array{id:string,properties:array<string,mixed>}>
     */
    private function search(PendingRequest $client, string $api, string $object, array $groups, int $max): array
    {
        $results = [];
        $after = null;
        $requests = 0;
        do {
            $payload = [
                'filterGroups' => $groups,
                'properties' => $object === 'deals' ? ['dealstage', 'pipeline'] : ['hs_task_status'],
                'limit' => min(100, $max),
            ];
            if ($after !== null) {
                $payload['after'] = $after;
            }
            $response = $client->post($api.'/crm/v3/objects/'.$object.'/search', $payload);
            if (! $response->successful()) {
                throw new RuntimeException('Busca HubSpot '.$object.' HTTP '.$response->status());
            }
            $data = $response->json();
            if (! is_array($data) || ! is_array($data['results'] ?? null)) {
                throw new RuntimeException('Resposta inválida na busca de '.$object);
            }
            foreach ($data['results'] as $item) {
                if (! is_array($item) || ! is_scalar($item['id'] ?? null)) {
                    continue;
                }
                $id = trim((string) $item['id']);
                if ($id !== '') {
                    $results[$id] = [
                        'id' => $id,
                        'properties' => is_array($item['properties'] ?? null) ? $item['properties'] : [],
                    ];
                }
            }
            $after = data_get($data, 'paging.next.after');
            $after = is_scalar($after) ? trim((string) $after) : null;
            $requests++;
            if (count($results) >= $max && $after !== null) {
                throw new RuntimeException('Mais de '.$max.' '.$object.'s remotos; aumente --max antes de aplicar.');
            }
            if ($requests > 60) {
                throw new RuntimeException('Busca remota excedeu 60 páginas; interrompida por segurança.');
            }
        } while ($after !== null && $after !== '');
        return array_values($results);
    }

    /** @param array{pipeline_id:string,pipeline_label:string,stage_id:string,stage_label:string,display_order:int} $item */
    private function storeStage(array $item): void
    {
        DB::transaction(static function () use ($item): void {
            $stage = HubSpotPipelineStage::query()
                ->where('pipeline_id', $item['pipeline_id'])
                ->where('stage_id', $item['stage_id'])
                ->first();
            if ($stage === null) {
                // Reutiliza a etapa importada por CSV antes da chegada dos IDs.
                $stage = HubSpotPipelineStage::query()
                    ->where('pipeline_label', $item['pipeline_label'])
                    ->where('stage_label', $item['stage_label'])
                    ->first();
            }
            $stage ??= new HubSpotPipelineStage();
            $stage->forceFill($item)->save();
        });
    }
}
