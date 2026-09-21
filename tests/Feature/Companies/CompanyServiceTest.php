<?php

use App\Models\Cnae;
use App\Models\Company;
use App\Models\Establishment;
use App\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a company with matrix establishment and cnae', function () {
    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Cooperativa Agropecuária São João',

                'share_capital' => 2500000,

                'size_code' => '05',

                'size_description' => 'Demais',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',

                'fantasy_name' => 'Cooperativa São João',

                'registration_status' => 'ATIVA',

                'state' => 'PR',

                'municipality_name' => 'Toledo',

                'email' => 'CONTATO@EXEMPLO.COM.BR',
            ],
            [
                [
                    'code' => '4622200',

                    'description' => 'Comércio atacadista de soja',

                    'is_primary' => true,
                ],
            ]
        );

    expect(
        Company::count()
    )->toBe(1);

    expect(
        Establishment::count()
    )->toBe(1);

    expect(
        Cnae::count()
    )->toBe(1);

    expect(
        $company->cnpj_root
    )->toBe('11222333');

    expect(
        $company->normalized_name
    )->toBe(
        'COOPERATIVA AGROPECUARIA SAO JOAO'
    );

    $establishment =
        $company->establishments->first();

    expect(
        $establishment->cnpj
    )->toBe('11222333000181');

    expect(
        $establishment->order_number
    )->toBe('0001');

    expect(
        $establishment->check_digits
    )->toBe('81');

    expect(
        $establishment->email
    )->toBe(
        'contato@exemplo.com.br'
    );

    expect(
        $establishment
            ->cnaes
            ->first()
            ->code
    )->toBe('4622200');

    expect(
        (bool) $establishment
            ->cnaes
            ->first()
            ->pivot
            ->is_primary
    )->toBeTrue();
});

it('supports an alphanumeric cnpj', function () {
    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Empresa Alfanumérica Teste',
            ],
            [
                'cnpj' => '12.ABC.345/01DE-35',

                'type' => 'branch',

                'state' => 'SP',
            ],
        );

    expect(
        $company->cnpj_root
    )->toBe('12ABC345');

    $establishment =
        $company->establishments->first();

    expect(
        $establishment->cnpj
    )->toBe(
        '12ABC34501DE35'
    );

    expect(
        $establishment->order_number
    )->toBe('01DE');

    expect(
        $establishment->check_digits
    )->toBe('35');
});

it('does not duplicate a company when processing it twice', function () {
    $service = app(
        CompanyService::class
    );

    $payload = [
        'corporate_name' => 'Empresa Reprocessada Ltda',
    ];

    $establishment = [
        'cnpj' => '11.222.333/0001-81',

        'type' => 'matrix',
    ];

    $service
        ->createOrUpdateFromEstablishment(
            $payload,
            $establishment,
        );

    $service
        ->createOrUpdateFromEstablishment(
            $payload,
            $establishment,
        );

    expect(
        Company::count()
    )->toBe(1);

    expect(
        Establishment::count()
    )->toBe(1);
});

it('can skip relation reload during large group enrichment', function () {
    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Grupo Grande Performance Ltda',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',

                'registration_status' => 'ATIVA',

                'state' => 'PR',
            ],
            [
                [
                    'code' => '4622200',

                    'description' => 'Comércio atacadista de soja',

                    'is_primary' => true,
                ],
            ],
            loadRelations: false,
        );

    expect(
        $company
            ->relationLoaded(
                'establishments'
            )
    )->toBeFalse();

    expect(
        Company::count()
    )->toBe(1);

    expect(
        Establishment::count()
    )->toBe(1);
});
