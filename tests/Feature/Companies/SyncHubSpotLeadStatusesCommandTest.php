<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

it('returns success when all HubSpot lead statuses sync', function () {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '93333331',

                'corporate_name' => 'Empresa Sync Sucesso',
            ]);

    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-success',

            'hubspot_deal_id' => 'deal-success',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'work_status' => 'new',

            'metadata' => [],
        ]);

    Http::fake(
        function (
            Request $request
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

    $this
        ->artisan(
            'hubspot:lead-statuses',
            [
                '--limit' => 10,
            ]
        )
        ->expectsOutputToContain(
            'Processados: 1 | Falhas: 0'
        )
        ->assertExitCode(0);
});

it('returns failure when a HubSpot lead status sync fails', function () {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '93333332',

                'corporate_name' => 'Empresa Sync Falha',
            ]);

    $lead =
        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_company_id' => 'company-failure',

                'hubspot_deal_id' => 'deal-failure',

                'pipeline_id' => 'default',

                'deal_stage_id' => 'appointmentscheduled',

                'work_status' => 'new',

                'metadata' => [],
            ]);

    Http::fake(
        fn () => Http::response(
            [
                'message' => 'Falha simulada',
            ],
            500
        )
    );

    $this
        ->artisan(
            'hubspot:lead-statuses',
            [
                '--limit' => 10,
            ]
        )
        ->expectsOutputToContain(
            'Processados: 0 | Falhas: 1'
        )
        ->assertExitCode(1);

    expect(
        $lead
            ->refresh()
            ->sync_error
    )->not->toBeNull();
});
