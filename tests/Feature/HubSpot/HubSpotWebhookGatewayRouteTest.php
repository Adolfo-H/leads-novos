<?php

it('identifies the loaded webhook route without exposing the application', function (): void {
    $config = file_get_contents(base_path('docker/campaign-unsubscribe/nginx.conf'));
    expect($config)->not->toBeFalse();

    expect($config)
        ->toContain('location = /webhooks/hubspot')
        ->toContain('add_header X-EC-Webhook-Route "hubspot-v382" always;')
        ->toContain('if ($request_method = HEAD) { return 405; }')
        ->toContain('if ($request_method !~ ^(POST|HEAD)$) { return 405; }')
        ->toContain('proxy_intercept_errors off;')
        ->toContain('resolver 127.0.0.11 valid=10s ipv6=off;')
        ->toContain('set $ec_laravel_upstream laravel.test:80;')
        ->toContain('proxy_pass http://$ec_laravel_upstream$request_uri;')
        ->toContain('location / { return 404; }')
        ->toContain('location ~ "^/email/descadastro/');

    expect(substr_count($config, 'location = /webhooks/hubspot'))->toBe(1);
});
