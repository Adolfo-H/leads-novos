<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadActivity;
use App\Models\User;
use App\Services\HubSpotLeadReprospectingActionService;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function resumeActionLead(
    string $status,
    DateTimeInterface $changedAt,
    ?DateTimeInterface $dueAt = null,
): CompanyHubSpotLead {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => fake()->unique()->numerify(
                    '########'
                ),

                'corporate_name' => 'Empresa Retomada',
            ]);

    return CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-100',

            'hubspot_deal_id' => 'deal-200',

            'pipeline_id' => 'default',

            'deal_stage_id' => $status === 'future'
                    ? '13185626'
                    : 'closedlost',

            'work_status' => $status,

            'work_status_changed_at' => $changedAt,

            'last_task_due_at' => $dueAt,

            'open_task_count' => 0,

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);
}

function fakeSuccessfulResumeHubSpot(): void
{
    Http::fake(
        function (
            Request $request
        ) {
            $url =
                $request->url();

            if (
                $request->method() === 'PATCH'
                && str_contains(
                    $url,
                    '/crm/v3/objects/deals/deal-200'
                )
            ) {
                return Http::response([
                    'id' => 'deal-200',

                    'properties' => [
                        'dealstage' => 'appointmentscheduled',
                    ],
                ]);
            }

            if (
                $request->method() === 'GET'
                && str_contains(
                    $url,
                    '/crm/v3/objects/companies/company-100'
                )
            ) {
                return Http::response([
                    'properties' => [
                        'num_contacted_notes' => '1',

                        'notes_last_contacted' => now()
                            ->subDay()
                            ->toIso8601String(),

                        'notes_last_updated' => now()
                            ->subDay()
                            ->toIso8601String(),
                    ],
                ]);
            }

            if (
                $request->method() === 'GET'
                && str_contains(
                    $url,
                    '/crm/v3/objects/deals/deal-200'
                )
            ) {
                return Http::response([
                    'properties' => [
                        'dealstage' => 'appointmentscheduled',

                        'num_contacted_notes' => '1',

                        'notes_last_contacted' => now()
                            ->subDay()
                            ->toIso8601String(),

                        'notes_last_updated' => now()
                            ->subDay()
                            ->toIso8601String(),
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
        }
    );
}

beforeEach(function () {
    Carbon::setTestNow(
        '2026-09-17 15:00:00'
    );

    config([
        'services.hubspot.access_token' => 'test-token',

        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.lead_initial_stage' => 'appointmentscheduled',

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

        'prospector.crm.reprospecting_after_days' => 180,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reuses the existing HubSpot deal when resuming a future opportunity', function () {
    $user =
        User::factory()
            ->create();

    $this->actingAs(
        $user
    );

    $lead =
        resumeActionLead(
            status: 'future',
            changedAt: now()->subDays(30),
            dueAt: now()->subHour(),
        );

    fakeSuccessfulResumeHubSpot();

    $updated =
        app(
            HubSpotLeadReprospectingActionService::class
        )->resume(
            $lead
        );

    expect(
        $updated->hubspot_deal_id
    )->toBe(
        'deal-200'
    );

    expect(
        $updated->deal_stage_id
    )->toBe(
        'appointmentscheduled'
    );

    expect(
        $updated->work_status
    )->toBe(
        'contacting'
    );

    expect(
        $updated->work_status_changed_at
    )->not->toBeNull();

    $activity =
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $lead->company_id
            )
            ->where(
                'type',
                'reprospecting_started'
            )
            ->first();

    expect(
        $activity
    )->not->toBeNull();

    expect(
        $activity?->user_id
    )->toBe(
        $user->id
    );

    Http::assertSent(
        function (
            Request $request
        ): bool {
            if (
                $request->method() !== 'PATCH'
                || ! str_contains(
                    $request->url(),
                    '/crm/v3/objects/deals/deal-200'
                )
            ) {
                return false;
            }

            $data =
                $request->data();

            return data_get(
                $data,
                'properties.dealstage'
            ) === 'appointmentscheduled';
        }
    );
});

it('does not resume a refused lead before its cooldown ends', function () {
    $lead =
        resumeActionLead(
            status: 'refused',
            changedAt: now()->subDays(30),
        );

    Http::fake();

    expect(
        fn () => app(
            HubSpotLeadReprospectingActionService::class
        )->resume(
            $lead
        )
    )->toThrow(
        DomainException::class
    );

    Http::assertNothingSent();
});

it('keeps a local fallback when HubSpot moved the deal but reconciliation fails', function () {
    $lead =
        resumeActionLead(
            status: 'refused',
            changedAt: now()->subDays(181),
        );

    Http::fake(
        function (
            Request $request
        ) {
            if (
                $request->method() === 'PATCH'
            ) {
                return Http::response([
                    'id' => 'deal-200',
                ]);
            }

            return Http::response(
                [],
                500
            );
        }
    );

    $updated =
        app(
            HubSpotLeadReprospectingActionService::class
        )->resume(
            $lead
        );

    expect(
        $updated->deal_stage_id
    )->toBe(
        'appointmentscheduled'
    );

    expect(
        $updated->work_status
    )->toBe(
        'contacting'
    );

    expect(
        $updated->status_synced_at
    )->toBeNull();

    expect(
        $updated->sync_error
    )->toBe(
        'Retomada aplicada no HubSpot; aguardando reconciliação automática.'
    );
});
