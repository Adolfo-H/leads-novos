<?php

it('declares the HubSpot write and owner scopes required by manual opportunities', function () {
    $path =
        base_path(
            'hubspot-app/src/app/app-hsmeta.json'
        );

    $data =
        json_decode(
            file_get_contents(
                $path
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

    $scopes =
        data_get(
            $data,
            'config.auth.requiredScopes',
            []
        );

    expect(
        $scopes
    )->toContain(
        'crm.objects.companies.write'
    );

    expect(
        $scopes
    )->toContain(
        'crm.objects.contacts.write'
    );

    expect(
        $scopes
    )->toContain(
        'crm.objects.deals.write'
    );

    expect(
        $scopes
    )->toContain(
        'crm.objects.owners.read'
    );
});
