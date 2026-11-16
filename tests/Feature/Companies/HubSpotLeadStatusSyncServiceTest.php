<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Services\HubSpotLeadStatusSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function statusFlowLead(
    string $root = '90112233'
): CompanyHubSpotLead {
    $company =
        Company::query()->create([
            'cnpj_root' => $root,
            'corporate_name' => 'EMPRESA STATUS HUBSPOT',
        ]);

    return CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_company_id' => 'company-100',
        'hubspot_deal_id' => 'deal-200',
        'pipeline_id' => 'default',
        'deal_stage_id' => 'appointmentscheduled',
        'work_status' => 'new',
        'synced_at' => now(),
        'metadata' => [],
    ]);
}

function fakeHubSpotLeadStatus(
    string $dealStage = 'appointmentscheduled',
    int $contactedCount = 0,
    bool $openTask = false,
): void {
    Http::fake(
        function (
            Request $request
        ) use (
            $dealStage,
            $contactedCount,
            $openTask,
        ) {
            $url =
                $request->url();

            if (
                str_contains(
                    $url,
                    '/crm/v3/objects/companies/'
                )
            ) {
                return Http::response([
                    'properties' => [
                        'num_contacted_notes' => (string) $contactedCount,

                        'notes_last_contacted' => null,

                        'notes_last_updated' => null,
                    ],
                ]);
            }

            if (
                str_contains(
                    $url,
                    '/crm/v3/objects/deals/'
                )
            ) {
                return Http::response([
                    'properties' => [
                        'dealstage' => $dealStage,

                        'num_contacted_notes' => '0',

                        'notes_last_contacted' => null,

                        'notes_last_updated' => null,
                    ],
                ]);
            }

            if (
                str_contains(
                    $url,
                    '/associations/tasks'
                )
            ) {
                return Http::response([
                    'results' => $openTask
                            ? [
                                [
                                    'toObjectId' => 'task-300',
                                ],
                            ]
                            : [],
                ]);
            }

            if (
                str_contains(
                    $url,
                    '/crm/v3/objects/tasks/'
                )
            ) {
                return Http::response([
                    'properties' => [
                        'hs_task_status' => 'NOT_STARTED',

                        'hs_task_subject' => 'Retornar contato',

                        'hs_timestamp' => now()
                            ->addDay()
                            ->timestamp
                                * 1000,
                    ],
                ]);
            }

            return Http::response(
                [],
                404
            );
        }
    );
}

beforeEach(function () {
    config([
        'services.hubspot.access_token' => 'test-token',

        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.lead_discarded_stages' => [
            '13185627',
            '13185628',
        ],
    ]);
});

it('keeps an untouched HubSpot lead as new', function () {
    $lead =
        statusFlowLead(
            '90112231'
        );

    fakeHubSpotLeadStatus();

    $result =
        app(
            HubSpotLeadStatusSyncService::class
        )->sync(
            $lead
        );

    expect(
        $result->work_status
    )->toBe(
        'new'
    );

    expect(
        $result->open_task_count
    )->toBe(0);
});

it('marks a contacted HubSpot lead as contacting', function () {
    $lead =
        statusFlowLead(
            '90112232'
        );

    fakeHubSpotLeadStatus(
        contactedCount: 1
    );

    $result =
        app(
            HubSpotLeadStatusSyncService::class
        )->sync(
            $lead
        );

    expect(
        $result->work_status
    )->toBe(
        'contacting'
    );

    expect(
        $result->last_activity_type
    )->toBe(
        'hubspot_activity'
    );
});

it('rolls back status changes when audit persistence fails', function () {
    $lead =
        statusFlowLead(
            '90112239'
        );

    fakeHubSpotLeadStatus(
        contactedCount: 1
    );

    DB::unprepared(
        'CREATE TRIGGER fail_hubspot_status_audit '
        .'BEFORE INSERT ON company_lead_activities '
        ."WHEN NEW.type = 'hubspot_status_changed' "
        ."BEGIN SELECT RAISE(ABORT, 'forced status audit failure'); END"
    );

    try {
        expect(
            fn () => app(
                HubSpotLeadStatusSyncService::class
            )->sync(
                $lead
            )
        )->toThrow(
            QueryException::class,
            'forced status audit failure'
        );

        expect(
            $lead->fresh()->work_status
        )->toBe(
            'new'
        );

        expect(
            $lead->fresh()->status_synced_at
        )->toBeNull();

        expect(
            $lead->company
                ->leadActivities()
                ->where(
                    'type',
                    'hubspot_status_changed'
                )
                ->exists()
        )->toBeFalse();
    } finally {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS fail_hubspot_status_audit'
        );
    }
});

it('marks a lead with an open task as waiting', function () {
    $lead =
        statusFlowLead(
            '90112233'
        );

    fakeHubSpotLeadStatus(
        contactedCount: 1,
        openTask: true,
    );

    $result =
        app(
            HubSpotLeadStatusSyncService::class
        )->sync(
            $lead
        );

    expect(
        $result->work_status
    )->toBe(
        'waiting'
    );

    expect(
        $result->open_task_count
    )->toBe(1);

    expect(
        $result->last_activity_type
    )->toBe(
        'task'
    );

    expect(
        $result->last_task_due_at
    )->not->toBeNull();
});

