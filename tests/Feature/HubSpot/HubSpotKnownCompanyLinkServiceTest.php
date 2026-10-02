<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Services\HubSpotKnownCompanyLinkService;

it('links a HubSpot company created by Prospector to its fiscal company', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '59585193',

            'corporate_name' => 'AGROPECUARIA TERRA FERTIL S.A.',
        ]);

    $company
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'hub-company-123',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'metadata' => [],
        ]);

    $mirror =
        HubSpotCompany::query()->create([
            'hubspot_id' => 'hub-company-123',

            'name' => 'AGROPECUARIA TERRA FERTIL S.A.',
        ]);

    $linked =
        app(
            HubSpotKnownCompanyLinkService::class
        )->link(
            $mirror
        );

    expect(
        $linked
    )->toBeTrue();

    $mirror->refresh();

    expect(
        $mirror->company_id
    )->toBe(
        $company->id
    );

    expect(
        $mirror->matched_cnpj_root
    )->toBe(
        '59585193'
    );

    expect(
        $mirror->match_source
    )->toBe(
        'prospector_created'
    );

    expect(
        $mirror->hasTrustedFiscalLink()
    )->toBeTrue();
});

it('does not overwrite a trusted HubSpot fiscal link to another company', function () {
    $original =
        Company::query()->create([
            'cnpj_root' => '11111111',

            'corporate_name' => 'EMPRESA ORIGINAL',
        ]);

    $prospector =
        Company::query()->create([
            'cnpj_root' => '22222222',

            'corporate_name' => 'EMPRESA PROSPECTOR',
        ]);

    $prospector
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'hub-company-conflict',

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'metadata' => [],
        ]);

    $mirror =
        HubSpotCompany::query()->create([
            'company_id' => $original->id,

            'matched_cnpj_root' => '11111111',

            'matched_company_name' => 'EMPRESA ORIGINAL',

            'match_source' => 'manual_manager',

            'hubspot_id' => 'hub-company-conflict',

            'name' => 'EMPRESA ORIGINAL',
        ]);

    $linked =
        app(
            HubSpotKnownCompanyLinkService::class
        )->link(
            $mirror
        );

    expect(
        $linked
    )->toBeFalse();

    expect(
        $mirror
            ->refresh()
            ->company_id
    )->toBe(
        $original->id
    );
});
