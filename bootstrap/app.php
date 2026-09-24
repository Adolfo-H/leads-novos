<?php

use App\Http\Middleware\EnsureCommercialCompanyAccess;
use App\Http\Middleware\EnsureCommercialManager;
use App\Http\Middleware\VerifyHubSpotWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(
            except: [
                'webhooks/hubspot',
            ]
        );

        $middleware->alias([
            'commercial.manager' => EnsureCommercialManager::class,

            'commercial.company-access' => EnsureCommercialCompanyAccess::class,

            'hubspot.signature' => VerifyHubSpotWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
