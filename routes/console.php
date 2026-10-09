<?php

use App\Jobs\HubSpotQueueHeartbeat;
use App\Services\HubSpotTunnelHealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

Schedule::command(
    'hubspot:health-ping'
)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(
    'imports:recover-stale'
)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(
    'exports:recover-stale'
)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:webhooks-recover --minutes=10 --limit=500'
)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:lead-statuses'
)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:leads-recover --minutes=10 --limit=50'
)
    ->everyTenMinutes()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:bulk-recover --minutes=20 --limit=100'
)
    ->everyFiveMinutes()
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
    ->withoutOverlapping();
