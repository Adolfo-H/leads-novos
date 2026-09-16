<?php

it('documents the HubSpot lead settings exactly once', function () {
    $contents =
        file_get_contents(
            base_path('.env.example')
        );

    expect(
        $contents
    )->not->toBeFalse();

    $keys = [
        'HUBSPOT_ACCESS_TOKEN',
        'HUBSPOT_BASE_URL',
        'HUBSPOT_PORTAL_ID',
        'HUBSPOT_LEAD_SYNC_ENABLED',
        'HUBSPOT_LEAD_MIN_SCORE',
        'HUBSPOT_LEAD_PIPELINE',
        'HUBSPOT_LEAD_INITIAL_STAGE',
        'HUBSPOT_LEAD_DISCARDED_STAGES',
        'APIBRASIL_BEARER_TOKEN',
        'APIBRASIL_BASE_URL',
        'APIBRASIL_CNPJ_TYPE',
        'BRASILAPI_BASE_URL',
    ];

    foreach ($keys as $key) {
        expect(
            substr_count(
                (string) $contents,
                $key.'='
            )
        )->toBe(
            1,
            $key.' deve aparecer exatamente uma vez.'
        );
    }
});
