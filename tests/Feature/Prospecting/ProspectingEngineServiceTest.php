<?php

use App\Jobs\EnrichImportItem;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Services\ProspectingEngineService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('removes companies already known by the prospecting engine', function () {
    config([
        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    Company::query()->create([
        'cnpj_root' => '75904383',

        'corporate_name' => 'COAMO AGROINDUSTRIAL COOPERATIVA',
    ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'processing',
        ]);

    $batch->items()->create([
        'row_number' => 1,

        'raw_cnpj' => '84046101005233',

        'normalized_cnpj' => '84046101005233',

        'cnpj_root' => '84046101',

        'status' => 'queued',
    ]);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [
                [
                    'cnpj_root' => '75904383',

                    'cnpj' => '75904383024144',

                    'corporate_name' => 'COAMO AGROINDUSTRIAL COOPERATIVA',

                    'state' => 'MS',

                    'matched_cnae' => '0115600',

                    'cnae_match_type' => 'primary',

                    'primary_cnae' => '0115600',

                    'share_capital' => 303060454,

                    'size_code' => '05',

                    'legal_nature_code' => '2143',

                    'active_establishments' => 231,

                    'active_states' => 3,

                    'discovery_score' => 100,
                ],
                [
                    'cnpj_root' => '84046101',

                    'cnpj' => '84046101005233',

                    'corporate_name' => 'BUNGE ALIMENTOS S/A',

                    'state' => 'MT',

                    'matched_cnae' => '4632001',

                    'cnae_match_type' => 'primary',

                    'primary_cnae' => '4632001',

                    'share_capital' => 9489807206.72,

                    'size_code' => '05',

                    'legal_nature_code' => '2054',

                    'active_establishments' => 194,

                    'active_states' => 20,

                    'discovery_score' => 100,
                ],
                [
                    'cnpj_root' => '06315338',

                    'cnpj' => '06315338003134',

                    'corporate_name' => 'COFCO INTERNATIONAL BRASIL S.A.',

                    'state' => 'MT',

                    'matched_cnae' => '4622200',

                    'cnae_match_type' => 'primary',

                    'primary_cnae' => '4622200',

                    'share_capital' => 7011251666.72,

                    'size_code' => '05',

                    'legal_nature_code' => '2054',

                    'active_establishments' => 129,

                    'active_states' => 12,

                    'discovery_score' => 100,
                ],
            ],

            'count' => 3,
            'limit' => 50,
            'offset' => 0,

            'filters' => [
                'states' => [
                    'MS',
                    'MT',
                ],
            ],
        ]),
    ]);

    $preview =
        app(
            ProspectingEngineService::class
        )->preview(
            limit: 2,
            states: [
                'MS',
                'MT',
            ],
            cnaes: [
                '0115600',
                '4632001',
                '4622200',
            ],
        );

    expect(
        $preview[
            'discovered_count'
        ]
    )->toBe(3);

    expect(
        $preview[
            'known_count'
        ]
    )->toBe(2);

    expect(
        $preview[
            'new_count'
        ]
    )->toBe(1);

    expect(
        $preview[
            'items'
        ][0][
            'cnpj_root'
        ]
    )->toBe(
        '06315338'
    );
});

it('creates and dispatches a prospecting batch', function () {
    Queue::fake();

    config([
        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [
                [
                    'cnpj_root' => '06315338',

                    'cnpj' => '06315338003134',

                    'corporate_name' => 'COFCO INTERNATIONAL BRASIL S.A.',

                    'state' => 'MT',

                    'matched_cnae' => '4622200',

                    'cnae_match_type' => 'primary',

                    'primary_cnae' => '4622200',

                    'share_capital' => 7011251666.72,

                    'size_code' => '05',

                    'legal_nature_code' => '2054',

                    'active_establishments' => 129,

                    'active_states' => 12,

                    'discovery_score' => 100,
                ],
            ],

            'count' => 1,
            'limit' => 50,
            'offset' => 0,

            'filters' => [
                'states' => [
                    'MT',
                ],

                'cnaes' => [
                    '4622200',
                ],
            ],
        ]),
    ]);

    $result =
        app(
            ProspectingEngineService::class
        )->execute(
            limit: 1,
            states: [
                'MT',
            ],
            cnaes: [
                '4622200',
            ],
        );

    expect(
        $result[
            'batch'
        ]
    )->not->toBeNull();

    expect(
        $result[
            'dispatched'
        ]
    )->toBe(1);

    expect(
        $result[
            'batch'
        ]?->source_type
    )->toBe(
        'prospecting'
    );

    expect(
        $result[
            'batch'
        ]?->total_rows
    )->toBe(1);

    expect(
        data_get(
            $result[
                'batch'
            ]?->metadata,
            'prospecting.new_candidates_count'
        )
    )->toBe(1);

    Queue::assertPushed(
        EnrichImportItem::class,
        1
    );
});

