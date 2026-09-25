<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotWebhookMirrorSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it(
    'updates an unmatched HubSpot deal and keeps the webhook processed',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $company =
            HubSpotCompany::query()
                ->create([
                    'company_id' => null,

                    'hubspot_id' => '9258277170',

                    'name' => 'AGROSUL CEREAIS',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-100',

                    'name' => 'Licenciamento - AGROSUL CEREAIS',

                    'pipeline_id' => 'default',

                    'stage_id' => 'closedlost',

                    'stage_label' => 'Recusou',

                    'is_closed' => true,

                    'is_closed_won' => false,
                ]);

        $company
            ->deals()
            ->attach(
                $deal->id
            );

        $event =
            HubSpotWebhookEvent::query()
                ->create([
                    'event_key' => 'mirror-test-deal-stage',

                    'subscription_type' => 'object.propertyChange',

                    'object_type' => 'deal',

                    'object_type_id' => '0-3',

                    'object_id' => 'deal-100',

                    'property_name' => 'dealstage',

                    'status' => 'received',

                    'payload' => [
                        'objectTypeId' => '0-3',

                        'objectId' => 'deal-100',

                        'subscriptionType' => 'object.propertyChange',

                        'propertyName' => 'dealstage',

                        'propertyValue' => 'qualified',
                    ],
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
                        '/crm/v3/objects/deals/deal-100'
                    )
                ) {
                    return Http::response([
                        'id' => 'deal-100',

                        'createdAt' => '2026-09-01T12:00:00Z',

                        'updatedAt' => '2026-09-25T17:00:00Z',

                        'properties' => [
                            'dealname' => 'Licenciamento - AGROSUL CEREAIS',

                            'pipeline' => 'default',

                            'dealstage' => 'qualified',

                            'hubspot_owner_id' => null,

                            'amount' => null,

                            'deal_currency_code' => null,

                            'hs_is_closed' => 'false',

                            'hs_is_closed_won' => 'false',

                            'closedate' => null,

                            'notes_last_contacted' => null,

                            'notes_last_updated' => null,
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/crm/v3/pipelines/deals'
                    )
                ) {
                    return Http::response([
                        'results' => [
                            [
                                'id' => 'default',

                                'label' => 'Pipeline de vendas',

                                'stages' => [
                                    [
                                        'id' => 'qualified',

                                        'label' => 'Leds qualificado',

                                        'displayOrder' => 2,
                                    ],
                                ],
                            ],
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/crm/v4/objects/deals/deal-100/associations/companies'
                    )
                ) {
                    return Http::response([
                        'results' => [
                            [
                                'toObjectId' => '9258277170',
                            ],
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/crm/v4/objects/deals/deal-100/associations/'
                    )
                ) {
                    return Http::response([
                        'results' => [],
                    ]);
                }

                /*
                 * Resolver pode fazer consultas
                 * adicionais antes/depois do
                 * mirror. Retornamos vazio.
                 */
                if (
                    str_contains(
                        $url,
                        '/associations/'
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

        Queue::fake();

        app()->call([
            new ProcessHubSpotWebhookEvent(
                $event->id
            ),

            'handle',
        ]);

        $deal =
            $deal->refresh();

        expect(
            $deal->stage_id
        )->toBe(
            'qualified'
        );

        expect(
            $deal->stage_label
        )->toBe(
            'Leds qualificado'
        );

        expect(
            $deal->is_closed
        )->toBeFalse();

        expect(
            $deal
                ->companies
                ->pluck(
                    'hubspot_id'
                )
                ->all()
        )->toBe([
            '9258277170',
        ]);

        expect(
            $company
                ->refresh()
                ->company_id
        )->toBeNull();

        expect(
            $event
                ->refresh()
                ->status
        )->toBe(
            'processed'
        );

        Queue::assertNotPushed(
            RefreshCompanyFromHubSpot::class
        );
    }
);

it(
    'keeps the known owner when HubSpot denies owner lookup',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $company =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'company-owner-403',

                    'name' => 'Empresa Owner 403',

                    'owner_name' => 'Responsável já conhecido',

                    'company_id' => null,
                ]);

        $event =
            HubSpotWebhookEvent::query()
                ->create([
                    'event_key' => 'owner-403-event',

                    'subscription_type' => 'object.propertyChange',

                    'object_type' => 'company',

                    'object_type_id' => '0-2',

                    'object_id' => 'company-owner-403',

                    'property_name' => 'notes_last_updated',

                    'status' => 'received',

                    'payload' => [
                        'objectTypeId' => '0-2',

                        'objectId' => 'company-owner-403',

                        'subscriptionType' => 'object.propertyChange',

                        'propertyName' => 'notes_last_updated',
                    ],
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
                        '/crm/v3/objects/companies/company-owner-403'
                    )
                ) {
                    return Http::response([
                        'id' => 'company-owner-403',

                        'createdAt' => '2026-09-01T12:00:00Z',

                        'updatedAt' => '2026-09-25T18:00:00Z',

                        'properties' => [
                            'name' => 'Empresa Owner 403',

                            'domain' => null,

                            'lifecyclestage' => 'lead',

                            'hs_lead_status' => null,

                            'hubspot_owner_id' => 'owner-sem-scope',

                            'city' => null,

                            'state' => null,

                            'phone' => null,

                            'notes_last_contacted' => null,

                            'notes_last_updated' => '2026-09-25T18:00:00Z',

                            'num_contacted_notes' => '1',

                            'num_associated_deals' => '0',
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/crm/v3/owners/owner-sem-scope'
                    )
                ) {
                    return Http::response(
                        [
                            'message' => 'Missing permissions',
                        ],
                        403
                    );
                }

                if (
                    str_contains(
                        $url,
                        '/crm/v4/objects/companies/company-owner-403/associations/'
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

        $handled =
            app(
                HubSpotWebhookMirrorSyncService::class
            )->syncEvent(
                $event
            );

        expect(
            $handled
        )->toBeTrue();

        expect(
            $company
                ->refresh()
                ->owner_name
        )->toBe(
            'Responsável já conhecido'
        );
    }
);
