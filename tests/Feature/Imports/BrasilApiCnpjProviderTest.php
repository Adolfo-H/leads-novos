<?php

use App\Exceptions\CnpjNotFoundException;
use App\Exceptions\CnpjProviderTemporaryException;
use App\Services\Providers\BrasilApiCnpjProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('calls the brasilapi cnpj endpoint correctly', function () {
    config([
        'services.brasilapi.base_url' => 'https://brasilapi.com.br/api',
    ]);

    Http::fake([
        'https://brasilapi.com.br/api/cnpj/v1/44959669000180' => Http::response([
            'cnpj' => '44959669000180',

            'razao_social' => 'EMPRESA TESTE LTDA',

            'descricao_situacao_cadastral' => 'ATIVA',

            'cnae_fiscal' => 115600,

            'cnae_fiscal_descricao' => 'Cultivo de soja',
        ]),
    ]);

    $data = app(
        BrasilApiCnpjProvider::class
    )->lookup(
        '44959669000180'
    );

    expect(
        $data['razao_social']
    )->toBe(
        'EMPRESA TESTE LTDA'
    );

    Http::assertSent(
        function (Request $request) {
            return
                $request->method()
                    === 'GET'

                && $request->url()
                    === 'https://brasilapi.com.br/api/cnpj/v1/44959669000180'

                && ! $request->hasHeader(
                    'Authorization'
                );
        }
    );
});

it('treats brasilapi rate limit as temporary', function () {
    Http::fake([
        '*' => Http::response(
            [
                'message' => 'Too Many Requests',
            ],
            429
        ),
    ]);

    expect(
        fn () => app(
            BrasilApiCnpjProvider::class
        )->lookup(
            '44959669000180'
        )
    )->toThrow(
        CnpjProviderTemporaryException::class
    );
});

it('treats a missing cnpj as not found', function () {
    Http::fake([
        '*' => Http::response(
            [
                'message' => 'CNPJ não encontrado',
            ],
            404
        ),
    ]);

    expect(
        fn () => app(
            BrasilApiCnpjProvider::class
        )->lookup(
            '44959669000180'
        )
    )->toThrow(
        CnpjNotFoundException::class
    );
});