it('continues searching when the first page contains only known companies', function () {
    config([
        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    $knownItems = [];

    foreach (range(1, 50) as $index) {
        $root = str_pad(
            (string) (10000000 + $index),
            8,
            '0',
            STR_PAD_LEFT
        );

        Company::query()->create([
            'cnpj_root' => $root,
            'corporate_name' => 'EMPRESA CONHECIDA '.$index,
        ]);

        $knownItems[] = [
            'cnpj_root' => $root,
            'cnpj' => $root.'000100',
            'corporate_name' => 'EMPRESA CONHECIDA '.$index,
            'state' => 'MT',
            'matched_cnae' => '4622200',
            'primary_cnae' => '4622200',
            'share_capital' => 1000000,
            'size_code' => '05',
            'legal_nature_code' => '2062',
            'cnae_match_type' => 'primary',
            'active_establishments' => 1,
            'active_states' => 1,
            'discovery_score' => 80,
        ];
    }

    $newItem = [
        'cnpj_root' => '99999999',
        'cnpj' => '99999999000100',
        'corporate_name' => 'EMPRESA NOVA SEGUNDA PAGINA',
        'state' => 'MT',
        'matched_cnae' => '4622200',
        'primary_cnae' => '4622200',
        'share_capital' => 5000000,
        'size_code' => '05',
        'legal_nature_code' => '2062',
        'cnae_match_type' => 'primary',
        'active_establishments' => 1,
        'active_states' => 1,
        'discovery_score' => 90,
    ];

    Http::fake([
        'receita-data:8000/prospects*' => Http::sequence()
            ->push([
                'items' => $knownItems,
                'count' => 50,
                'limit' => 50,
                'offset' => 0,
                'filters' => [],
            ])
            ->push([
                'items' => [
                    $newItem,
                ],
                'count' => 1,
                'limit' => 50,
                'offset' => 50,
                'filters' => [],
            ]),
    ]);

    $preview = app(
        ProspectingEngineService::class
    )->preview(
        limit: 10,
        states: [
            'MT',
        ],
        cnaes: [
            '4622200',
        ],
    );

    expect(
        $preview['new_count']
    )->toBe(1);

    expect(
        $preview['items'][0]['cnpj_root']
    )->toBe('99999999');

    Http::assertSentCount(2);
});

it('uses the stored cnpj root to remember previous prospecting', function () {
    config([
        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',
            'status' => 'processing',
        ]);

    /*
     * De propósito não usamos normalized_cnpj.
     *
     * O motor novo deve consultar diretamente
     * a coluna indexada cnpj_root.
     */
    $batch->items()->create([
        'row_number' => 1,
        'raw_cnpj' => '99.999.999/0001-00',
        'normalized_cnpj' => null,
        'cnpj_root' => '99999999',
        'status' => 'queued',
    ]);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [
                [
                    'cnpj_root' => '99999999',
                    'cnpj' => '99999999000100',
                    'corporate_name' => 'EMPRESA JA PROSPECTADA',
                    'state' => 'MT',
                    'matched_cnae' => '4622200',
                    'cnae_match_type' => 'primary',
                    'primary_cnae' => '4622200',
                    'share_capital' => 5000000,
                    'size_code' => '05',
                    'legal_nature_code' => '2062',
                    'active_establishments' => 1,
                    'active_states' => 1,
                    'discovery_score' => 90,
                ],
            ],
            'count' => 1,
            'limit' => 50,
            'offset' => 0,
            'filters' => [],
        ]),
    ]);

    $preview =
        app(
            ProspectingEngineService::class
        )->preview(
            limit: 10,
            states: [
                'MT',
            ],
            cnaes: [
                '4622200',
            ],
        );

    expect(
        $preview['known_count']
    )->toBe(1);

    expect(
        $preview['new_count']
    )->toBe(0);

    expect(
        $preview['items']
    )->toBe([]);
});
