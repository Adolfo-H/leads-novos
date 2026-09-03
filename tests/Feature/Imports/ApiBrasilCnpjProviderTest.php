<?php

use App\Services\Providers\ApiBrasilCnpjProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('calls the apibrasil cnpj endpoint correctly', function () {
    config([
        'services.apibrasil.token' => 'test-token',

        'services.apibrasil.base_url' => 'https://gateway.apibrasil.io/api/v2',

        'services.apibrasil.cnpj_type' => 'cnpj-cadastral',
    ]);

    Http::fake([
        'https://gateway.apibrasil.io/api/v2/consulta/cnpj/credits' => Http::response([
            'error' => false,

            'response' => [
                'razao_social' => 'EMPRESA TESTE LTDA',

                'situacao_cadastral' => 'ATIVA',
            ],
        ]),
    ]);

    $data = app(
        ApiBrasilCnpjProvider::class
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
                $request->method() === 'POST'
                && $request->url()
                    === 'https://gateway.apibrasil.io/api/v2/consulta/cnpj/credits'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer test-token'
                )
                && $request['tipo']
                    === 'cnpj-cadastral'
                && $request['cnpj']
                    === '44959669000180';
        }
    );
});
