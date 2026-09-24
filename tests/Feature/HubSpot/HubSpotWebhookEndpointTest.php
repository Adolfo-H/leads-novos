<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use Illuminate\Support\Facades\Queue;

function hubSpotWebhookSignature(
    string $body,
    string $timestamp,
    string $url,
    string $secret,
): string {
    $source =
        'POST'
        .$url
        .$body
        .$timestamp;

    return base64_encode(
        hash_hmac(
            'sha256',
            $source,
            $secret,
            true
        )
    );
}

it(
    'accepts a signed HubSpot webhook and queues it once',
    function (): void {
        Queue::fake();

        $secret =
            'hubspot-test-secret';

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

        $payload = [
            [
                'appId' => 100,

                'eventId' => 200,

                'subscriptionId' => 300,

                'portalId' => 400,

                'occurredAt' => (int) $timestamp,

                'subscriptionType' => 'object.propertyChange',

                'objectTypeId' => '0-3',

                'objectId' => 500,

                'propertyName' => 'dealstage',

                'propertyValue' => 'closedwon',
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
            hubSpotWebhookSignature(
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
                'accepted' => 0,

                'duplicates' => 1,

                'invalid' => 0,
            ]);

        $this->assertDatabaseCount(
            'hubspot_webhook_events',
            1
        );

        Queue::assertPushed(
            ProcessHubSpotWebhookEvent::class,
            1
        );
    }
);

it(
    'rejects a HubSpot webhook with an invalid signature',
    function (): void {
        Queue::fake();

        config([
            'services.hubspot.webhook_secret' => 'secret',

            'services.hubspot.webhook_public_url' => 'http://localhost/webhooks/hubspot',

            'services.hubspot.webhook_verify_signature' => true,
        ]);

        $body =
            json_encode([
                [
                    'subscriptionType' => 'object.creation',

                    'objectTypeId' => '0-48',

                    'objectId' => 123,
                ],
            ]);

        expect(
            $body
        )->toBeString();

        $this
            ->call(
                'POST',
                '/webhooks/hubspot',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',

                    'HTTP_X_HUBSPOT_SIGNATURE_V3' => 'invalid',

                    'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string)
                        now()
                            ->getTimestampMs(),
                ],
                $body
            )
            ->assertStatus(
                401
            );

        $this->assertDatabaseCount(
            'hubspot_webhook_events',
            0
        );

        Queue::assertNothingPushed();
    }
);
