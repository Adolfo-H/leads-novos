<?php

use App\Jobs\HubSpotQueueHeartbeat;
use App\Jobs\QueueWorkerHeartbeat;
use App\Services\HubSpotTunnelHealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

Artisan::command(
    'inspire',
    function () {
        $this->comment(
            Inspiring::quote()
        );
    }
)->purpose(
    'Display an inspiring quote'
);

Artisan::command(
    'hubspot:health-ping',
    function (): void {
        Cache::put(
            HubSpotQueueHeartbeat::dispatchCacheKey(),
            now()->toIso8601String(),
            now()->addMinutes(10),
        );

        foreach (
            HubSpotQueueHeartbeat::QUEUES as $queue
        ) {
            HubSpotQueueHeartbeat::dispatch(
                $queue
            );
        }

        $this->info(
            'Heartbeats HubSpot enviados.'
        );
    }
)->purpose(
    'Verifica se os workers prioritários do HubSpot estão consumindo filas.'
);

Artisan::command(
    'ops:scheduler-heartbeat',
    function (): void {
        Cache::put(
            'ops:scheduler:last-heartbeat',
            now()->toIso8601String(),
            now()->addMinutes(5),
        );
    }
)->purpose(
    'Registra a execução do scheduler para diagnóstico operacional.'
);

Artisan::command(
    'ops:workers-ping',
    function (): void {
        $queues = QueueWorkerHeartbeat::QUEUES;

        if ((bool) config('campaign_mail.enabled')) {
            $queues[] = (string) config('campaign_mail.queue', 'campaigns');
        }

        foreach (array_unique($queues) as $queue) {
            QueueWorkerHeartbeat::dispatch($queue);
        }
    }
)->purpose(
    'Envia heartbeats sem payload comercial às filas operacionais.'
);

Artisan::command(
    'ops:health',
    function (): int {
        $healthy = true;
        $lastSchedulerHeartbeat = Cache::get(
            'ops:scheduler:last-heartbeat'
        );

        if (
            ! is_string($lastSchedulerHeartbeat)
            || Carbon::parse($lastSchedulerHeartbeat)
                ->lt(now()->subMinutes(3))
        ) {
            $this->error('Scheduler sem execução recente (limite: 3 minutos).');
            $healthy = false;
        } else {
            $this->info('Scheduler: heartbeat recente.');
        }

        foreach (HubSpotQueueHeartbeat::QUEUES as $queue) {
            $heartbeat = Cache::get(
                HubSpotQueueHeartbeat::cacheKey($queue)
            );

            if (
                ! is_string($heartbeat)
                || Carbon::parse($heartbeat)
                    ->lt(now()->subMinutes(3))
            ) {
                $this->error("Worker {$queue}: sem heartbeat recente.");
                $healthy = false;
            } else {
                $this->info("Worker {$queue}: heartbeat recente.");
            }
        }

        $campaignQueue = (string) config(
            'campaign_mail.queue',
            'campaigns'
        );
        $workerQueues = QueueWorkerHeartbeat::QUEUES;

        if ((bool) config('campaign_mail.enabled')) {
            $workerQueues[] = $campaignQueue;
        }

        foreach (array_unique($workerQueues) as $queue) {
            $heartbeat = Cache::get(
                QueueWorkerHeartbeat::cacheKey($queue)
            );

            if (
                ! is_string($heartbeat)
                || Carbon::parse($heartbeat)
                    ->lt(now()->subMinutes(3))
            ) {
                $this->error("Worker {$queue}: sem heartbeat recente.");
                $healthy = false;
            } else {
                $this->info("Worker {$queue}: heartbeat recente.");
            }
        }

        $queues = array_values(array_unique([
            'default',
            'hubspot-webhooks',
            'hubspot-realtime',
            'hubspot-bulk',
            $campaignQueue,
        ]));

        foreach ($queues as $queue) {
            $this->line(
                "Fila {$queue}: "
                .Queue::connection('redis')->size($queue)
                .' jobs pendentes.'
            );
        }

        return $healthy ? 0 : 1;
    }
)->purpose(
    'Exibe saúde do scheduler, workers prioritários e contagem de filas.'
);

Schedule::command('ops:scheduler-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:workers-ping')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:health-ping'
)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'imports:recover-stale'
)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'imports:dispatch-ready'
)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'exports:recover-stale'
)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:webhooks-recover --minutes=10 --limit=500'
)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:lead-statuses'
)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:leads-recover --minutes=10 --limit=50'
)
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:bulk-recover --minutes=20 --limit=100'
)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

/*
 * Reconciliação preventiva das Primary Companies.
 *
 * Webhooks fazem a atualização em tempo real.
 * Esta rotina cobre eventos perdidos e dados
 * históricos.
 */
Schedule::command(
    'hubspot:sync-deal-primary-companies --apply'
)
    ->dailyAt(
        '03:20'
    )
    ->onOneServer()
    ->withoutOverlapping();

/* HUBSPOT_TASK_RECONCILIATION_V22_START */
/*
 * Rede de segurança para tarefas HubSpot que perderam eventos de webhook.
 * Atualiza somente situações confirmadas pelo CRM via GET.
 */
Schedule::command(
    'prospector:reconcile-hubspot-due-tasks --apply --limit=50 --stale-minutes=20'
)
    ->everyThirtyMinutes()
    ->onOneServer()
    ->withoutOverlapping(30);
/* HUBSPOT_TASK_RECONCILIATION_V22_END */

/*
 * WEB_HEALTH_NONBLOCKING_V24103:
 * Testar a URL publica fora das requisicoes de usuario. Um timeout do
 * tunel nao deve atrasar Leads nem bloquear a unica thread artisan serve.
 */
Artisan::command(
    'hubspot:tunnel-health-refresh',
    function (): void {
        $status = app(HubSpotTunnelHealthService::class)->refresh();
        $this->line('Webhook HubSpot: '.$status['label']);
    }
)->purpose('Atualiza em background a saude do endpoint publico HubSpot.');

Schedule::command('hubspot:tunnel-health-refresh')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

/* EC_EMAIL_CAMPAIGNS_V1_SCHEDULE */
// Com CAMPAIGN_MAIL_LIVE_ENABLED=false não há envios reais.
Schedule::command('campaigns:tick')->everyMinute()->onOneServer()->withoutOverlapping();
// IMAP é somente leitura e fica desabilitado até a configuração de Python.
Schedule::command('campaigns:sync-replies')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
/* EC_EMAIL_CAMPAIGNS_V1_SCHEDULE_END */

// EC_CAMPAIGN_INTELLIGENCE_V9: gera RASCUNHOS, nunca envia; flag desativada por padrão.
Schedule::command('campaigns:prepare-followup-drafts')->hourly()->onOneServer()->withoutOverlapping();
