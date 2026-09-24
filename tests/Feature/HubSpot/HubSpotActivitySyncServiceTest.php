<?php

use App\Models\Company;
use App\Models\CompanyLeadActivity;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotActivitySyncService;
use Illuminate\Support\Facades\Http;

function hubSpotActivityCompany(
    string $root,
    string $name,
): Company {
    return Company::query()
        ->create([
            'cnpj_root' => $root,

            'corporate_name' => $name,
        ]);
}

function hubSpotActivityEvent(
    string $type,
    string $id,
    string $subscription,
): HubSpotWebhookEvent {
    return HubSpotWebhookEvent::query()
        ->create([
            'event_key' => hash(
                'sha256',
                $type
                .$id
                .$subscription
                .uniqid(
                    '',
                    true
                )
            ),

            'subscription_type' => $subscription,

            'object_type' => $type,

            'object_id' => $id,

            'status' => 'received',

            'payload' => [
                'test' => true,
            ],
        ]);
}

it(
    'creates and later updates a HubSpot note without duplicating it',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $company =
            hubSpotActivityCompany(
                '95111111',
                'Empresa Nota HubSpot'
            );

        /*
         * A mesma URL será consultada duas vezes:
         *
         * 1. criação da observação;
         * 2. edição da mesma observação.
         *
         * A sequence garante que a segunda leitura
         * receba o conteúdo atualizado.
         */
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/notes/note-100*' => Http::sequence()
                ->push([
                    'id' => 'note-100',

                    'createdAt' => '2026-09-24T12:00:00Z',

                    'updatedAt' => '2026-09-24T12:00:00Z',

                    'properties' => [
                        'hs_timestamp' => '2026-09-24T12:00:00Z',

                        'hs_note_body' => '<p>Cliente pediu retorno sexta-feira.</p>',
                    ],
                ])
                ->push([
                    'id' => 'note-100',

                    'createdAt' => '2026-09-24T12:00:00Z',

                    'updatedAt' => '2026-09-24T13:00:00Z',

                    'properties' => [
                        'hs_timestamp' => '2026-09-24T12:00:00Z',

                        'hs_note_body' => '<p>Cliente pediu retorno segunda-feira.</p>',
                    ],
                ]),
        ]);

        $event =
            hubSpotActivityEvent(
                type: 'note',

                id: 'note-100',

                subscription: 'object.creation',
            );

        app(
            HubSpotActivitySyncService::class
        )->syncEvent(
            event: $event,

            companyIds: [
                $company->id,
            ],
        );

        $activity =
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'source_object_id',
                    'note-100'
                )
                ->firstOrFail();

        expect(
            $activity->type
        )->toBe(
            'hubspot_note'
        );

        expect(
            $activity->title
        )->toBe(
            'Observação HubSpot'
        );

        expect(
            $activity->description
        )->toContain(
            'Cliente pediu retorno sexta-feira.'
        );

        expect(
            $activity->is_deleted
        )->toBeFalse();

        /*
         * Mesmo ID no HubSpot.
         *
         * Deve atualizar o registro local,
         * nunca criar uma segunda atividade.
         */
        $updatedEvent =
            hubSpotActivityEvent(
                type: 'note',

                id: 'note-100',

                subscription: 'object.propertyChange',
            );

        app(
            HubSpotActivitySyncService::class
        )->syncEvent(
            event: $updatedEvent,

            companyIds: [
                $company->id,
            ],
        );

        expect(
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'source_object_id',
                    'note-100'
                )
                ->count()
        )->toBe(
            1
        );

        $updatedActivity =
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'source_object_id',
                    'note-100'
                )
                ->firstOrFail();

        expect(
            $updatedActivity->description
        )->toContain(
            'Cliente pediu retorno segunda-feira.'
        );

        expect(
            $updatedActivity
                ->source_updated_at
                ?->utc()
                ->toIso8601String()
        )->toBe(
            '2026-09-24T13:00:00+00:00'
        );
    }
);

it(
    'stores HubSpot calls with commercial details',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $company =
            hubSpotActivityCompany(
                '95222222',
                'Empresa Ligacao HubSpot'
            );

        $event =
            hubSpotActivityEvent(
                type: 'call',
                id: 'call-200',

                subscription: 'object.creation',
            );

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/calls/call-200*' => Http::response([
                'id' => 'call-200',

                'createdAt' => '2026-09-24T14:00:00Z',

                'updatedAt' => '2026-09-24T14:10:00Z',

                'properties' => [
                    'hs_timestamp' => '2026-09-24T14:00:00Z',

                    'hs_call_title' => 'Retorno proposta',

                    'hs_call_body' => 'Falou com Juliana sobre aprovação.',

                    'hs_call_status' => 'COMPLETED',

                    'hs_call_disposition' => 'CONNECTED',

                    'hs_call_duration' => '522000',
                ],
            ]),
        ]);

        app(
            HubSpotActivitySyncService::class
        )->syncEvent(
            event: $event,

            companyIds: [
                $company->id,
            ],
        );

        $activity =
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'source_object_id',
                    'call-200'
                )
                ->firstOrFail();

        expect(
            $activity->title
        )->toBe(
            'Retorno proposta'
        );

        expect(
            $activity->description
        )
            ->toContain(
                'Falou com Juliana'
            )
            ->toContain(
                'Status: COMPLETED'
            )
            ->toContain(
                'Resultado: CONNECTED'
            )
            ->toContain(
                'Duração: 8m 42s'
            );
    }
);

it(
    'hides a locally known HubSpot activity when it is deleted',
    function (): void {
        $company =
            hubSpotActivityCompany(
                '95333333',
                'Empresa Exclusao HubSpot'
            );

        CompanyLeadActivity::query()
            ->create([
                'company_id' => $company->id,

                'type' => 'hubspot_note',

                'source' => 'hubspot',

                'source_object_type' => 'note',

                'source_object_id' => 'note-delete',

                'title' => 'Observação HubSpot',

                'description' => 'Será excluída.',

                'metadata' => [],

                'occurred_at' => now(),

                'source_updated_at' => now(),

                'is_deleted' => false,
            ]);

        $event =
            hubSpotActivityEvent(
                type: 'note',
                id: 'note-delete',

                subscription: 'object.deletion',
            );

        $companyIds =
            app(
                HubSpotActivitySyncService::class
            )->syncEvent(
                event: $event,

                companyIds: [],
            );

        expect(
            $companyIds
        )->toBe([
            $company->id,
        ]);

        $activity =
            CompanyLeadActivity::query()
                ->where(
                    'source_object_id',
                    'note-delete'
                )
                ->firstOrFail();

        expect(
            $activity->is_deleted
        )->toBeTrue();

        expect(
            $company
                ->refresh()
                ->leadActivities
                ->contains(
                    'id',
                    $activity->id
                )
        )->toBeFalse();
    }
);
