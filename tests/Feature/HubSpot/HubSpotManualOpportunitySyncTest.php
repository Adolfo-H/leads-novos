<?php

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\User;
use App\Services\HubSpotLeadSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $response
 */
function manualOpportunityCrmProvider(
    array $response
): void {
    app()->instance(
        CrmCompanyProvider::class,
        new class($response) implements CrmCompanyProvider
        {
            /**
             * @param  array<string, mixed>  $response
             */
            public function __construct(
                private array $response,
            ) {}

            public function name(): string
            {
                return 'fake-hubspot';
            }

            public function findCompany(
                Company $company
            ): array {
                return $this->response;
            }
        }
    );
}

beforeEach(function () {
    config([
        'services.hubspot.access_token' => 'test-token',

        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.lead_pipeline' => 'default',

        'services.hubspot.lead_initial_stage' => 'appointmentscheduled',

        'services.hubspot.lead_task_enabled' => true,

        'services.hubspot.lead_task_hour' => '09:00',

        'services.hubspot.lead_task_priority' => 'HIGH',

        'services.hubspot.lead_task_type' => 'CALL',

        'services.hubspot.company_cnpj_property' => '',
    ]);
});

it('creates company contacts deal owner and initial task for a manual opportunity', function () {
    $user =
        User::factory()->create([
            'email' => 'vendedor@exportcontrol.com.br',

            'hubspot_owner_id' => 'owner-123',
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '91919191',

            'corporate_name' => 'EMPRESA OPORTUNIDADE MANUAL',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'not_found',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '91919191000110',

            'order_number' => '0001',

            'check_digits' => '10',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'state' => 'PR',

            'municipality_name' => 'Cascavel',

            'phone_1' => '45999990001',

            'email' => 'comercial@empresa-manual.test',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '91919191000209',

            'order_number' => '0002',

            'check_digits' => '09',

            'type' => 'branch',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'state' => 'SC',

            'municipality_name' => 'Chapecó',

            'phone_1' => '49999990002',

            'email' => 'exportacao@empresa-manual.test',
        ]);

    manualOpportunityCrmProvider([
        'found' => false,
    ]);

    Http::fake(
        function (
            Request $request
        ) {
            $url =
                $request->url();

            $method =
                $request->method();

            $data =
                $request->data();

            if (
                $method === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/companies'
                )
            ) {
                return Http::response(
                    [
                        'id' => 'company-123',
                    ],
                    201
                );
            }

            if (
                $method === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/contacts/search'
                )
            ) {
                return Http::response([
                    'results' => [],
                ]);
            }

            if (
                $method === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/contacts'
                )
            ) {
                $email =
                    data_get(
                        $data,
                        'properties.email'
                    );

                return Http::response(
                    [
                        'id' => $email
                            === 'comercial@empresa-manual.test'
                                ? 'contact-1'
                                : 'contact-2',
                    ],
                    201
                );
            }

            if (
                $method === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/deals'
                )
            ) {
                return Http::response(
                    [
                        'id' => 'deal-123',
                    ],
                    201
                );
            }

            if (
                $method === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/tasks/search'
                )
            ) {
                return Http::response([
                    'results' => [],
                ]);
            }

            if (
                $method === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/tasks'
                )
            ) {
                return Http::response(
                    [
                        'id' => 'task-123',
                    ],
                    201
                );
            }

            if (
                $method === 'PUT'
                && str_contains(
                    $url,
                    '/associations/default/'
                )
            ) {
                return Http::response(
                    [],
                    200
                );
            }

            return Http::response(
                [
                    'message' => 'Unexpected request: '
                        .$method
                        .' '
                        .$url,
                ],
                500
            );
        }
    );

    /*
     * Nenhum SDR é criado.
     *
     * Isso comprova que a decisão manual
     * não depende da regra automática.
     */
    $sync =
        app(
            HubSpotLeadSyncService::class
        )->syncManual(
            $company->fresh(),
            $user
        );

    expect(
        $sync
            ->hubspot_company_id
    )->toBe(
        'company-123'
    );

    expect(
        $sync
            ->hubspot_contact_id
    )->toBe(
        'contact-1'
    );

    expect(
        $sync
            ->hubspot_deal_id
    )->toBe(
        'deal-123'
    );

    expect(
        $sync
            ->hubspot_task_id
    )->toBe(
        'task-123'
    );

    expect(
        $sync
            ->synced_at
    )->not->toBeNull();

    expect(
        data_get(
            $sync->metadata,
            'manual_sync.hubspot_owner_id'
        )
    )->toBe(
        'owner-123'
    );

    expect(
        data_get(
            $sync->metadata,
            'manual_sync.contact_ids'
        )
    )->toBe([
        'contact-1',
        'contact-2',
    ]);

    Http::assertSent(
        function (
            Request $request
        ): bool {
            if (
                $request->method()
                !== 'POST'
                || ! str_ends_with(
                    $request->url(),
                    '/crm/v3/objects/companies'
                )
            ) {
                return false;
            }

            return data_get(
                $request->data(),
                'properties.hubspot_owner_id'
            ) === 'owner-123';
        }
    );

    Http::assertSent(
        function (
            Request $request
        ): bool {
            if (
                $request->method()
                !== 'POST'
                || ! str_ends_with(
                    $request->url(),
                    '/crm/v3/objects/deals'
                )
            ) {
                return false;
            }

            return data_get(
                $request->data(),
                'properties.hubspot_owner_id'
            ) === 'owner-123';
        }
    );

    Http::assertSent(
        function (
            Request $request
        ): bool {
            if (
                $request->method()
                !== 'POST'
                || ! str_ends_with(
                    $request->url(),
                    '/crm/v3/objects/tasks'
                )
            ) {
                return false;
            }

            return
                data_get(
                    $request->data(),
                    'properties.hubspot_owner_id'
                ) === 'owner-123'
                && data_get(
                    $request->data(),
                    'properties.hs_task_status'
                ) === 'NOT_STARTED'
                && data_get(
                    $request->data(),
                    'properties.hs_task_priority'
                ) === 'HIGH'
                && data_get(
                    $request->data(),
                    'properties.hs_task_type'
                ) === 'CALL'
                && str_contains(
                    (string) data_get(
                        $request->data(),
                        'properties.hs_task_subject'
                    ),
                    '91919191'
                );
        }
    );

    Http::assertSent(
        fn (
            Request $request
        ): bool => $request->method()
                === 'PUT'
            && str_contains(
                $request->url(),
                '/crm/v4/objects/tasks/task-123/'
                .'associations/default/companies/company-123'
            )
    );

    Http::assertSent(
        fn (
            Request $request
        ): bool => $request->method()
                === 'PUT'
            && str_contains(
                $request->url(),
                '/crm/v4/objects/tasks/task-123/'
                .'associations/default/deals/deal-123'
            )
    );
});

