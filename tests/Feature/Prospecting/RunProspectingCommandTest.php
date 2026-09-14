<?php

use App\Jobs\EnrichImportItem;
use App\Models\Company;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('discovers new companies and creates a prospecting batch', function () {
    Queue::fake();

    config([
        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    /*
     * Esta raiz já existe e deve ser
     * eliminada antes da importação.
     */
    Company::query()->create([
        'cnpj_root' => '02916265',

        'corporate_name' => 'JBS S/A',
    ]);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [
                [
                    'cnpj_root' => '02916265',

                    'cnpj' => '02916265000160',

                    'corporate_name' => 'JBS S/A',

                    'state' => 'SP',

                    'matched_cnae' => '4622200',

                    'primary_cnae' => '1011201',

                    'share_capital' => 23631071304.24,

                    'size_code' => '05',

                    'legal_nature_code' => '2046',
                ],
                [
                    'cnpj_root' => '42080238',

                    'cnpj' => '42080238000114',

                    'corporate_name' => 'ARO TTI BRASIL AGRO BUSINESS LTDA',

                    'state' => 'SP',

                    'matched_cnae' => '4622200',

                    'primary_cnae' => '4639701',

                    'share_capital' => 172964347313.00,

                    'size_code' => '05',

                    'legal_nature_code' => '2062',
                ],
            ],

            'count' => 2,

            'limit' => 2,

            'offset' => 0,

            'filters' => [
                'states' => [
                    'SP',
                ],

                'cnaes' => [
                    '4622200',
                ],

                'active_only' => true,
            ],
        ]),
    ]);

    $this
        ->artisan(
            'prospecting:run',
            [
                '--limit' => 2,

                '--execute' => true,
            ]
        )
        ->assertSuccessful();

    $batch =
        ImportBatch::query()
            ->where(
                'source_type',
                'prospecting'
            )
            ->firstOrFail();

    expect(
        $batch->total_rows
    )->toBe(1);

    expect(
        $batch->valid_rows
    )->toBe(1);

    expect(
        data_get(
            $batch->metadata,
            'prospecting.discovered_count'
        )
    )->toBe(2);

    expect(
        data_get(
            $batch->metadata,
            'prospecting.existing_roots_count'
        )
    )->toBe(1);

    expect(
        data_get(
            $batch->metadata,
            'prospecting.new_candidates_count'
        )
    )->toBe(1);

    expect(
        $batch
            ->items()
            ->firstOrFail()
            ->normalized_cnpj
    )->toBe(
        '42080238000114'
    );

    Queue::assertPushed(
        EnrichImportItem::class,
        1
    );
});

it('only previews prospects when execute is not provided', function () {
    Queue::fake();

    config([
        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [
                [
                    'cnpj_root' => '42080238',

                    'cnpj' => '42080238000114',

                    'corporate_name' => 'ARO TTI BRASIL AGRO BUSINESS LTDA',

                    'state' => 'SP',

                    'matched_cnae' => '4622200',

                    'primary_cnae' => '4639701',

                    'share_capital' => 1000000,

                    'size_code' => '05',

                    'legal_nature_code' => '2062',
                ],
            ],

            'count' => 1,

            'limit' => 1,

            'offset' => 0,

            'filters' => [],
        ]),
    ]);

    $this
        ->artisan(
            'prospecting:run',
            [
                '--limit' => 1,
            ]
        )
        ->assertSuccessful();

    expect(
        ImportBatch::query()
            ->where(
                'source_type',
                'prospecting'
            )
            ->count()
    )->toBe(0);

    Queue::assertNothingPushed();
});
