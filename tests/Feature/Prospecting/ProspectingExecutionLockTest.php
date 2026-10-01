<?php

use App\Jobs\EnrichImportItem;
use App\Services\ProspectingEngineService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('blocks a second execution while another prospecting reservation owns the lock', function () {
    config([
        'cache.default' => 'database',

        'prospector.prospecting.execution_lock_seconds' => 60,

        'prospector.prospecting.execution_lock_wait_seconds' => 1,
    ]);

    Http::fake();

    $lock =
        Cache::lock(
            'prospecting:engine:execute',
            60,
        );

    expect(
        $lock->get()
    )->toBeTrue();

    try {
        expect(
            fn () => app(
                ProspectingEngineService::class
            )->execute(
                limit: 1,

                states: [
                    'MT',
                ],

                cnaes: [
                    '4622200',
                ],
            )
        )->toThrow(
            RuntimeException::class,
            'Já existe uma execução do Motor de Prospecção reservando empresas.'
        );
    } finally {
        $lock->release();
    }

    /*
     * O segundo processo nem chega
     * ao serviço Receita.
     */
    Http::assertNothingSent();
});

it('releases the prospecting lock when discovery throws an exception', function () {
    config([
        'cache.default' => 'database',

        'prospector.prospecting.execution_lock_seconds' => 60,

        'prospector.prospecting.execution_lock_wait_seconds' => 1,

        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response(
            [
                'error' => 'falha de teste',
            ],
            500,
        ),
    ]);

    expect(
        fn () => app(
            ProspectingEngineService::class
        )->execute(
            limit: 1,

            states: [
                'MT',
            ],

            cnaes: [
                '4622200',
            ],
        )
    )->toThrow(
        RuntimeException::class,
        'Erro ao consultar motor de prospecção.'
    );

    /*
     * A exceção ocorreu dentro da closure
     * passada para block().
     *
     * O lock precisa estar liberado.
     */
    $lock =
        Cache::lock(
            'prospecting:engine:execute',
            60,
        );

    expect(
        $lock->get()
    )->toBeTrue();

    $lock->release();
});

it('makes the next execution skip a root reserved by the previous run', function () {
    Queue::fake();

    config([
        'cache.default' => 'database',

        'prospector.prospecting.execution_lock_seconds' => 60,

        'prospector.prospecting.execution_lock_wait_seconds' => 1,

        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    $cofco = [
        'cnpj_root' => '06315338',

        'cnpj' => '06315338003134',

        'corporate_name' => 'COFCO INTERNATIONAL BRASIL S.A.',

        'state' => 'MT',

        'matched_cnae' => '4622200',

        'primary_cnae' => '4622200',

        'share_capital' => 7011251666.72,

        'size_code' => '05',

        'legal_nature_code' => '2054',

        'cnae_match_type' => 'primary',

        'active_establishments' => 129,

        'active_states' => 12,

        'discovery_score' => 100,
    ];

    $bunge = [
        'cnpj_root' => '84046101',

        'cnpj' => '84046101005233',

        'corporate_name' => 'BUNGE ALIMENTOS S/A',

        'state' => 'MT',

        'matched_cnae' => '4632001',

        'primary_cnae' => '4632001',

        'share_capital' => 9489807206.72,

        'size_code' => '05',

        'legal_nature_code' => '2054',

        'cnae_match_type' => 'primary',

        'active_establishments' => 194,

        'active_states' => 20,

        'discovery_score' => 100,
    ];

    Http::fake([
        'receita-data:8000/prospects*' => Http::sequence()

                /*
                 * Primeira execução:
                 * COFCO é reservada.
                 */
            ->push([
                'items' => [
                    $cofco,
                ],

                'count' => 1,

                'limit' => 50,

                'offset' => 0,

                'filters' => [],
            ])

                /*
                 * Segunda execução:
                 *
                 * COFCO aparece novamente,
                 * porém agora sua raiz já está
                 * registrada em import_items.
                 *
                 * BUNGE deve ser escolhida.
                 */
            ->push([
                'items' => [
                    $cofco,
                    $bunge,
                ],

                'count' => 2,

                'limit' => 50,

                'offset' => 0,

                'filters' => [],
            ]),
    ]);

    $engine =
        app(
            ProspectingEngineService::class
        );

    $first =
        $engine->execute(
            limit: 1,

            states: [
                'MT',
            ],

            cnaes: [
                '4622200',
            ],
        );

    $second =
        $engine->execute(
            limit: 1,

            states: [
                'MT',
            ],

            cnaes: [
                '4622200',
            ],
        );

    expect(
        $first[
            'batch'
        ]
    )->not->toBeNull();

    expect(
        $second[
            'batch'
        ]
    )->not->toBeNull();

    expect(
        $first[
            'batch'
        ]
            ?->items()
            ->firstOrFail()
            ->cnpj_root
    )->toBe(
        '06315338'
    );

    expect(
        $second[
            'batch'
        ]
            ?->items()
            ->firstOrFail()
            ->cnpj_root
    )->toBe(
        '84046101'
    );

    Queue::assertPushed(
        EnrichImportItem::class,
        2
    );
});
