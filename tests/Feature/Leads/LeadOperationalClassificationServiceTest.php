<?php

use App\Models\CompanyCrmCheck;
use App\Services\LeadOperationalClassificationService;
use Illuminate\Support\Carbon;

it(
    'classifies follow up using the new 30 and 90 day rules',
    function (
        int $contactedCount,
        ?string $lastContactedAt,
        array $tasks,
        string $expected,
    ): void {
        Carbon::setTestNow(
            '2026-09-22 12:00:00'
        );

        try {
            config([
                'prospector.crm.contacting_after_days' => 30,

                'prospector.crm.reprospecting_after_days' => 90,
            ]);

            $crm =
                new CompanyCrmCheck([
                    'status' => 'known',

                    'contacted_count' => $contactedCount,

                    'last_contacted_at' => $lastContactedAt,

                    'metadata' => [
                        'open_tasks' => $tasks,
                    ],
                ]);

            $status =
                app(
                    LeadOperationalClassificationService::class
                )->workStatus(
                    $crm,
                    $tasks
                );

            expect(
                $status
            )->toBe(
                $expected
            );
        } finally {
            Carbon::setTestNow();
        }
    }
)->with([
    'aguardando retorno' => [
        1,
        '2026-09-20 10:00:00',
        [
            [
                'id' => 'task-1',
            ],
        ],
        'waiting',
    ],

    'novo' => [
        0,
        null,
        [],
        'new',
    ],

    'em contato' => [
        1,
        '2026-09-10 10:00:00',
        [],
        'contacting',
    ],

    'oportunidade futura' => [
        1,
        '2026-08-10 10:00:00',
        [],
        'future',
    ],

    'reprospeccao' => [
        1,
        '2026-05-01 10:00:00',
        [],
        'reprospecting',
    ],
]);

it(
    'keeps only the four commercial situations',
    function (): void {
        Carbon::setTestNow(
            '2026-09-22 12:00:00'
        );

        try {
            config([
                'prospector.crm.contacting_after_days' => 30,
                'prospector.crm.reprospecting_after_days' => 90,
            ]);

            $service =
                app(
                    LeadOperationalClassificationService::class
                );

            $new =
                new CompanyCrmCheck([
                    'status' => 'not_found',
                ]);

            $known =
                new CompanyCrmCheck([
                    'status' => 'opportunity',

                    'last_contacted_at' => '2026-09-15 10:00:00',
                ]);

            $client =
                new CompanyCrmCheck([
                    'status' => 'client',
                ]);

            $reprospecting =
                new CompanyCrmCheck([
                    'status' => 'prospected',

                    'last_contacted_at' => '2026-05-01 10:00:00',
                ]);

            expect(
                $service
                    ->commercialStatus(
                        $new
                    )
            )->toBe('new');

            expect(
                $service
                    ->commercialStatus(
                        $known
                    )
            )->toBe('known');

            expect(
                $service
                    ->commercialStatus(
                        $client
                    )
            )->toBe('client');

            expect(
                $service
                    ->commercialStatus(
                        $reprospecting
                    )
            )->toBe(
                'reprospecting'
            );
        } finally {
            Carbon::setTestNow();
        }
    }
);
