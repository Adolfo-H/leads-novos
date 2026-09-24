<?php

namespace App\Services;

use Illuminate\Http\Request;

final class HubSpotWebhookSignatureVerifier
{
    private const MAX_AGE_MS =
        300000;

    public function valid(
        Request $request
    ): bool {
        $signature =
            trim(
                (string)
                $request->header(
                    'X-HubSpot-Signature-v3',
                    ''
                )
            );

        $timestamp =
            trim(
                (string)
                $request->header(
                    'X-HubSpot-Request-Timestamp',
                    ''
                )
            );

        if (
            $signature === ''
            || $timestamp === ''
            || ! ctype_digit(
                $timestamp
            )
        ) {
            return false;
        }

        $timestampMs =
            (int) $timestamp;

        $nowMs =
            (int) floor(
                microtime(true)
                * 1000
            );

        if (
            abs(
                $nowMs
                - $timestampMs
            )
            > self::MAX_AGE_MS
        ) {
            return false;
        }

        $secret =
            trim(
                (string) config(
                    'services.hubspot.webhook_secret',
                    ''
                )
            );

        if ($secret === '') {
            return false;
        }

        /*
         * Em produção, se houver proxy HTTPS,
         * HUBSPOT_WEBHOOK_PUBLIC_URL evita
         * divergência entre http:// interno
         * e https:// público.
         */
        $configuredUrl =
            trim(
                (string) config(
                    'services.hubspot.webhook_public_url',
                    ''
                )
            );

        $uri =
            $configuredUrl !== ''
                ? $configuredUrl
                : $request->fullUrl();

        $uri =
            $this->decodeSignedUri(
                $uri
            );

        $source =
            mb_strtoupper(
                $request->method()
            )
            .$uri
            .$request->getContent()
            .$timestamp;

        $expected =
            base64_encode(
                hash_hmac(
                    'sha256',
                    $source,
                    $secret,
                    true
                )
            );

        return hash_equals(
            $expected,
            $signature
        );
    }

    private function decodeSignedUri(
        string $uri
    ): string {
        $encoded = [
            '%3A',
            '%2F',
            '%3F',
            '%40',
            '%21',
            '%24',
            '%27',
            '%28',
            '%29',
            '%2A',
            '%2C',
            '%3B',
        ];

        $decoded = [
            ':',
            '/',
            '?',
            '@',
            '!',
            '$',
            "'",
            '(',
            ')',
            '*',
            ',',
            ';',
        ];

        return str_ireplace(
            $encoded,
            $decoded,
            $uri
        );
    }
}
