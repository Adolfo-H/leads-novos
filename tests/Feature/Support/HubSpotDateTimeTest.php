<?php

use App\Support\HubSpotDateTime;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config([
        'app.timezone' => 'America/Sao_Paulo',
    ]);
});

it('converts HubSpot UTC ISO time to Sao Paulo time', function () {
    $date =
        HubSpotDateTime::parse(
            '2026-10-06T11:00:00.000Z'
        );

    expect(
        $date?->format(
            'd/m/Y H:i'
        )
    )->toBe(
        '06/10/2026 08:00'
    );

    expect(
        $date
            ?->getTimezone()
            ->getName()
    )->toBe(
        'America/Sao_Paulo'
    );
});

it('converts HubSpot milliseconds to Sao Paulo time', function () {
    $milliseconds =
        CarbonImmutable::parse(
            '2026-10-06 11:00:00',
            'UTC'
        )->getTimestampMs();

    $date =
        HubSpotDateTime::parse(
            $milliseconds
        );

    expect(
        $date?->format(
            'd/m/Y H:i'
        )
    )->toBe(
        '06/10/2026 08:00'
    );
});

it('keeps the same instant while converting timezone', function () {
    $utc =
        CarbonImmutable::parse(
            '2026-10-06 11:00:00',
            'UTC'
        );

    $date =
        HubSpotDateTime::parse(
            $utc
        );

    expect(
        $date?->getTimestamp()
    )->toBe(
        $utc->getTimestamp()
    );

    expect(
        $date?->format(
            'H:i'
        )
    )->toBe(
        '08:00'
    );
});