it('recovers an existing initial task instead of creating a duplicate', function () {
    $user =
        User::factory()->create([
            'hubspot_owner_id' => 'owner-999',
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '92929292',

            'corporate_name' => 'EMPRESA RETOMADA TAREFA',
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'opportunity',

            'contacted_count' => 1,

            'associated_deals_count' => 1,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'company-existing',

            'hubspot_deal_id' => 'deal-existing',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'metadata' => [],

            'synced_at' => null,
        ]);

    manualOpportunityCrmProvider([
        'found' => true,

        'external_id' => 'company-existing',

        'deals' => [],
    ]);

    Http::fake(
        function (
            Request $request
        ) {
            $url =
                $request->url();

            $method =
                $request->method();

            if (
                $method === 'PATCH'
                && (
                    str_contains(
                        $url,
                        '/crm/v3/objects/companies/'
                    )
                    || str_contains(
                        $url,
                        '/crm/v3/objects/deals/'
                    )
                )
            ) {
                return Http::response(
                    [],
                    200
                );
            }

            if (
                $method === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/tasks/search'
                )
            ) {
                return Http::response([
                    'results' => [
                        [
                            'id' => 'task-existing',
                        ],
                    ],
                ]);
            }

            if (
                $method === 'PUT'
                && str_contains(
                    $url,
                    '/associations/default/'
                )
            ) {
                return Http::response(
                    [],
                    200
                );
            }

            return Http::response(
                [
                    'message' => 'Unexpected request: '
                        .$method
                        .' '
                        .$url,
                ],
                500
            );
        }
    );

    $sync =
        app(
            HubSpotLeadSyncService::class
        )->syncManual(
            $company->fresh(),
            $user
        );

    expect(
        $sync
            ->hubspot_task_id
    )->toBe(
        'task-existing'
    );

    expect(
        $sync
            ->synced_at
    )->not->toBeNull();

    Http::assertNotSent(
        fn (
            Request $request
        ): bool => $request->method()
                === 'POST'
            && $request->url()
                === 'https://api.hubapi.com/crm/v3/objects/tasks'
    );
});
