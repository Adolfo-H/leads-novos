<?php

use App\Models\CompanyHubSpotLead;
use App\Services\HubSpotLeadReprospectingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow(
        '2026-09-17 15:00:00'
    );

    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('releases a future opportunity when its scheduled date arrives', function () {
    $lead =
        new CompanyHubSpotLead([
            'work_status' => 'future',

            'last_task_due_at' => now()->subMinute(),
        ]);

    $result =
        app(
            HubSpotLeadReprospectingService::class
        )->evaluate(
            $lead
        );

    expect(
        $result['eligible']
    )->toBeTrue();

    expect(
        $result['reason']
    )->toBe(
        'future_due'
    );
});

it('keeps a future opportunity waiting until its scheduled date', function () {
    $lead =
        new CompanyHubSpotLead([
            'work_status' => 'future',

            'last_task_due_at' => now()->addDays(5),
        ]);

    $result =
        app(
            HubSpotLeadReprospectingService::class
        )->evaluate(
            $lead
        );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['days_remaining']
    )->toBe(5);
});

it('does not automatically release a future opportunity without a date', function () {
    $lead =
        new CompanyHubSpotLead([
            'work_status' => 'future',
        ]);

    $result =
        app(
            HubSpotLeadReprospectingService::class
        )->evaluate(
            $lead
        );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'future_without_date'
    );
});

it('releases a refused lead after the commercial cooldown', function () {
    $lead =
        new CompanyHubSpotLead([
            'work_status' => 'refused',

            'work_status_changed_at' => now()->subDays(181),
        ]);

    $result =
        app(
            HubSpotLeadReprospectingService::class
        )->evaluate(
            $lead
        );

    expect(
        $result['eligible']
    )->toBeTrue();

    expect(
        $result['reason']
    )->toBe(
        'refused_cooldown_elapsed'
    );
});

it('keeps a recently refused lead blocked', function () {
    $lead =
        new CompanyHubSpotLead([
            'work_status' => 'refused',

            'work_status_changed_at' => now()->subDays(30),
        ]);

    $result =
        app(
            HubSpotLeadReprospectingService::class
        )->evaluate(
            $lead
        );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['days_remaining']
    )->toBe(150);
});

it('never automatically releases an explicitly discarded lead', function () {
    $lead =
        new CompanyHubSpotLead([
            'work_status' => 'discarded',

            'work_status_changed_at' => now()->subYears(2),
        ]);

    $result =
        app(
            HubSpotLeadReprospectingService::class
        )->evaluate(
            $lead
        );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'manual_discard'
    );
});
