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
