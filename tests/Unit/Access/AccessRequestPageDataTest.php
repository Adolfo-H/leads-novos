<?php

use App\Support\Access\AccessRequestPageData;

it('presents the original access request statuses in Brazilian Portuguese', function () {
    expect(AccessRequestPageData::label((object) ['status' => 'pending']))->toBe('Pendente');
    expect(AccessRequestPageData::label((object) ['status' => 'approved']))->toBe('Aprovada');
    expect(AccessRequestPageData::label((object) ['status' => 'rejected']))->toBe('Recusada');
    expect(AccessRequestPageData::label((object) ['status' => 'activated']))->toBe('Ativada');
});

it('recognizes an approved request as activated when accepted', function () {
    $request = (object) [
        'status' => 'approved',
        'accepted_at' => '2026-10-28 10:00:00',
    ];

    expect(AccessRequestPageData::status($request))->toBe('activated');
});

it('reads fields without inventing unavailable registration data', function () {
    $request = (object) [
        'name' => 'Pessoa Exemplo',
        'company' => 'ExportControl',
        'email' => '',
    ];

    expect(AccessRequestPageData::field($request, ['name']))->toBe('Pessoa Exemplo')
        ->and(AccessRequestPageData::field($request, ['company_name', 'company']))->toBe('ExportControl')
        ->and(AccessRequestPageData::field($request, ['email']))->toBe('—');
});
