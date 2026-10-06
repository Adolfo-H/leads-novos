<?php

use App\Services\HubSpotRepresentativeDealService;

beforeEach(function () {
    config([
        'services.hubspot.lead_future_stages' => [
            'future-stage',
        ],
    ]);
});

it('prefers an active sales deal over a future opportunity', function () {
    $service =
        app(
            HubSpotRepresentativeDealService::class
        );

    $deal =
        $service->select([
            [
                'id' => 'future',
                'stage_id' => 'future-stage',
                'is_closed' => false,
                'is_closed_won' => false,
                'updated_at' => '2026-10-06T11:05:56Z',
            ],
            [
                'id' => 'proposal',
                'stage_id' => 'proposal-stage',
                'is_closed' => false,
                'is_closed_won' => false,
                'updated_at' => '2026-10-06T11:05:53Z',
            ],
        ]);

    expect(
        $deal[
            'id'
        ]
    )->toBe(
        'proposal'
    );
});

it('uses the most recently updated deal when priorities are equal', function () {
    $service =
        app(
            HubSpotRepresentativeDealService::class
        );

    $deal =
        $service->select([
            [
                'id' => 'older',
                'stage_id' => 'proposal-stage',
                'is_closed' => false,
                'is_closed_won' => false,
                'updated_at' => '2026-10-05T10:00:00Z',
            ],
            [
                'id' => 'newer',
                'stage_id' => 'proposal-stage',
                'is_closed' => false,
                'is_closed_won' => false,
                'updated_at' => '2026-10-06T10:00:00Z',
            ],
        ]);

    expect(
        $deal[
            'id'
        ]
    )->toBe(
        'newer'
    );
});
