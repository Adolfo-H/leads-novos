<?php

use Illuminate\Support\Facades\Schema;

it('stores HubSpot owner and initial task identifiers', function () {
    expect(
        Schema::hasColumn(
            'users',
            'hubspot_owner_id'
        )
    )->toBeTrue();

    expect(
        Schema::hasColumn(
            'company_hubspot_leads',
            'hubspot_task_id'
        )
    )->toBeTrue();
});

it('documents manual opportunity task settings', function () {
    expect(
        config(
            'services.hubspot.lead_task_enabled'
        )
    )->not->toBeNull();

    expect(
        config(
            'services.hubspot.lead_task_hour'
        )
    )->toBe(
        '09:00'
    );

    expect(
        config(
            'services.hubspot.lead_task_priority'
        )
    )->toBe(
        'HIGH'
    );

    expect(
        config(
            'services.hubspot.lead_task_type'
        )
    )->toBe(
        'CALL'
    );
});
