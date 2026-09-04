<?php

use App\Models\Company;
use App\Services\CrmReprospectingPolicyService;

function reprospectCrm(
    ?string $lastContactedAt,
    array $deals = [],
) {
    $company =
        Company::query()->create([
            'cnpj_root' => fake()
                ->unique()
                ->numerify('########'),

            'corporate_name' => 'Empresa Reprospecção '
                .fake()->uuid(),
        ]);

    return $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'prospected',

            'contacted_count' => 1,

            'associated_deals_count' => count($deals),

            'last_contacted_at' => $lastContactedAt,

            'metadata' => [
                'deals' => $deals,
            ],

            'checked_at' => now(),
        ]);
}

it('blocks recently prospected companies', function () {
    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);

    $crm =
        reprospectCrm(
            now()
                ->subDays(30)
                ->toIso8601String()
        );

    $result = app(
        CrmReprospectingPolicyService::class
    )->evaluate(
        $crm
    );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'cooldown_active'
    );
});

it('allows old prospected companies to return', function () {
    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);

    $crm =
        reprospectCrm(
            now()
                ->subDays(200)
                ->toIso8601String()
        );

    $result = app(
        CrmReprospectingPolicyService::class
    )->evaluate(
        $crm
    );

    expect(
        $result['eligible']
    )->toBeTrue();

    expect(
        $result['reason']
    )->toBe(
        'cooldown_elapsed'
    );
});

it('uses the newest commercial activity date', function () {
    config([
        'prospector.crm.reprospecting_after_days' => 180,
    ]);

    $crm =
        reprospectCrm(
            now()
                ->subDays(250)
                ->toIso8601String(),
            [
                [
                    'id' => 'deal-recent',

                    'closed_at' => now()
                        ->subDays(30)
                        ->toIso8601String(),
                ],
            ]
        );

    $result = app(
        CrmReprospectingPolicyService::class
    )->evaluate(
        $crm
    );

    /*
     * O contato é antigo, mas o negócio foi
     * encerrado há apenas 30 dias.
     */
    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'cooldown_active'
    );
});

it('blocks automatic reprospecting when activity date is unknown', function () {
    $crm =
        reprospectCrm(
            null
        );

    $result = app(
        CrmReprospectingPolicyService::class
    )->evaluate(
        $crm
    );

    expect(
        $result['eligible']
    )->toBeFalse();

    expect(
        $result['reason']
    )->toBe(
        'activity_date_unknown'
    );
});
