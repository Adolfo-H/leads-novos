<?php

namespace App\Http\Middleware;

use App\Services\HubSpotWebhookSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyHubSpotWebhookSignature
{
    public function __construct(
        private readonly HubSpotWebhookSignatureVerifier $verifier,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        if (
            ! $this->verifier
                ->valid(
                    $request
                )
        ) {
            return response()->json(
                [
                    'message' => 'Invalid HubSpot webhook signature.',
                ],
                401
            );
        }

        return $next(
            $request
        );
    }
}
