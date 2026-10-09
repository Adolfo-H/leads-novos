<?php

use App\Services\LeadUnifiedExcelV23Service;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('exports a valid XLSX with the same 20 headers and releases worksheets after streaming', function (): void {
    $response = app(LeadUnifiedExcelV23Service::class)->excel(collect(), collect());

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    // Laravel pode envolver a callback para tratar exceções do download.
    // Procurar a planilha sem depender da quantidade desses wrappers.
    $pending = [$response->getCallback()];
    $spreadsheet = null;

    while ($pending !== []) {
        $callback = array_pop($pending);
        if (! $callback instanceof Closure) {
            continue;
        }

        foreach ((new ReflectionFunction($callback))->getStaticVariables() as $value) {
            if ($value instanceof Spreadsheet) {
                $spreadsheet = $value;
                break 2;
            }
            if ($value instanceof Closure) {
                $pending[] = $value;
            }
        }
    }

    expect($spreadsheet)->toBeInstanceOf(Spreadsheet::class);
    if (! $spreadsheet instanceof Spreadsheet) {
        throw new RuntimeException('Planilha não localizada na callback do download.');
    }
    expect($spreadsheet->getSheetCount())->toBe(1);

    ob_start();
    try {
        $response->sendContent();
        $data = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect(substr($data, 0, 2))->toBe('PK')
        ->and($spreadsheet->getSheetCount())->toBe(0);

    $tmp = tempnam(sys_get_temp_dir(), 'v186-xlsx-');
    if ($tmp === false) {
        throw new RuntimeException('Não foi possível criar arquivo temporário para teste XLSX.');
    }

    $readBack = null;
    try {
        file_put_contents($tmp, $data);
        $readBack = (new Xlsx)->load($tmp);
        $sheet = $readBack->getActiveSheet();

        expect($sheet->getHighestColumn())->toBe('T')
            ->and($sheet->getCell('A1')->getValue())->toBe('Empresa')
            ->and($sheet->getCell('B1')->getValue())->toBe('CNPJ')
            ->and($sheet->getCell('T1')->getValue())->toBe('Última atividade HubSpot');
    } finally {
        $readBack?->disconnectWorksheets();
        @unlink($tmp);
    }
});

it('preserves leading zeroes and stores formula-looking commercial values as text', function (): void {
    $book = new Spreadsheet;
    try {
        $sheet = $book->getActiveSheet();
        $writeRow = new ReflectionMethod(LeadUnifiedExcelV23Service::class, 'writeRow');

        $writeRow->invoke(
            app(LeadUnifiedExcelV23Service::class),
            $sheet,
            2,
            [
                'Empresa' => '=2+2',
                'CNPJ' => '00123456000100',
                'Telefones' => '+5511999999999',
                'E-mails' => '@teste.invalid',
                'Exportação' => '-1+2',
            ],
        );

        expect($sheet->getCell('A2')->getValue())->toBe("'=2+2")
            ->and($sheet->getCell('B2')->getValue())->toBe('00123456000100')
            ->and($sheet->getCell('L2')->getValue())->toBe("'+5511999999999")
            ->and($sheet->getCell('M2')->getValue())->toBe("'@teste.invalid")
            ->and($sheet->getCell('N2')->getValue())->toBe("'-1+2");

        foreach (['A2', 'B2', 'L2', 'M2', 'N2'] as $cell) {
            expect($sheet->getCell($cell)->getDataType())->toBe(DataType::TYPE_STRING);
        }
    } finally {
        $book->disconnectWorksheets();
    }
});
