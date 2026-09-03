<?php

use App\Services\Providers\ReceitaLocalCnpjGroupProvider;
use Illuminate\Support\Facades\Http;

it('loads a complete company group from receita data service', function () {
    config()->set(
        'services.receita_local.base_url',
        'http://receita-data:8000'
    );

    Http::fake([
        'http://receita-data:8000/groups/11222333' => Http::response(
            [
                'company' => [
                    'corporate_name' => 'COOPERATIVA AGRO TESTE',

                    'legal_nature_code' => '2143',

                    'legal_nature_description' => 'COOPERATIVA',

                    'share_capital' => 5000000,

                    'size_code' => '05',

                    'size_description' => 'DEMAIS',

                    'source' => 'receita-local',
                ],

                'establishments' => [
                    [
                        'establishment' => [
                            'cnpj' => '11222333000181',

                            'type' => 'matrix',

                            'state' => 'MT',

                            'municipality_name' => 'SORRISO',

                            'source' => 'receita-local',
                        ],

                        'cnaes' => [
                            [
                                'code' => '4622200',

                                'description' => null,

                                'is_primary' => true,
                            ],
                        ],
                    ],

                    [
                        'establishment' => [
                            'cnpj' => '11222333000262',

                            'type' => 'branch',

                            'state' => 'GO',

                            'municipality_name' => 'RIO VERDE',

                            'source' => 'receita-local',
                        ],

                        'cnaes' => [],
                    ],
                ],
            ],
            200
        ),
    ]);

    $result = app(
        ReceitaLocalCnpjGroupProvider::class
    )->lookupRoot(
        '11222333'
    );

    expect(
        $result['company']['corporate_name']
    )->toBe(
        'COOPERATIVA AGRO TESTE'
    );

    expect(
        $result['establishments']
    )->toHaveCount(2);

    Http::assertSent(
        fn ($request): bool => $request->url()
            === 'http://receita-data:8000/groups/11222333'
    );
});

it('fails when company does not exist in local receita base', function () {
    config()->set(
        'services.receita_local.base_url',
        'http://receita-data:8000'
    );

    Http::fake([
        'http://receita-data:8000/groups/99999999' => Http::response(
            [
                'detail' => 'Empresa não encontrada na base local.',
            ],
            404
        ),
    ]);

    app(
        ReceitaLocalCnpjGroupProvider::class
    )->lookupRoot(
        '99999999'
    );
})->throws(
    RuntimeException::class,
    'Empresa não encontrada na base local da Receita.'
);
