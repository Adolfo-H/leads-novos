<?php

namespace App\Services;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LeadExportService
{
    /**
     * @var list<string>
     */
    private const HEADERS = [
        'Empresa',
        'CNPJ',
        'Cidade',
        'UF',
        'Score SDR',
        'Prioridade',
        'ICP',
        'Responsável',
        'Status comercial',
        'Status CRM',
        'Próxima ação',
        'Telefones',
        'E-mails',
        'Exportação',
        'Resumo exportação',
        'HubSpot Company ID',
        'HubSpot Deal ID',
    ];

    /**
     * @param  Collection<int, Company>  $companies
     */
    public function excel(
        Collection $companies
    ): StreamedResponse {
        $spreadsheet =
            new Spreadsheet;

        $sheet =
            $spreadsheet
                ->getActiveSheet();

        $sheet->setTitle(
            'Leads'
        );

        /*
         * Cabeçalho.
         */
        foreach (
            self::HEADERS as $columnIndex => $header
        ) {
            $sheet->setCellValueExplicit(
                [
                    $columnIndex + 1,
                    1,
                ],
                $header,
                DataType::TYPE_STRING,
            );
        }

        $sheet
            ->getStyle(
                'A1:Q1'
            )
            ->getFont()
            ->setBold(
                true
            );

        $sheet->freezePane(
            'A2'
        );

        $sheet->setAutoFilter(
            'A1:Q1'
        );

        /*
         * Linhas.
         */
        $rowIndex = 2;

        foreach ($companies as $company) {
            $row =
                $this->row(
                    $company
                );

            foreach (
                self::HEADERS as $columnIndex => $header
            ) {
                $value =
                    $row[
                        $header
                    ]
                    ?? '';

                /*
                 * Tudo é escrito explicitamente
                 * como texto.
                 *
                 * Além de preservar CNPJ/telefone,
                 * evita formula injection.
                 */
                $sheet->setCellValueExplicit(
                    [
                        $columnIndex + 1,
                        $rowIndex,
                    ],
                    $this
                        ->safeSpreadsheetText(
                            $value
                        ),
                    DataType::TYPE_STRING,
                );
            }

            $rowIndex++;
        }

        /*
         * Largura automática com limite visual.
         */
        foreach (
            range(
                'A',
                'Q'
            ) as $column
        ) {
            $sheet
                ->getColumnDimension(
                    $column
                )
                ->setAutoSize(
                    true
                );
        }

        $sheet
            ->getStyle(
                'A1:Q'
                .max(
                    1,
                    $rowIndex - 1
                )
            )
            ->getAlignment()
            ->setVertical(
                'top'
            )
            ->setWrapText(
                true
            );

        $filename =
            'leads-'
            .now()
                ->format(
                    'Y-m-d_H-i-s'
                )
            .'.xlsx';

        return response()
            ->streamDownload(
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

                    'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                ]
            );
    }

