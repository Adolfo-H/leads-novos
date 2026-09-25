<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\CompanyLeadActivity;
use App\Models\HubSpotActivity;
use App\Models\HubSpotCompany;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotActivitySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it(
    'preserves a HubSpot note before fiscal identification',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $hubSpotCompany =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'hs-company-unmatched',

                    'name' => 'Empresa CRM sem CNPJ',

                    'company_id' => null,
                ]);

        $event =
            HubSpotWebhookEvent::query()
                ->create([
                    'event_key' => 'unmatched-note-event',

                    'subscription_type' => 'object.creation',

                    'object_type' => 'note',

                    'object_type_id' => '0-46',

                    'object_id' => 'note-unmatched',

                    'status' => 'received',

                    'payload' => [
                        'objectTypeId' => '0-46',

                        'objectId' => 'note-unmatched',

                        'subscriptionType' => 'object.creation',
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
                        '/crm/v3/objects/notes/note-unmatched'
                    )
                ) {
                    return Http::response([
                        'id' => 'note-unmatched',

                        'createdAt' => '2026-09-25T17:00:00Z',

                        'updatedAt' => '2026-09-25T17:05:00Z',

                        'properties' => [
                            'hs_timestamp' => '2026-09-25T17:00:00Z',

                            'hs_note_body' => '<p>Cliente pediu retorno.</p>',
                        ],
                    ]);
                }

                if (
                    str_contains(
                        $url,
                        '/notes/note-unmatched/associations/companies'
                    )
                ) {
                    return Http::response([
                        'results' => [
                            [
                                'toObjectId' => 'hs-company-unmatched',
                            ],
                        ],
                    ]);
                }

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

        $activity =
            HubSpotActivity::query()
                ->where(
                    'object_type',
                    'note'
                )
                ->where(
                    'hubspot_id',
                    'note-unmatched'
                )
                ->firstOrFail();

        expect(
            $activity->description
        )->toContain(
            'Cliente pediu retorno.'
        );

        expect(
            $activity
                ->companies
                ->pluck(
                    'hubspot_id'
                )
                ->all()
        )->toBe([
            'hs-company-unmatched',
        ]);

        expect(
            $hubSpotCompany
                ->refresh()
                ->company_id
        )->toBeNull();

        expect(
            CompanyLeadActivity::query()
                ->count()
        )->toBe(0);

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
    'promotes preserved activity after fiscal identification',
    function (): void {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '12345678',

                    'corporate_name' => 'Empresa Fiscal Teste',
                ]);

        $hubSpotCompany =
            HubSpotCompany::query()
                ->create([
                    'hubspot_id' => 'hs-before-link',

                    'name' => 'Empresa HubSpot',

                    'company_id' => null,
                ]);

        $activity =
            HubSpotActivity::query()
                ->create([
                    'hubspot_id' => 'call-before-link',

                    'object_type' => 'call',

                    'title' => 'Ligação comercial',

                    'description' => 'Contato realizado antes do CNPJ.',

                    'occurred_at' => now()->subDay(),

                    'source_updated_at' => now()->subDay(),

                    'is_deleted' => false,

                    'raw_properties' => [
                        'hs_call_status' => 'COMPLETED',
                    ],
                ]);

        $activity
            ->companies()
            ->attach(
                $hubSpotCompany->id
            );

        $count =
            app(
                HubSpotActivitySyncService::class
            )->promoteForCompany(
                hubSpotCompany: $hubSpotCompany,

                company: $company,
            );

        expect(
            $count
        )->toBe(1);

        $projected =
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'source',
                    'hubspot'
                )
                ->where(
                    'source_object_type',
                    'call'
                )
                ->where(
                    'source_object_id',
                    'call-before-link'
                )
                ->firstOrFail();

        expect(
            $projected->type
        )->toBe(
            'hubspot_call'
        );

        expect(
            $projected->description
        )->toContain(
            'antes do CNPJ'
        );
    }
);
