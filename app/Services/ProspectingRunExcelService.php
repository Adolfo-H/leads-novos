<?php

namespace App\Services;

use App\Models\ImportBatch;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProspectingRunExcelService
{
    public function __construct(
        private readonly ProspectingRunDetailService $detailService,
    ) {}

    public function download(
        ImportBatch $batch,
        string $filter = 'all',
    ): StreamedResponse {
        $detail =
            $this->detailService->build(
                $batch
            );

        /**
         * @var list<array<string, mixed>> $rawItems
         */
        $rawItems =
            is_array(
                $detail['items']
                ?? null
            )
                ? $detail['items']
                : [];

        $items =
            $this->filteredItems(
                $rawItems,
                $filter,
            );

        $spreadsheet =
            new Spreadsheet;

        $this->buildSummarySheet(
            spreadsheet: $spreadsheet,
            detail: $detail,
            filter: $filter,
            exportedCount: count(
                $items
            ),
        );

        $this->buildCompaniesSheet(
            spreadsheet: $spreadsheet,
            items: $items,
        );

        $spreadsheet->setActiveSheetIndex(
            0
        );

        $date =
            $batch
                ->created_at
                ?->format(
                    'Ymd-His'
                )
            ?? now()->format(
                'Ymd-His'
            );

        $shortUuid =
            mb_substr(
                (string) $batch->uuid,
                0,
                8
            );

        $filename =
            'prospeccao-'
            .$date
            .'-'
            .$shortUuid
            .'.xlsx';

        return response()->streamDownload(
            static function () use (
                $spreadsheet
            ): void {
                $writer =
                    new Xlsx(
                        $spreadsheet
                    );

                $writer->save(
                    'php://output'
                );

                $spreadsheet
                    ->disconnectWorksheets();
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function buildSummarySheet(
        Spreadsheet $spreadsheet,
        array $detail,
        string $filter,
        int $exportedCount,
    ): void {
        $sheet =
            $spreadsheet
                ->getActiveSheet();

        $sheet->setTitle(
            'Resumo'
        );

        $sheet->mergeCells(
            'A1:H1'
        );

        $sheet->setCellValue(
            'A1',
            'ExportControl - Rodada de Prospecção'
        );

        $sheet
            ->getStyle(
                'A1:H1'
            )
            ->getFill()
            ->setFillType(
                Fill::FILL_SOLID
            )
            ->getStartColor()
            ->setARGB(
                'FF172554'
            );

        $sheet
            ->getStyle(
                'A1:H1'
            )
            ->getFont()
            ->setBold(
                true
            )
            ->setColor(
                new Color(
                    'FFFFFFFF'
                )
            )
            ->setSize(
                15
            );

        $sheet
            ->getStyle(
                'A1:H1'
            )
            ->getAlignment()
            ->setHorizontal(
                Alignment::HORIZONTAL_LEFT
            )
            ->setVertical(
                Alignment::VERTICAL_CENTER
            );

        $sheet
            ->getRowDimension(
                1
            )
            ->setRowHeight(
                28
            );

        $sheet->setCellValue(
            'A3',
            'Lote'
        );

        $sheet->setCellValue(
            'B3',
            (string) (
                $detail['uuid']
                ?? ''
            )
        );

        $sheet->setCellValue(
            'A4',
            'Data'
        );

        $sheet->setCellValue(
            'B4',
            (string) (
                $detail['created_label']
                ?? ''
            )
        );

        $sheet->setCellValue(
            'D3',
            'Status'
        );

        $sheet->setCellValue(
            'E3',
            (string) (
                $detail['status_label']
                ?? ''
            )
        );

        $sheet->setCellValue(
            'D4',
            'Filtro exportado'
        );

        $sheet->setCellValue(
            'E4',
            $this->filterLabel(
                $filter
            )
        );

        $sheet->setCellValue(
            'G3',
            'Linhas no arquivo'
        );

        $sheet->setCellValue(
            'H3',
            $exportedCount
        );

        $labels = [
            'Descobertos',
            'Enviados',
            'Processados',
            'Leads',
            'Bloqueados',
            'Pesquisados',
            'Exportadores',
            'Falhas',
        ];

        $values = [
            $this->integer(
                $detail[
                    'discovered_count'
                ]
                ?? 0
            ),

            $this->integer(
                $detail[
                    'total_rows'
                ]
                ?? 0
            ),

            $this->integer(
                $detail[
                    'processed_rows'
                ]
                ?? 0
            ),

            $this->integer(
                $detail[
                    'lead_count'
                ]
                ?? 0
            ),

            $this->integer(
                $detail[
                    'blocked_count'
                ]
                ?? 0
            ),

            $this->integer(
                $detail[
                    'researched_count'
                ]
                ?? 0
            ),

            $this->integer(
                $detail[
                    'export_identified_count'
                ]
                ?? 0
            ),

            $this->integer(
                $detail[
                    'failed_count'
                ]
                ?? 0
            ),
        ];

        $sheet->fromArray(
            $labels,
            null,
            'A7'
        );

        $sheet->fromArray(
            $values,
            null,
            'A8'
        );

        $sheet
            ->getStyle(
                'A7:H7'
            )
            ->getFill()
            ->setFillType(
                Fill::FILL_SOLID
            )
            ->getStartColor()
            ->setARGB(
                'FF1E3A5F'
            );

        $sheet
            ->getStyle(
                'A7:H7'
            )
            ->getFont()
            ->setBold(
                true
            )
            ->setColor(
                new Color(
                    'FFFFFFFF'
                )
            );

        $sheet
            ->getStyle(
                'A8:H8'
            )
            ->getFont()
            ->setBold(
                true
            )
            ->setSize(
                13
            );

        $filters =
            is_array(
                $detail[
                    'filters'
                ]
                ?? null
            )
                ? $detail[
                    'filters'
                ]
                : [];

        $sheet->setCellValue(
            'A11',
            'UFs'
        );

        $sheet->setCellValue(
            'B11',
            $this->joinValues(
                $filters[
                    'states'
                ]
                ?? []
            )
        );

        $sheet->setCellValue(
            'A12',
            'CNAEs'
        );

        $sheet->setCellValue(
            'B12',
            $this->joinValues(
                $filters[
                    'cnaes'
                ]
                ?? []
            )
        );

        $sheet->mergeCells(
            'B11:H11'
        );

        $sheet->mergeCells(
            'B12:H12'
        );

        foreach (
            range(
                'A',
                'H'
            ) as $column
        ) {
            $sheet
                ->getColumnDimension(
                    $column
                )
                ->setWidth(
                    16
                );
        }

        $sheet
            ->getColumnDimension(
                'B'
            )
            ->setWidth(
                24
            );

        $sheet
            ->getStyle(
                'A1:H12'
            )
            ->getAlignment()
            ->setVertical(
                Alignment::VERTICAL_CENTER
            );

        $sheet
            ->getStyle(
                'B11:H12'
            )
            ->getAlignment()
            ->setWrapText(
                true
            );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function buildCompaniesSheet(
        Spreadsheet $spreadsheet,
        array $items,
    ): void {
        $sheet =
            $spreadsheet
                ->createSheet();

        $sheet->setTitle(
            'Empresas'
        );

        $headers = [
            'Empresa',
            'CNPJ',
            'ICP',
            'ICP Score',
            'CRM',
            'Exportação',
            'SDR Score',
            'Resultado',
            'Motivo / Bloqueio',
            'HubSpot',
            'Dossiê',
        ];

        $sheet->fromArray(
            $headers,
            null,
            'A1'
        );

        $sheet
            ->getStyle(
                'A1:K1'
            )
            ->getFill()
            ->setFillType(
                Fill::FILL_SOLID
            )
            ->getStartColor()
            ->setARGB(
                'FF172554'
            );

        $sheet
            ->getStyle(
                'A1:K1'
            )
            ->getFont()
            ->setBold(
                true
            )
            ->setColor(
                new Color(
                    'FFFFFFFF'
                )
            );

        $row = 2;

        foreach ($items as $item) {
            $cnpj =
                $this->text(
                    $item[
                        'cnpj'
                    ]
                    ?? null
                );

            $companyName =
                $this->text(
                    $item[
                        'company_name'
                    ]
                    ?? null
                );

            if ($companyName === '') {
                $companyName =
                    $cnpj;
            }

            $hubSpotUrl =
                $this->text(
                    $item[
                        'hubspot_url'
                    ]
                    ?? null
                );

            $companyUuid =
                $this->text(
                    $item[
                        'company_uuid'
                    ]
                    ?? null
                );

            $dossierUrl =
                $companyUuid !== ''
                    ? route(
                        'companies.show',
                        $companyUuid
                    )
                    : '';

            $sheet->setCellValue(
                'A'.$row,
                $companyName
            );

            /*
             * CNPJ precisa ser texto no Excel,
             * senão zeros à esquerda podem
             * desaparecer.
             */
            $sheet
                ->getCell(
                    'B'.$row
                )
                ->setValueExplicit(
                    $cnpj,
                    DataType::TYPE_STRING
                );

            $sheet->setCellValue(
                'C'.$row,
                $this->text(
                    $item[
                        'icp_grade'
                    ]
                    ?? null
                )
            );

            $sheet->setCellValue(
                'D'.$row,
                $this->nullableInteger(
                    $item[
                        'icp_score'
                    ]
                    ?? null
                )
            );

            $sheet->setCellValue(
                'E'.$row,
                $this->text(
                    $item[
                        'crm_label'
                    ]
                    ?? null
                )
            );

            $sheet->setCellValue(
                'F'.$row,
                $this->text(
                    $item[
                        'export_label'
                    ]
                    ?? null
                )
            );

            $sheet->setCellValue(
                'G'.$row,
                $this->nullableInteger(
                    $item[
                        'sdr_score'
                    ]
                    ?? null
                )
            );

            $sheet->setCellValue(
                'H'.$row,
                $this->text(
                    $item[
                        'outcome'
                    ]
                    ?? null
                )
            );

            $sheet->setCellValue(
                'I'.$row,
                $this->text(
                    $item[
                        'blocked_reason'
                    ]
                    ?? $item[
                        'error'
                    ]
                    ?? null
                )
            );

            $sheet->setCellValue(
                'J'.$row,
                $hubSpotUrl !== ''
                    ? 'Abrir no HubSpot'
                    : ''
            );

            $sheet->setCellValue(
                'K'.$row,
                $dossierUrl
            );

            if ($hubSpotUrl !== '') {
                $sheet
                    ->getCell(
                        'J'.$row
                    )
                    ->getHyperlink()
                    ->setUrl(
                        $hubSpotUrl
                    );
            }

            if ($dossierUrl !== '') {
                $sheet
                    ->getCell(
                        'K'.$row
                    )
                    ->getHyperlink()
                    ->setUrl(
                        $dossierUrl
                    );
            }

            $row++;
        }

        $lastRow =
            max(
                1,
                $row - 1
            );

        $sheet->freezePane(
            'A2'
        );

        $sheet->setAutoFilter(
            'A1:K'.$lastRow
        );

        $sheet
            ->getStyle(
                'A1:K'.$lastRow
            )
            ->getAlignment()
            ->setVertical(
                Alignment::VERTICAL_TOP
            );

        $sheet
            ->getStyle(
                'A2:K'.$lastRow
            )
            ->getAlignment()
            ->setWrapText(
                true
            );

        $sheet
            ->getColumnDimension(
                'A'
            )
            ->setWidth(
                42
            );

        $sheet
            ->getColumnDimension(
                'B'
            )
            ->setWidth(
                18
            );

        $sheet
            ->getColumnDimension(
                'C'
            )
            ->setWidth(
                10
            );

        $sheet
            ->getColumnDimension(
                'D'
            )
            ->setWidth(
                12
            );

        $sheet
            ->getColumnDimension(
                'E'
            )
            ->setWidth(
                18
            );

        $sheet
            ->getColumnDimension(
                'F'
            )
            ->setWidth(
                34
            );

        $sheet
            ->getColumnDimension(
                'G'
            )
            ->setWidth(
                12
            );

        $sheet
            ->getColumnDimension(
                'H'
            )
            ->setWidth(
                18
            );

        $sheet
            ->getColumnDimension(
                'I'
            )
            ->setWidth(
                38
            );

        $sheet
            ->getColumnDimension(
                'J'
            )
            ->setWidth(
                45
            );

        $sheet
            ->getColumnDimension(
                'K'
            )
            ->setWidth(
                45
            );

        $sheet
            ->getStyle(
                'J2:K'.$lastRow
            )
            ->getFont()
            ->getColor()
            ->setARGB(
                'FF0563C1'
            );

        $sheet
            ->getStyle(
                'J2:K'.$lastRow
            )
            ->getFont()
            ->setUnderline(
                true
            );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function filteredItems(
        array $items,
        string $filter,
    ): array {
        return array_values(
            array_filter(
                $items,
                static function (
                    array $item
                ) use (
                    $filter
                ): bool {
                    return match ($filter) {
                        'leads' => (
                            $item[
                                'sdr_eligible'
                            ]
                            ?? null
                        ) === true,

                        'blocked' => (
                            $item[
                                'sdr_eligible'
                            ]
                            ?? null
                        ) === false,

                        'exporters' => (
                            $item[
                                'export_identified'
                            ]
                            ?? false
                        ) === true,

                        'failed' => (
                            $item[
                                'item_status'
                            ]
                            ?? null
                        ) === 'failed',

                        default => true,
                    };
                }
            )
        );
    }

    private function filterLabel(
        string $filter
    ): string {
        return match ($filter) {
            'leads' => 'Leads',

            'blocked' => 'Bloqueados',

            'exporters' => 'Exportadores',

            'failed' => 'Falhas',

            default => 'Todos',
        };
    }

    private function text(
        mixed $value
    ): string {
        if (is_string($value)) {
            return trim(
                $value
            );
        }

        if (
            is_int($value)
            || is_float($value)
        ) {
            return (string) $value;
        }

        return '';
    }

    private function integer(
        mixed $value
    ): int {
        return is_numeric($value)
            ? (int) $value
            : 0;
    }

    private function nullableInteger(
        mixed $value
    ): ?int {
        return is_numeric($value)
            ? (int) $value
            : null;
    }

    private function joinValues(
        mixed $value
    ): string {
        if (! is_array($value)) {
            return '';
        }

        $values = [];

        foreach ($value as $item) {
            if (
                is_string($item)
                || is_int($item)
            ) {
                $values[] =
                    (string) $item;
            }
        }

        return implode(
            ', ',
            $values
        );
    }
}
