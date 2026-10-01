<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use Illuminate\Support\Facades\Queue;

function phaseFourHubSpotSignature(
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

it('uses a HubSpot retry to recover a previously failed event', function () {
    Queue::fake();

    $secret =
        'phase-four-hubspot-secret';

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

            'eventId' => 44001,

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
        phaseFourHubSpotSignature(
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

    /*
     * Primeiro recebimento.
     */
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

            'queued' => 1,

            'requeued' => 0,

            'pending' => 0,
        ]);

    $event =
        HubSpotWebhookEvent::query()
            ->firstOrFail();

    expect(
        $event->status
    )->toBe(
        'queued'
    );

    /*
     * Simula que o job esgotou retries.
     */
    $event->forceFill([
        'status' => 'failed',

        'attempts' => 5,

        'error' => 'Falha temporária de teste.',
    ])->save();

    /*
     * HubSpot envia exatamente o mesmo
     * evento novamente.
     *
     * Antes:
     * "duplicado" e fim.
     *
     * Agora:
     * a duplicata recupera o evento falhado.
     */
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

            'duplicates' => 1,

            'requeued' => 1,
        ]);

    expect(
        $event
            ->refresh()
            ->status
    )->toBe(
        'queued'
    );

    Queue::assertPushed(
        ProcessHubSpotWebhookEvent::class,
        2
    );
});
