<?php

use Illuminate\Support\Facades\Route;

it('permits refreshing the access request admin page without consuming the write throttle', function (): void {
    $route = Route::getRoutes()->getByName('access.requests.index');

    expect($route)->not->toBeNull();
    expect($route->methods())->toContain('GET');
    expect($route->gatherMiddleware())->toContain('auth', 'verified', 'commercial.manager');
    expect($route->gatherMiddleware())->not->toContain('throttle:20,1');
});

it('keeps authorization and write throttling on sensitive access actions', function (): void {
    foreach (['approve', 'reject', 'resend', 'resend-notification', 'revoke'] as $action) {
        $route = Route::getRoutes()->getByName('access.requests.'.$action);

        expect($route)->not->toBeNull();
        expect($route->methods())->toContain('POST');
        expect($route->gatherMiddleware())->toContain('auth', 'verified', 'commercial.manager', 'throttle:20,1');
    }
});

it('preserves the rate limit on public access request submissions', function (): void {
    $route = Route::getRoutes()->getByName('access.request.store');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('guest', 'throttle:8,1');
});
