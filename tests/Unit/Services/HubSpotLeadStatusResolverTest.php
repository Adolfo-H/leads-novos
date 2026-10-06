<?php

use App\Services\HubSpotLeadStatusResolver;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'services.hubspot.lead_converted_stages' => [
            'closedwon',
        ],

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

it('maps a new lead', function () {
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

it('maps recent commercial activity as contacting', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'appointmentscheduled',
            openTasks: 0,
            contactedCount: 1,
            lastActivityAt: now(),
        )
    )->toBe('contacting');
});

it('maps an open follow up as waiting', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'appointmentscheduled',
            openTasks: 1,
            contactedCount: 1,
            lastActivityAt: now(),
        )
    )->toBe('waiting');
});

it('keeps a won deal terminal even with an open task', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'closedwon',
            openTasks: 2,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('converted');
});

it('keeps a discarded deal terminal even with an open task', function () {
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

it('maps refused deal without an open task as refused', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'closedlost',
            openTasks: 0,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('refused');
});

it('prioritizes an open task over a refused deal', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: 'closedlost',
            openTasks: 1,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('waiting');
});

it('maps future opportunity without an open task as future', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: '13185626',
            openTasks: 0,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('future');
});

it('prioritizes an open task over a future opportunity', function () {
    $resolver =
        app(
            HubSpotLeadStatusResolver::class
        );

    expect(
        $resolver->resolve(
            dealStage: '13185626',
            openTasks: 1,
            contactedCount: 5,
            lastActivityAt: now(),
        )
    )->toBe('waiting');
});
