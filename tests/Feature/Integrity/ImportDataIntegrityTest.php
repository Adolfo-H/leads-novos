<?php

use App\Models\ImportBatch;
use App\Services\CnpjImportService;

it('keeps a long invalid input from breaking the entire import batch', function () {
    $service =
        app(
            CnpjImportService::class
        );

    $valid =
        '11.222.333/0001-81';

    $tooLong =
        str_repeat(
            'ABC123',
            30
        );

    $batch =
        $service
            ->import([
                $tooLong,
                $valid,
            ]);

    expect(
        ImportBatch::query()
            ->count()
    )->toBe(
        1
    );

    expect(
        $batch->total_rows
    )->toBe(
        2
    );

    expect(
        $batch->invalid_rows
    )->toBe(
        1
    );

    expect(
        $batch->valid_rows
    )->toBe(
        1
    );

    $invalid =
        $batch
            ->items
            ->firstWhere(
                'status',
                'invalid'
            );

    expect(
        $invalid
    )->not->toBeNull();

    expect(
        mb_strlen(
            $invalid
                ->raw_cnpj
        )
    )->toBe(
        50
    );

    expect(
        $invalid
            ->normalized_cnpj
    )->toBeNull();

    expect(
        data_get(
            $invalid
                ->metadata,
            'input.raw_truncated'
        )
    )->toBeTrue();

    expect(
        data_get(
            $invalid
                ->metadata,
            'input.raw_length'
        )
    )->toBe(
        mb_strlen(
            $tooLong
        )
    );
});
