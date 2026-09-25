<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
    'hubspot:lead-statuses'
)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(
    'hubspot:leads-recover --minutes=10 --limit=50'
)
    ->everyTenMinutes()
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
