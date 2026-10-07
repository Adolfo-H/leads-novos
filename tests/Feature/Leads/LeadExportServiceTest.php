<?php

use App\Models\Company;
use App\Services\LeadExportService;

function leadExportCompany(
    string $root,
    string $name,
): Company {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => $root,

                'corporate_name' => $name,
            ]);

    $company
        ->sdrScore()
        ->create([
            'score' => 91,

            'priority' => 'very_high',

            'label' => 'Muito alta',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    return $company;
}

it('exports every unique email and phone found in company establishments', function () {
    $company =
        leadExportCompany(
            '89111111',
            'Empresa Exportacao Excel'
        );

    $company
        ->establishments()
        ->create([
            'cnpj' => '89111111000101',

            'order_number' => '0001',

            'check_digits' => '01',

            'type' => 'matrix',

            'municipality_name' => 'Palotina',

            'state' => 'PR',

            'phone_1' => '(44) 99999-1111',

            'phone_2' => '(44) 3333-2222',

            'email' => 'COMERCIAL@EMPRESA.COM.BR',

            'source' => 'test',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '89111111000292',

            'order_number' => '0002',

            'check_digits' => '92',

            'type' => 'branch',

            'phone_1' => '(44) 99999-1111',

            'phone_2' => '(45) 98888-3333',

            'email' => 'financeiro@empresa.com.br',

            'source' => 'test',
        ]);

    $rows =
        app(
            LeadExportService::class
        )->rows(
            collect([
                $company,
            ])
        );

    expect(
        $rows
    )->toHaveCount(
        1
    );

    expect(
        $rows[0][
            'Empresa'
        ]
    )->toBe(
        'Empresa Exportacao Excel'
    );

    expect(
        $rows[0][
            'CNPJ'
        ]
    )->toBe(
        '89111111000101'
    );

    expect(
        $rows[0][
            'Telefones'
        ]
    )
        ->toContain(
            '(44) 99999-1111'
        )
        ->toContain(
            '(44) 3333-2222'
        )
        ->toContain(
            '(45) 98888-3333'
        );

    expect(
        substr_count(
            $rows[0][
                'Telefones'
            ],
            '(44) 99999-1111'
        )
    )->toBe(
        1
    );

    expect(
        $rows[0][
            'E-mails'
        ]
    )
        ->toContain(
            'comercial@empresa.com.br'
        )
        ->toContain(
            'financeiro@empresa.com.br'
        );

    expect(
        $rows[0][
            'Score SDR'
        ]
    )->toBe(
        '91'
    );

    expect(
        $rows[0][
            'Prioridade'
        ]
    )->toBe(
        'Muito alta'
    );
});
