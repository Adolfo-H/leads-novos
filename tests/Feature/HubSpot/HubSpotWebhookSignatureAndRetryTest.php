<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use Illuminate\Support\Facades\Queue;

function package12WebhookSignature(
    string $body,
    string $timestamp,
    string $url,
    string $secret,
): string {
    return base64_encode(hash_hmac(
        'sha256',
        'POST'.$url.$body.$timestamp,
        $secret,
        true
    ));
}

it('rejects a correctly signed but expired HubSpot webhook before persistence', function () {
    $secret = 'synthetic-webhook-secret';
    $url = 'http://localhost/webhooks/hubspot';
    $timestamp = (string) now()->subMinutes(10)->getTimestampMs();
    $body = json_encode([
        [
            'appId' => 111,
            'eventId' => 222,
            'subscriptionId' => 333,
            'portalId' => 444,
            'occurredAt' => (int) $timestamp,
            'subscriptionType' => 'object.propertyChange',
            'objectTypeId' => '0-3',
            'objectId' => 555,
            'propertyName' => 'dealstage',
            'propertyValue' => 'closedwon',
        ],
    ], JSON_UNESCAPED_SLASHES);

    config([
        'services.hubspot.webhook_secret' => $secret,
        'services.hubspot.webhook_public_url' => $url,
    ]);

    $this->call(
        'POST',
        '/webhooks/hubspot',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => package12WebhookSignature(
                $body,
                $timestamp,
                $url,
                $secret
            ),
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => $timestamp,
        ],
        $body
    )->assertUnauthorized();

    $this->assertDatabaseCount('hubspot_webhook_events', 0);
});

it('does not allow a legacy configuration flag to bypass webhook signature checks', function () {
    config([
        'services.hubspot.webhook_secret' => 'synthetic-webhook-secret',
        'services.hubspot.webhook_verify_signature' => false,
    ]);

    $this->call(
        'POST',
        '/webhooks/hubspot',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '[]'
    )->assertUnauthorized();

    $this->assertDatabaseCount('hubspot_webhook_events', 0);
});

it('does not enqueue an identical webhook delivery more than once', function () {
    Queue::fake();

    $secret = 'synthetic-webhook-secret';
    $url = 'http://localhost/webhooks/hubspot';
    $timestamp = (string) now()->getTimestampMs();
    $body = json_encode([
        [
            'appId' => 111,
            'eventId' => 223,
            'subscriptionId' => 333,
            'portalId' => 444,
            'occurredAt' => (int) $timestamp,
            'subscriptionType' => 'object.propertyChange',
            'objectTypeId' => '0-3',
            'objectId' => 556,
            'propertyName' => 'dealstage',
            'propertyValue' => 'closedwon',
        ],
    ], JSON_UNESCAPED_SLASHES);

    config([
        'services.hubspot.webhook_secret' => $secret,
        'services.hubspot.webhook_public_url' => $url,
    ]);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUBSPOT_SIGNATURE_V3' => package12WebhookSignature(
            $body,
            $timestamp,
            $url,
            $secret
        ),
        'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => $timestamp,
    ];

    foreach ([1, 2] as $_) {
        $this->call('POST', '/webhooks/hubspot', [], [], [], $server, $body)
            ->assertAccepted();
    }

    $this->assertDatabaseCount('hubspot_webhook_events', 1);
    Queue::assertPushed(ProcessHubSpotWebhookEvent::class, 1);
});

it('keeps the public unsubscribe gateway from proxying the HubSpot webhook', function () {
    $configuration = file_get_contents(
        base_path('docker/campaign-unsubscribe/nginx.conf')
    );

    expect($configuration)->toBeString()
        ->and($configuration)->toContain(
            'location ~ "^/email/descadastro/'
        )
        ->and($configuration)->toContain('location / { return 404; }')
        ->and(preg_match(
            '/location\s+[^{}]*\/webhooks\/hubspot[^{}]*\{[^}]*proxy_pass/s',
            $configuration
        ))->toBe(0);
});
