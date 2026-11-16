<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\ImportBatch;
use App\Services\CnpjImportService;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;

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

it('uses a batched establishment lookup for a modest import', function () {
    $cnpjs = [];

    foreach (range(1, 40) as $index) {
        $base = str_pad(
            (string) (223334440000 + $index),
            12,
            '0',
            STR_PAD_LEFT
        );

        $cnpjs[] =
            $base
            .Cnpj::calculateCheckDigits(
                $base
            );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $batch = app(
        CnpjImportService::class
    )->import($cnpjs);

    $lookupQueries = collect(
        DB::getQueryLog()
    )
        ->filter(
            static function (
                array $query
            ): bool {
                $sql = mb_strtolower(
                    ltrim(
                        $query['query']
                    )
                );

                return str_starts_with(
                    $sql,
                    'select'
                )
                    && str_contains(
                        $sql,
                        'establishments'
                    );
            }
        )
        ->count();

    DB::disableQueryLog();

    expect($batch->total_rows)->toBe(40);
    expect($lookupQueries)->toBe(1);
});

it('preserves existing and duplicate classification in a batched import', function () {
    $base = '223334440001';
    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $company = Company::query()->create([
        'cnpj_root' => substr($cnpj, 0, 8),
        'corporate_name' => 'Empresa Importada Existente',
    ]);

    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => $cnpj,
        'order_number' => substr($cnpj, 8, 4),
        'check_digits' => substr($cnpj, 12, 2),
        'type' => 'matrix',
    ]);

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
        $cnpj,
    ]);

    $items = $batch->items()
        ->orderBy('row_number')
        ->get();

    expect($batch->existing_rows)->toBe(1);
    expect($batch->duplicate_rows)->toBe(1);
    expect($batch->valid_rows)->toBe(0);
    expect($items[0]->status)->toBe('existing');
    expect($items[0]->company_id)->toBe($company->id);
    expect($items[1]->status)->toBe('duplicate');
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

it('rejects an import above its configured row limit before creating a batch', function () {
    config([
        'prospector.imports.max_rows' => 1,
    ]);

    $service = app(
        CnpjImportService::class
    );

    expect(
        fn () => $service->import([
            'invalid-1',
            'invalid-2',
        ])
    )->toThrow(
        InvalidArgumentException::class,
        'O lote excede o limite de 1 registros por importação.'
    );

    expect(
        ImportBatch::query()->count()
    )->toBe(0);
});