    /**
     * Método público para permitir validação
     * da representação exportada em testes.
     *
     * @param  Collection<int, Company>  $companies
     * @return list<array<string, string>>
     */
    public function rows(
        Collection $companies
    ): array {
        $rows = [];

        foreach ($companies as $company) {
            $rows[] =
                $this->row(
                    $company
                );
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function row(
        Company $company
    ): array {
        $company->loadMissing([
            'establishments',
            'matrix',
            'icpScore',
            'crmCheck',
            'sdrScore',
            'exportIntelligence',
            'hubSpotLead',
            'leadWorkState.assignedUser',
        ]);

        $matrix =
            $company->matrix
            ?? $company
                ->establishments
                ->first();

        $phones =
            $this->phones(
                $company
            );

        $emails =
            $this->emails(
                $company
            );

        $lead =
            $company
                ->hubSpotLead;

        $workState =
            $company
                ->leadWorkState;

        $export =
            $company
                ->exportIntelligence;

        $nextAction =
            $lead
                ->last_task_due_at
            ?? $workState
                ?->next_action_at;

        return [
            'Empresa' => trim(
                (string)
                $company
                    ->corporate_name
            ),

            'CNPJ' => trim(
                (string) (
                    $matrix
                        ->cnpj
                    ?? $company
                        ->cnpj_root
                )
            ),

            'Cidade' => trim(
                (string) (
                    $matrix
                        ->municipality_name
                    ?? ''
                )
            ),

            'UF' => trim(
                (string) (
                    $matrix
                        ->state
                    ?? ''
                )
            ),

            'Score SDR' => (string) (
                $company
                    ->sdrScore
                    ->score
                ?? ''
            ),

            'Prioridade' => $this
                ->priorityLabel(
                    $company
                        ->sdrScore
                        ?->priority
                ),

            'ICP' => trim(
                (string) (
                    $company
                        ->icpScore
                        ->grade
                    ?? ''
                )
            ),

            'Responsável' => trim(
                (string) (
                    $workState
                        ?->assignedUser
                        ->name
                    ?? 'Sem responsável'
                )
            ),

            'Status comercial' => $this
                ->workStatusLabel(
                    $lead
                        ?->work_status
                ),

            'Status CRM' => $this
                ->crmStatusLabel(
                    $company
                        ->crmCheck
                        ?->status
                ),

            'Próxima ação' => $nextAction !== null
                    ? CarbonImmutable::parse(
                        (string) $nextAction
                    )
                        ->timezone(
                            config(
                                'app.timezone'
                            )
                        )
                        ->format(
                            'd/m/Y H:i'
                        )
                    : '',

            'Telefones' => implode(
                ' | ',
                $phones
            ),

            'E-mails' => implode(
                ' | ',
                $emails
            ),

            'Exportação' => $this
                ->exportLabel(
                    $company
                ),

            'Resumo exportação' => trim(
                (string) (
                    $export
                        ->overall_summary
                    ?? ''
                )
            ),

            'HubSpot Company ID' => trim(
                (string) (
                    $lead
                        ->hubspot_company_id
                    ?? ''
                )
            ),

            'HubSpot Deal ID' => trim(
                (string) (
                    $lead
                        ->hubspot_deal_id
                    ?? ''
                )
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function phones(
        Company $company
    ): array {
        /**
         * @var array<string, string> $phones
         */
        $phones = [];

        foreach (
            $company
                ->establishments as $establishment
        ) {
            foreach (
                [
                    $establishment
                        ->phone_1,

                    $establishment
                        ->phone_2,

                    $establishment
                        ->fax,
                ] as $value
            ) {
                $phone =
                    trim(
                        (string) $value
                    );

                if ($phone === '') {
                    continue;
                }

                $digits =
                    preg_replace(
                        '/\D/',
                        '',
                        $phone
                    );

                if (
                    ! is_string(
                        $digits
                    )
                    || $digits === ''
                ) {
                    continue;
                }

                $phones[
                    $digits
                ] =
                    $phone;
            }
        }

        return array_values(
            $phones
        );
    }

    /**
     * @return list<string>
     */
    private function emails(
        Company $company
    ): array {
        /**
         * @var array<string, string> $emails
         */
        $emails = [];

        foreach (
            $company
                ->establishments as $establishment
        ) {
            $email =
                mb_strtolower(
                    trim(
                        (string)
                        $establishment
                            ->email
                    )
                );

            if (
                $email === ''
                || filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                ) === false
            ) {
                continue;
            }

            $emails[
                $email
            ] =
                $email;
        }

        return array_values(
            $emails
        );
    }

    private function workStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'contacting' => 'Em contato',

            'waiting' => 'Aguardando retorno',

            'future' => 'Oportunidade futura',

            'reprospecting' => 'Reprospecção',

            'refused' => 'Recusou',

            'converted' => 'Convertido',

            'discarded' => 'Descartado',

            default => 'Novo',
        };
    }

    private function priorityLabel(
        ?string $priority
    ): string {
        return match ($priority) {
            'very_high' => 'Muito alta',

            'high' => 'Alta',

            'medium' => 'Média',

            'low' => 'Baixa',

            default => '',
        };
    }

    private function crmStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'not_found' => 'Não encontrado',

            'known' => 'Conhecido no CRM',

            'prospected' => 'Prospectado',

            'opportunity' => 'Oportunidade',

            'client' => 'Cliente',

            default => trim(
                (string) $status
            ),
        };
    }

    private function exportLabel(
        Company $company
    ): string {
        $intelligence =
            $company
                ->exportIntelligence;

        if ($intelligence === null) {
            return 'Não pesquisado';
        }

        $signals = [];

        if (
            $intelligence
                ->direct_confirmed
        ) {
            $signals[] =
                'Direta confirmada';

        } elseif (
            $intelligence
                ->direct_status
            === 'positive'
        ) {
            $signals[] =
                'Direta identificada';
        }

        if (
            $intelligence
                ->indirect_confirmed
        ) {
            $signals[] =
                'Indireta confirmada';

        } elseif (
            $intelligence
                ->indirect_status
            === 'positive'
        ) {
            $signals[] =
                'Indireta identificada';
        }

        if (
            $intelligence
                ->trading_confirmed
        ) {
            $signals[] =
                'Trading confirmada';

        } elseif (
            $intelligence
                ->trading_status
            === 'positive'
        ) {
            $signals[] =
                'Trading identificada';
        }

        if ($signals !== []) {
            return implode(
                ' | ',
                $signals
            );
        }

        if (
            $intelligence
                ->research_status
            === 'completed'
        ) {
            return 'Sem evidência positiva';
        }

        return 'Pesquisa '
            .trim(
                (string)
                $intelligence
                    ->research_status
            );
    }

    private function safeSpreadsheetText(
        mixed $value
    ): string {
        $text =
            trim(
                (string) $value
            );

        /*
         * Excel interpreta estes prefixos
         * como fórmula.
         *
         * Prefixamos apóstrofo para impedir
         * execução de conteúdo vindo da base.
         */
        if (
            preg_match(
                '/^[=+\-@]/',
                $text
            ) === 1
        ) {
            return "'".$text;
        }

        return $text;
    }
}
