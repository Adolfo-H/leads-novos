<?php

use App\Contracts\CnpjDataProvider;
use App\Jobs\EnrichImportItem;
use App\Services\CnpjImportService;
use App\Services\ImportQueueService;
use App\Support\Cnpj;
use Illuminate\Support\Facades\Queue;

it('queues ready import items', function () {
    Queue::fake();

    $base =
        '223334440001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $total = app(
        ImportQueueService::class
    )->dispatchReady(
        $batch
    );

    expect($total)
        ->toBe(1);

    expect(
        $batch
            ->items()
            ->firstOrFail()
            ->status
    )->toBe('queued');

    Queue::assertPushed(
        EnrichImportItem::class,
        1
    );
});

it('enriches a queued cnpj into a company', function () {
    $provider =
        new class implements CnpjDataProvider
        {
            public function name(): string
            {
                return 'fake';
            }

            public function lookup(
                string $cnpj
            ): array {
                return [
                    'razao_social' => 'AGROPECUARIA AUTOMATICA LTDA',

                    'nome_fantasia' => 'AGRO AUTOMATICA',

                    'situacao_cadastral' => 'ATIVA',

                    'data_abertura' => '2020-01-15',

                    'capital_social' => 5000000,

                    'porte' => 'DEMAIS',

                    'uf' => 'MT',

                    'municipio' => 'SORRISO',

                    'logradouro' => 'AVENIDA DAS SOJAS',

                    'numero' => '1000',

                    'email' => 'FISCAL@AGRO.COM.BR',

                    'telefone' => '6633334444',

                    'cnae_principal' => [
                        'codigo' => '4622200',

                        'descricao' => 'Comércio atacadista de soja',
                    ],

                    'cnaes_secundarios' => [
                        [
                            'codigo' => '0115600',

                            'descricao' => 'Cultivo de soja',
                        ],
                    ],
                ];
            }
        };

    app()->instance(
        CnpjDataProvider::class,
        $provider
    );

    $base =
        '223334440001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $item =
        $batch
            ->items()
            ->firstOrFail();

    $item->update([
        'status' => 'queued',
    ]);

    EnrichImportItem::dispatchSync(
        $item->id
    );

    $item->refresh();

    expect(
        $item->status
    )->toBe('completed');

    expect(
        $item->company_id
    )->not->toBeNull();

    $company =
        $item->company()
            ->firstOrFail();

    expect(
        $company->corporate_name
    )->toBe(
        'AGROPECUARIA AUTOMATICA LTDA'
    );

    expect(
        $company->share_capital
    )->toBe(
        '5000000.00'
    );

    $matrix =
        $company
            ->establishments()
            ->firstOrFail();

    expect(
        $matrix->state
    )->toBe('MT');

    expect(
        $matrix->email
    )->toBe(
        'fiscal@agro.com.br'
    );

    expect(
        $matrix
            ->cnaes()
            ->count()
    )->toBe(2);
});
