<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadActivity;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function reprospectingActionUiCompany(): Company
{
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '98666661',

                'corporate_name' => 'Empresa Botao Retomar',
            ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 80,
            'priority' => 'high',
            'label' => 'Prioridade alta',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
        ]);

    return $company;
}

function fakeReprospectingActionUiHubSpot(): void
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
                    '/crm/v3/objects/deals/deal-ui'
                )
            ) {
                return Http::response([
                    'id' => 'deal-ui',
                ]);
            }

            if (
                str_contains(
                    $url,
                    '/crm/v3/objects/companies/company-ui'
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
                str_contains(
                    $url,
                    '/crm/v3/objects/deals/deal-ui'
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

it('allows an eligible user to resume a lead from the SDR queue', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        reprospectingActionUiCompany();

    $lead =
        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_company_id' => 'company-ui',

                'hubspot_deal_id' => 'deal-ui',

                'pipeline_id' => 'default',

                'deal_stage_id' => 'closedlost',

                'work_status' => 'refused',

                'work_status_changed_at' => now()->subDays(181),

                'open_task_count' => 0,

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

    fakeReprospectingActionUiHubSpot();

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->assertSee(
            'Empresa Botao Retomar'
        )
        ->assertSee(
            'Retomar lead'
        )
        ->call(
            'resumeLead',
            $lead->id
        )
        ->assertSet(
            'commercialActionError',
            ''
        )
        ->assertSet(
            'commercialActionMessage',
            'Lead retomado no HubSpot. Status atual: Em contato.'
        );

    $lead =
        $lead->refresh();

    expect(
        $lead->deal_stage_id
    )->toBe(
        'appointmentscheduled'
    );

    expect(
        $lead->work_status
    )->toBe(
        'contacting'
    );

    $activity =
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $company->id
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
});

it('does not show the resume action while the refused cooldown is active', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $company =
        reprospectingActionUiCompany();

    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-ui',

            'hubspot_deal_id' => 'deal-ui',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'closedlost',

            'work_status' => 'refused',

            'work_status_changed_at' => now()->subDays(30),

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);

    Livewire::actingAs($user)
        ->test(
            'pages::leads.index'
        )
        ->assertSee(
            'Empresa Botao Retomar'
        )
        ->assertDontSee(
            'Retomar lead'
        )
        ->assertSee(
            'Reprospecção disponível em 150 dia(s).'
        );
});
