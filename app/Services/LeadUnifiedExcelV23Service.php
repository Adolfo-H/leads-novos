<?php

namespace App\Services;

use App\Models\Company;
use App\Models\HubSpotCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Export precisely the selected Companies and/or HubSpot-only Companies. */
final class LeadUnifiedExcelV23Service
{
    private const HEADERS = [
        'Empresa', 'CNPJ', 'Cidade', 'UF', 'Score SDR', 'Prioridade', 'ICP',
        'Responsável', 'Status comercial', 'Status CRM', 'Próxima ação',
        'Telefones', 'E-mails', 'Exportação', 'Resumo exportação',
        'HubSpot Company ID', 'HubSpot Deal ID', 'Etapas HubSpot',
        'Origem', 'Última atividade HubSpot',
    ];

    /**
     * @param  Collection<int, Company>  $companies
     * @param  Collection<int, HubSpotCompany>  $crmOnlyCompanies
     */
    public function excel(Collection $companies, Collection $crmOnlyCompanies): StreamedResponse
    {
        // EXCEL_BULK_EAGER_LOAD_V18_2
        // A tela entrega Illuminate\Support\Collection. Uma coleção Eloquent
        // temporária permite eager loading em lote sem trocar nem reordenar
        // os objetos originais usados pelas linhas do Excel.
        (new \Illuminate\Database\Eloquent\Collection($companies->all()))
            ->loadMissing([
                'establishments',
                'matrix',
                'icpScore',
                'crmCheck',
                'sdrScore',
                'exportIntelligence',
                'hubSpotLead',
                'leadWorkState.assignedUser',
                'hubSpotCompanies.commercialDeals',
            ]);

        (new \Illuminate\Database\Eloquent\Collection($crmOnlyCompanies->all()))
            ->loadMissing(['deals', 'contacts', 'tasks']);

        $normal = app(LeadExportService::class)->rows($companies);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Leads');
        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
        }
        $lineNumber = 2;
        foreach ($normal as $idx => $row) {
            $row['Origem'] = 'Prospector / CNPJ';
            // Company snapshot and mirrored deals are preserved as distinct sources.
            $company = $companies->get($idx);
            if ($company instanceof Company) {
                $company->loadMissing(['hubSpotCompanies.commercialDeals']);
                $stages = $company->hubSpotCompanies
                    ->flatMap(static fn ($linked) => $linked->commercialDeals->pluck('stage_label'))
                    ->filter()->unique();
                // Os metadados do CRM podem estar convertidos ou em JSON bruto.
                $metadata = $company->crmCheck?->getAttribute('metadata');
                if (is_string($metadata)) {
                    $decoded = json_decode($metadata, true);
                    $metadata = is_array($decoded) ? $decoded : [];
                }
                $metadataDeals = is_array($metadata) ? ($metadata['deals'] ?? []) : [];
                if (is_array($metadataDeals)) {
                    foreach ($metadataDeals as $item) {
                        if (is_array($item) && trim((string) ($item['stage_label'] ?? '')) !== '') {
                            $stages->push(trim((string) $item['stage_label']));
                        }
                    }
                }
                $row['Etapas HubSpot'] = $stages->unique()->implode(' | ');
                $row['Última atividade HubSpot'] = $this->displayDate(
                    $company->hubSpotLead?->getAttribute('last_activity_at')
                );
            }
            $this->writeRow($sheet, $lineNumber++, $row);
        }
        foreach ($crmOnlyCompanies as $company) {
            $this->writeRow($sheet, $lineNumber++, $this->hubSpotRow($company));
        }
        $lastCol = 'T';
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastCol.'1');
        foreach (range('A', $lastCol) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getStyle('A1:'.$lastCol.max(1, $lineNumber - 1))
            ->getAlignment()->setWrapText(true);

        return response()->streamDownload(static function () use ($spreadsheet): void {
            // XLSX_STREAM_CLEANUP_V18_6: liberar a planilha mesmo se o escritor falhar.
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, 'leads-filtrados-'.now()->format('Y-m-d_H-i-s').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    /** @return array<string,string> */
    public function hubSpotRow(HubSpotCompany $company): array
    {
        $company->loadMissing(['deals', 'contacts', 'tasks']);
        $stages = $company->deals->pluck('stage_label')->filter()->unique()->implode(' | ');
        $emails = $company->contacts->pluck('email')->filter()->unique()->implode(' | ');
        // Inclui o telefone da Company e dos contatos sem violar o tipo do Collection.
        $phoneValues = [(string) ($company->phone ?? '')];
        foreach ($company->contacts as $contact) {
            $phoneValues[] = (string) ($contact->phone ?? '');
            $phoneValues[] = (string) ($contact->mobile_phone ?? '');
        }
        $phones = collect($phoneValues)
            ->map(static fn (string $phone): string => trim($phone))
            ->filter(static fn (string $phone): bool => $phone !== '')
            ->unique()
            ->implode(' | ');
        $openTasks = $company->tasks->filter(static fn ($task): bool => (bool) $task->is_open && $task->completed_at === null);
        $nextDue = $openTasks->pluck('due_at')->filter()->sort()->first();
        $nextDueText = $this->displayDate($nextDue);
        $lastActivity = $this->displayDate($company->getAttribute('last_activity_at'));

        return [
            'Empresa' => (string) ($company->name ?: 'Empresa sem nome'),
            'CNPJ' => '', // No trusted fiscal identity: never guess or inherit CNPJ.
            'Cidade' => (string) ($company->city ?? ''),
            'UF' => (string) ($company->state ?? ''),
            'Score SDR' => '',
            'Prioridade' => '',
            'ICP' => '',
            'Responsável' => (string) ($company->owner_name ?: 'Não informado'),
            'Status comercial' => (string) ($company->lead_status ?? ''),
            'Status CRM' => 'HubSpot sem vínculo fiscal',
            'Próxima ação' => $nextDueText,
            'Telefones' => $phones,
            'E-mails' => $emails,
            'Exportação' => '',
            'Resumo exportação' => '',
            'HubSpot Company ID' => (string) $company->hubspot_id,
            'HubSpot Deal ID' => $company->deals->pluck('hubspot_id')->filter()->unique()->implode(' | '),
            'Etapas HubSpot' => $stages,
            'Origem' => 'HubSpot sem CNPJ',
            'Última atividade HubSpot' => $lastActivity,
        ];
    }

    /**
     * Datas podem vir convertidas pelo Eloquent ou como texto do banco.
     * Retorna vazio para ausência ou valor inválido, sem quebrar a exportação.
     */
    private function displayDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y H:i');
        }
        if (! is_string($value) || trim($value) === '') {
            return '';
        }
        try {
            return CarbonImmutable::parse($value)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string,string> $row */
    private function writeRow(Worksheet $sheet, int $line, array $row): void
    {
        foreach (self::HEADERS as $index => $header) {
            $value = (string) ($row[$header] ?? '');
            // Explicit text preserves CNPJ and prevents spreadsheet formula injection.
            if (preg_match('/^\s*[=+@\-]/u', $value) === 1) {
                $value = "'".$value;
            }
            $sheet->setCellValueExplicit([$index + 1, $line], $value, DataType::TYPE_STRING);
        }
    }
}
