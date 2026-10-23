<?php

use Illuminate\Support\Facades\Route;

it('exposes only the signed HubSpot POST endpoint through the unsubscribe gateway', function (): void {
    $nginx = file_get_contents(base_path('docker/campaign-unsubscribe/nginx.conf'));

    expect($nginx)
        ->not->toBeFalse()
        ->toContain('location = /webhooks/hubspot')
        ->toContain('EC_HUBSPOT_GATEWAY_V381_START')
        ->toContain('if ($request_method !~ ^(POST|HEAD)$) { return 405; }')
        ->toContain('proxy_pass http://laravel.test:80;')
        ->toContain('location / { return 404; }')
        ->toContain('location ~ "^/email/descadastro/')
        ->toContain('proxy_intercept_errors off;');

    expect(substr_count((string) $nginx, 'location = /webhooks/hubspot'))
        ->toBe(1);

    expect(Route::has('webhooks.hubspot'))->toBeTrue();

    $route = Route::getRoutes()->getByName('webhooks.hubspot');
    expect($route)->not->toBeNull();

    if ($route !== null) {
        expect($route->methods())->toContain('POST');
    }
});
