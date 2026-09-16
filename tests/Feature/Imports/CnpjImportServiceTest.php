<?php

use App\Services\CnpjImportService;
use App\Support\Cnpj;

it('classifies imported cnpjs', function () {
    $service = app(
        CnpjImportService::class
    );

    $base1 = '112223330002';

    $cnpj1 =
        $base1
        .Cnpj::calculateCheckDigits(
            $base1
        );

    $base2 = '112223330003';

    $cnpj2 =
        $base2
        .Cnpj::calculateCheckDigits(
            $base2
        );

    $batch = $service->import([
        $cnpj1,
        $cnpj2,
        $cnpj1,
        '00.000.000/0000-00',
    ]);

    expect(
        $batch->total_rows
    )->toBe(4);

    expect(
        $batch->valid_rows
    )->toBe(2);

    expect(
        $batch->duplicate_rows
    )->toBe(1);

    expect(
        $batch->invalid_rows
    )->toBe(1);

    expect(
        $batch
            ->items()
            ->where(
                'status',
                'ready'
            )
            ->count()
    )->toBe(2);
});

it('parses pasted cnpj lists', function () {
    $service = app(
        CnpjImportService::class
    );

    $values = $service->parseText(
        "11.222.333/0001-81\n"
        .'22.333.444/0001-00;'
        .'33.444.555/0001-00'
    );

    expect($values)
        ->toHaveCount(3);
});

it('stores the cnpj root for valid imported items', function () {
    $service = app(
        CnpjImportService::class
    );

    $base =
        '112223330004';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch =
        $service->import([
            $cnpj,
            '00.000.000/0000-00',
        ]);

    $validItem =
        $batch
            ->items()
            ->where(
                'status',
                'ready'
            )
            ->firstOrFail();

    $invalidItem =
        $batch
            ->items()
            ->where(
                'status',
                'invalid'
            )
            ->firstOrFail();

    expect(
        $validItem->cnpj_root
    )->toBe(
        '11222333'
    );

    expect(
        $invalidItem->cnpj_root
    )->toBeNull();
});