it('follows all pages when loading associated HubSpot tasks', function () {
    $lead =
        statusFlowLead(
            '90112238'
        );

    $companyTaskPages = [];
    $firstPageTasks =
        array_map(
            static fn (
                int $id
            ): array => [
                'toObjectId' => (string) $id,
            ],
            range(300, 399)
        );

    Http::fake(function (Request $request) use (
        &$companyTaskPages,
        $firstPageTasks,
    ) {
        $url =
            $request->url();

        if (
            str_contains(
                $url,
                '/crm/v3/objects/companies/'
            )
        ) {
            return Http::response([
                'properties' => [
                    'num_contacted_notes' => '0',
                    'notes_last_contacted' => null,
                    'notes_last_updated' => null,
                ],
            ]);
        }

        if (
            str_contains(
                $url,
                '/crm/v3/objects/deals/'
            )
        ) {
            return Http::response([
                'properties' => [
                    'dealstage' => 'appointmentscheduled',
                    'num_contacted_notes' => '0',
                    'notes_last_contacted' => null,
                    'notes_last_updated' => null,
                ],
            ]);
        }

        if (
            str_contains(
                $url,
                '/associations/tasks'
            )
            && str_contains(
                $url,
                '/companies/'
            )
        ) {
            parse_str(
                (string) parse_url(
                    $url,
                    PHP_URL_QUERY
                ),
                $query
            );

            $after =
                $query['after']
                ?? null;

            $companyTaskPages[] =
                $after;

            if ($after === 'cursor-next') {
                return Http::response([
                    'results' => [
                        [
                            'toObjectId' => '400',
                        ],
                    ],
                ]);
            }

            return Http::response([
                'results' => $firstPageTasks,
                'paging' => [
                    'next' => [
                        'after' => 'cursor-next',
                    ],
                ],
            ]);
        }

        if (
            str_contains(
                $url,
                '/associations/tasks'
            )
            && str_contains(
                $url,
                '/deals/'
            )
        ) {
            return Http::response([
                'results' => [],
            ]);
        }

        if (
            str_contains(
                $url,
                '/crm/v3/objects/tasks/'
            )
        ) {
            return Http::response([
                'properties' => [
                    'hs_task_status' => 'NOT_STARTED',
                    'hs_task_subject' => 'Retornar contato',
                    'hs_timestamp' => now()
                        ->addDay()
                        ->timestamp
                            * 1000,
                ],
            ]);
        }

        return Http::response(
            [],
            404
        );
    });

    $result =
        app(
            HubSpotLeadStatusSyncService::class
        )->sync(
            $lead
        );

    expect(
        $result->open_task_count
    )->toBe(101);

    expect(
        $companyTaskPages
    )->toBe([
        null,
        'cursor-next',
    ]);
});

it('gives discarded deal stage priority over tasks and activity', function () {
    $lead =
        statusFlowLead(
            '90112234'
        );

    fakeHubSpotLeadStatus(
        dealStage: '13185627',
        contactedCount: 5,
        openTask: true,
    );

    $result =
        app(
            HubSpotLeadStatusSyncService::class
        )->sync(
            $lead
        );

    expect(
        $result->work_status
    )->toBe(
        'discarded'
    );

    expect(
        $result->deal_stage_id
    )->toBe(
        '13185627'
    );

    expect(
        $result->last_activity_type
    )->toBe(
        'deal_stage'
    );
});

it('does not double count the same HubSpot contact activity', function () {
    $lead =
        statusFlowLead(
            '90112235'
        );

    Http::fake(function (Request $request) {
        $url =
            $request->url();

        if (
            str_contains(
                $url,
                '/crm/v3/objects/companies/'
            )
            || str_contains(
                $url,
                '/crm/v3/objects/deals/'
            )
        ) {
            return Http::response([
                'properties' => [
                    'dealstage' => 'appointmentscheduled',
                    'num_contacted_notes' => '1',
                    'notes_last_contacted' => now()->toIso8601String(),
                    'notes_last_updated' => now()->toIso8601String(),
                ],
            ]);
        }

        if (
            str_contains(
                $url,
                '/associations/tasks'
            )
        ) {
            return Http::response([
                'results' => [],
            ]);
        }

        return Http::response(
            [],
            404
        );
    });

    $result =
        app(
            HubSpotLeadStatusSyncService::class
        )->sync(
            $lead
        );

    expect(
        data_get(
            $result->metadata,
            'hubspot_status.contacted_count'
        )
    )->toBe(1);

    expect(
        $result->work_status
    )->toBe(
        'contacting'
    );
});

it('marks a closed won HubSpot deal as converted', function () {
    $lead =
        statusFlowLead(
            '90112236'
        );

    fakeHubSpotLeadStatus(
        dealStage: 'closedwon',
        contactedCount: 5,
        openTask: true,
    );

    config([
        'services.hubspot.lead_converted_stages' => [
            'closedwon',
        ],
    ]);

    $result =
        app(
            HubSpotLeadStatusSyncService::class
        )->sync(
            $lead
        );

    expect(
        $result->work_status
    )->toBe(
        'converted'
    );

    expect(
        $result->deal_stage_id
    )->toBe(
        'closedwon'
    );

    expect(
        $result->last_activity_type
    )->toBe(
        'deal_stage'
    );
});
