<?php

use App\Jobs\HubSpotQueueHeartbeat;
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
    'hubspot:webhooks-recover --minutes=1 --limit=500'
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
