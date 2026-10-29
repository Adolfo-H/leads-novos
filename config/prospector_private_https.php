<?php

// EC_A242_TRUSTED_PRIVATE_PROXY: válido com php artisan config:cache.
// Só permite IPs explícitos, nunca CIDRs amplos nem wildcard '*'.
$raw = (string) env('PROSPECTOR_TRUSTED_PROXIES', '');

return [
    'trusted_proxies' => array_values(array_filter(
        array_map('trim', explode(',', $raw)),
        static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false,
    )),
];
