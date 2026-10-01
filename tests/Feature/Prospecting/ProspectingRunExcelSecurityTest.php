<?php

use App\Services\ProspectingRunExcelService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

it('writes external company values as literal strings in prospecting excel', function () {
    $service =
        app(
            ProspectingRunExcelService::class
        );

    $spreadsheet =
        new Spreadsheet;

    $method =
        new ReflectionMethod(
            $service,
            'buildCompaniesSheet'
        );

    $method->invoke(
        $service,
        $spreadsheet,
        [[
            'cnpj' => '00123456000100',

            'company_name' => '=1+1',

            'icp_grade' => '+SUM(A1:A2)',

            'icp_score' => 90,

            'crm_label' => '-10+10',

            'export_label' => '@SUM(A1:A2)',

            'sdr_score' => 80,

            'outcome' => '=HYPERLINK("https://example.com","X")',

            'blocked_reason' => '=CMD()',

            'error' => null,

            'hubspot_url' => '',

            'company_uuid' => '',
        ]]
    );

    $sheet =
        $spreadsheet
            ->getSheetByName(
                'Empresas'
            );

    expect(
        $sheet
    )->not->toBeNull();

    assert(
        $sheet !== null
    );

    foreach (
        [
            'A2',
            'B2',
            'C2',
            'E2',
            'F2',
            'H2',
            'I2',
            'J2',
            'K2',
        ] as $coordinate
    ) {
        expect(
            $sheet
                ->getCell(
                    $coordinate
                )
                ->getDataType()
        )->toBe(
            DataType::TYPE_STRING
        );
    }

    expect(
        $sheet
            ->getCell(
                'A2'
            )
            ->getValue()
    )->toBe(
        '=1+1'
    );

    expect(
        $sheet
            ->getCell(
                'I2'
            )
            ->getValue()
    )->toBe(
        '=CMD()'
    );
});

it('writes prospecting summary metadata as literal strings', function () {
    $service =
        app(
            ProspectingRunExcelService::class
        );

    $spreadsheet =
        new Spreadsheet;

    $method =
        new ReflectionMethod(
            $service,
            'buildSummarySheet'
        );

    $method->invoke(
        $service,
        $spreadsheet,
        [
            'uuid' => '=1+1',

            'created_label' => '+2+2',

            'status_label' => '@STATUS',

            'filters' => [
                'states' => [
                    '=PR',
                ],

                'cnaes' => [
                    '+4622200',
                ],
            ],
        ],
        'all',
        1,
    );

    $sheet =
        $spreadsheet
            ->getActiveSheet();

    foreach (
        [
            'B3',
            'B4',
            'E3',
            'E4',
            'B11',
            'B12',
        ] as $coordinate
    ) {
        expect(
            $sheet
                ->getCell(
                    $coordinate
                )
                ->getDataType()
        )->toBe(
            DataType::TYPE_STRING
        );
    }

    expect(
        $sheet
            ->getCell(
                'B3'
            )
            ->getValue()
    )->toBe(
        '=1+1'
    );

    expect(
        $sheet
            ->getCell(
                'B11'
            )
            ->getValue()
    )->toBe(
        '=PR'
    );
});
