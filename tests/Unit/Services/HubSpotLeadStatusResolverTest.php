<?php

use App\Services\HubSpotLeadStatusResolver;
use Tests\TestCase;

uses(TestCase::class);

it('maps HubSpot activity to the four commercial statuses', function () {
    config([
        'services.hubspot.lead_discarded_stages' => [
            '13185627',
            '13185628',
        ],
    ]);

    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'appointmentscheduled',
            openTasks: 0,
            contactedCount: 0,
            lastActivityAt: null,
        )
    )->toBe('new');

    expect(
        $resolver->resolve(
            dealStage: 'appointmentscheduled',
            openTasks: 0,
            contactedCount: 1,
            lastActivityAt: null,
        )
    )->toBe('contacting');

    expect(
        $resolver->resolve(
            dealStage: 'appointmentscheduled',
            openTasks: 1,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('waiting');

    expect(
        $resolver->resolve(
            dealStage: '13185627',
            openTasks: 1,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('discarded');
});
