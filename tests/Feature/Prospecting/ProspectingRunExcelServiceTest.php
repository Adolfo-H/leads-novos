<?php

use App\Models\Company;
use App\Models\ImportBatch;
use App\Services\ProspectingRunExcelService;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

it('exports the selected prospecting filter to xlsx', function () {
    $batch =
        ImportBatch::query()->create([
            'source_type' => 'prospecting',

            'status' => 'completed',

            'total_rows' => 2,

            'processed_rows' => 2,

            'metadata' => [
                'prospecting' => [
                    'discovered_count' => 20,

                    'new_candidates_count' => 2,

                    'filters' => [
                        'states' => [
                            'MT',
                        ],

                        'cnaes' => [
                            '4622200',
                        ],
                    ],
                ],
            ],
        ]);

    $exporter =
        Company::query()->create([
            'cnpj_root' => '77777777',

            'corporate_name' => 'Exportadora Excel',
        ]);

    $exporter
        ->exportIntelligence()
        ->create([
            'direct_status' => 'yes',

            'direct_confidence' => 90,

            'direct_confirmed' => false,

            'research_status' => 'completed',

            'metadata' => [],
        ]);

    $other =
        Company::query()->create([
            'cnpj_root' => '88888888',

            'corporate_name' => 'Empresa Fora do Filtro',
        ]);

    $other
        ->exportIntelligence()
        ->create([
            'direct_status' => 'uncertain',

            'direct_confidence' => 0,

            'direct_confirmed' => false,

            'research_status' => 'completed',

            'metadata' => [],
        ]);

    $batch->items()->create([
        'row_number' => 1,

        'raw_cnpj' => '07777777000100',

        'normalized_cnpj' => '07777777000100',

        'status' => 'completed',

        'company_id' => $exporter->id,
    ]);

    $batch->items()->create([
        'row_number' => 2,

        'raw_cnpj' => '88888888000100',

        'normalized_cnpj' => '88888888000100',

        'status' => 'completed',

        'company_id' => $other->id,
    ]);

    $response =
        app(
            ProspectingRunExcelService::class
        )->download(
            batch: $batch,
            filter: 'exporters',
        );

    expect(
        $response
            ->headers
            ->get(
                'content-type'
            )
    )->toBe(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    ob_start();

    $response->sendContent();

    $binary =
        ob_get_clean();

    expect(
        is_string(
            $binary
        )
    )->toBeTrue();

    expect(
        mb_substr(
            (string) $binary,
            0,
            2
        )
    )->toBe(
        'PK'
    );

    $path =
        sys_get_temp_dir()
        .'/prospecting-'
        .Str::uuid()
        .'.xlsx';

    file_put_contents(
        $path,
        (string) $binary
    );

    try {
        $workbook =
            IOFactory::load(
                $path
            );

        $companies =
            $workbook
                ->getSheetByName(
                    'Empresas'
                );

        expect(
            $companies
        )->not->toBeNull();

        expect(
            $companies
                ?->getCell(
                    'A2'
                )
                ->getValue()
        )->toBe(
            'Exportadora Excel'
        );

        expect(
            $companies
                ?->getCell(
                    'B2'
                )
                ->getValue()
        )->toBe(
            '07777777000100'
        );

        expect(
            $companies
                ?->getCell(
                    'A3'
                )
                ->getValue()
        )->toBeNull();

        $summary =
            $workbook
                ->getSheetByName(
                    'Resumo'
                );

        expect(
            $summary
                ?->getCell(
                    'E4'
                )
                ->getValue()
        )->toBe(
            'Exportadores'
        );

        expect(
            $summary
                ?->getCell(
                    'H3'
                )
                ->getValue()
        )->toBe(
            1
        );
    } finally {
        if (
            is_file(
                $path
            )
        ) {
            unlink(
                $path
            );
        }
    }
});
