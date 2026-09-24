<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotWebhookEventContextService;
use Illuminate\Support\Facades\Queue;

function hubSpotAssociationSignature(
    string $body,
    string $timestamp,
    string $url,
    string $secret,
): string {
    return base64_encode(
        hash_hmac(
            'sha256',
            'POST'
            .$url
            .$body
            .$timestamp,
            $secret,
            true
        )
    );
}

it(
    'accepts a generic HubSpot association change payload',
    function (): void {
        Queue::fake();

        $secret =
            'hubspot-association-secret';

        $url =
            'http://localhost/webhooks/hubspot';

        config([
            'services.hubspot.webhook_secret' => $secret,

            'services.hubspot.webhook_public_url' => $url,

            'services.hubspot.webhook_verify_signature' => true,
        ]);

        $timestamp =
            (string)
            now()
                ->getTimestampMs();

        /*
         * Nota associada a empresa.
         *
         * Propositalmente não enviamos
         * objectId/objectTypeId.
         *
         * O sistema precisa entender o
         * evento através de from/to.
         */
        $payload = [
            [
                'appId' => 100,

                'eventId' => 9001,

                'subscriptionId' => 300,

                'portalId' => 400,

                'occurredAt' => (int) $timestamp,

                'subscriptionType' => 'object.associationChange',

                'fromObjectTypeId' => '0-46',

                'fromObjectId' => '7001',

                'toObjectTypeId' => '0-2',

                'toObjectId' => '8001',

                'associationTypeId' => 190,

                'associationCategory' => 'HUBSPOT_DEFINED',

                'associationRemoved' => false,
            ],
        ];

        $body =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
            );

        expect(
            $body
        )->toBeString();

        $signature =
            hubSpotAssociationSignature(
                body: $body,

                timestamp: $timestamp,

                url: $url,

                secret: $secret,
            );

        $server = [
            'CONTENT_TYPE' => 'application/json',

            'HTTP_X_HUBSPOT_SIGNATURE_V3' => $signature,

            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => $timestamp,
        ];

        $this
            ->call(
                'POST',
                '/webhooks/hubspot',
                [],
                [],
                [],
                $server,
                $body
            )
            ->assertStatus(
                202
            )
            ->assertJson([
                'accepted' => 1,

                'duplicates' => 0,

                'invalid' => 0,
            ]);

        $event =
            HubSpotWebhookEvent::query()
                ->latest(
                    'id'
                )
                ->firstOrFail();

        expect(
            $event->subscription_type
        )->toBe(
            'object.associationChange'
        );

        expect(
            $event->object_type
        )->toBe(
            'note'
        );

        expect(
            $event->object_type_id
        )->toBe(
            '0-46'
        );

        expect(
            $event->object_id
        )->toBe(
            '7001'
        );

        $references =
            app(
                HubSpotWebhookEventContextService::class
            )->references(
                $event
            );

        expect(
            $references
        )->toContain(
            [
                'type' => 'note',

                'id' => '7001',
            ]
        );

        expect(
            $references
        )->toContain(
            [
                'type' => 'company',

                'id' => '8001',
            ]
        );

        Queue::assertPushed(
            ProcessHubSpotWebhookEvent::class,
            1
        );
    }
);
