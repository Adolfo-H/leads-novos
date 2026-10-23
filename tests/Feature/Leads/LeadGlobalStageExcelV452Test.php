<?php

use App\Models\HubSpotDeal;
use App\Services\LeadGlobalStageExcelV45Service;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('exports the last HubSpot activity date even when Eloquent provides it as a string', function (): void {
    $dated = HubSpotDeal::query()->create([
        'hubspot_id' => 'v452-date-string-deal',
        'name' => 'V452 Negócio com atividade',
        'stage_label' => 'Reunião Agendada',
        'last_activity_at' => '2026-10-23 15:10:00',
    ]);

    $withoutDate = HubSpotDeal::query()->create([
        'hubspot_id' => 'v452-no-date-deal',
        'name' => 'V452 Negócio sem atividade',
        'stage_label' => 'Reunião Agendada',
        'last_activity_at' => null,
    ]);

    $response = app(LeadGlobalStageExcelV45Service::class)->excel(
        collect([$dated, $withoutDate]),
        'Reunião Agendada',
    );

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    try {
        $response->sendContent();
        $bytes = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect(substr($bytes, 0, 2))->toBe('PK');

    $file = tempnam(sys_get_temp_dir(), 'v452-xlsx-');
    if ($file === false) {
        throw new RuntimeException('Não foi possível criar o arquivo temporário XLSX.');
    }

    $spreadsheet = null;
    try {
        file_put_contents($file, $bytes);
        $spreadsheet = (new Xlsx)->load($file);
        $sheet = $spreadsheet->getActiveSheet();

        expect($sheet->getHighestDataRow())->toBe(3)
            ->and($sheet->getCell('A2')->getValue())->toBe('V452 Negócio com atividade')
            ->and($sheet->getCell('R2')->getValue())->toBe('v452-date-string-deal')
            ->and($sheet->getCell('S2')->getValue())->toBe('Reunião Agendada')
            ->and($sheet->getCell('U2')->getValue())->toBe('23/10/2026 15:10')
            ->and($sheet->getCell('U3')->getValue())->toBeEmpty();
    } finally {
        $spreadsheet?->disconnectWorksheets();
        @unlink($file);
    }
});
