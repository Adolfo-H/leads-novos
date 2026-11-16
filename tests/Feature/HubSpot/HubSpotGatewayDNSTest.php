<?php

it('keeps the public gateway restricted and resolves Laravel using Docker DNS at request time', function (): void {
    $config = file_get_contents(base_path('docker/campaign-unsubscribe/nginx.conf'));

    expect($config)->toBeString()
        ->toContain('resolver 127.0.0.11 valid=10s ipv6=off;')
        ->toContain('resolver_timeout 3s;')
        ->toContain('set $ec_laravel_upstream laravel.test:80;')
        ->toContain('location = /webhooks/hubspot')
        ->toContain('location ~ "^/email/descadastro/')
        ->toContain('location / { return 404; }')
        ->toContain('proxy_pass http://$ec_laravel_upstream$request_uri;');

    expect(substr_count($config, 'proxy_pass http://$ec_laravel_upstream$request_uri;'))->toBe(2);
    expect($config)->not->toContain('proxy_pass http://laravel.test:80;');
});
