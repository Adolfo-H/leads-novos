<?php

use App\Services\HubSpotLeadStatusResolver;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'services.hubspot.lead_discarded_stages' => [
            '13185627',
            '13185628',
        ],

        'services.hubspot.lead_future_stages' => [
            '13185626',
        ],

        'services.hubspot.lead_refused_stages' => [
            'closedlost',
        ],
    ]);
});

it('maps a new HubSpot lead', function () {
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
});

it('maps a contacted HubSpot lead', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'appointmentscheduled',
            openTasks: 0,
            contactedCount: 1,
            lastActivityAt: null,
        )
    )->toBe('contacting');
});

it('maps a lead with follow up as waiting', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'appointmentscheduled',
            openTasks: 1,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('waiting');
});

it('maps discarded HubSpot stages', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: '13185627',
            openTasks: 2,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('discarded');
});

it('maps future opportunity separately', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: '13185626',
            openTasks: 2,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('future');
});

it('maps refused deal separately', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'closedlost',
            openTasks: 2,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('refused');
});
