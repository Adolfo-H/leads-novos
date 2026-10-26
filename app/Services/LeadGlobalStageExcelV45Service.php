<?php

namespace App\Services;

use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Support\Cnpj;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uma linha por NEGÓCIO da etapa selecionada, mesmo sem vínculo fiscal.
 * Somente o chamador determina o recorte autorizado de negócios.
 */
final class LeadGlobalStageExcelV45Service
{
    // EC_PROSPECTOR_V51_CONTACT_COLUMNS: coluna L Nome do contato, M Telefones, N E-mails.
    private const HEADERS = [
        'Empresa', 'CNPJ', 'Cidade', 'UF', 'Score SDR', 'Prioridade', 'ICP',
        'Responsável', 'Status comercial', 'Status CRM', 'Próxima ação',
        'Nome do contato', 'Telefones', 'E-mails', 'Exportação', 'Resumo exportação',
        'HubSpot Company ID', 'HubSpot Deal ID', 'Etapas HubSpot',
        'Origem', 'Última atividade HubSpot',
    ];

    /**
     * @param Collection<int, HubSpotDeal> $deals
     */
    public function excel(Collection $deals, ?string $stageLabel = null): StreamedResponse
    {
        // A consulta chega filtrada no backend. Não acessar a API remota.
        $models = new EloquentCollection($deals->all());
        // EC_PROSPECTOR_V50_2: recarregar associações atualizadas do banco em cada exportação.
        // loadMissing reutiliza a relação vazia armazenada em um modelo exportado antes.
        $models->load([
            'companies.prospectorCompany.matrix', 'companies.prospectorCompany.hubSpotLead',
            'companies.contacts', 'contacts', 'stage',
        ]);

        // EC_PROSPECTOR_V52: rótulo comercial idêntico às duas listas HubSpot.
        $v52FiscalCompanies = $models
            ->flatMap(static fn ($deal) => $deal->companies)
            ->filter(static fn ($hub) => $hub->hasTrustedFiscalLink() && $hub->prospectorCompany !== null)
            ->map(static fn ($hub) => $hub->prospectorCompany)
            ->unique('id')->values();
        $v52Statuses = app(LeadCommercialSituationV52Service::class)->forCompanies($v52FiscalCompanies);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Negócios HubSpot');

        foreach (self::HEADERS as $index => $name) {
            $sheet->setCellValueExplicit([$index + 1, 1], $name, DataType::TYPE_STRING);
        }

        $contactExporter = app(HubSpotContactExportV50Service::class);
        $rowNumber = 2;
        foreach ($models as $deal) {
            $company = $deal->companies->first(
                static fn (HubSpotCompany $item): bool => (bool) data_get($item, 'pivot.is_primary', false)
            ) ?? $deal->companies->first();

            // CNPJ apenas se o vínculo fiscal foi confirmado de forma confiável.
            $fiscal = $company instanceof HubSpotCompany && $company->hasTrustedFiscalLink()
                ? $company->prospectorCompany : null;
            $matrix = $fiscal?->matrix;
            $cnpj = trim((string) data_get($matrix, 'cnpj', ''));
            $people = $contactExporter->forHubSpotDeal($deal, $company);
            $v52Status = $fiscal !== null
                ? ($v52Statuses[(int) $fiscal->id] ?? '')
                : ($deal->is_closed_won ? 'client' : '');

            $row = [
                'Empresa' => (string) ($company?->name ?: $deal->name ?: 'Negócio sem empresa vinculada'),
                'CNPJ' => $cnpj !== '' ? Cnpj::format($cnpj) : '',
                'Cidade' => (string) ($matrix?->municipality_name ?: $company?->city ?: ''),
                'UF' => (string) ($matrix?->state ?: $company?->state ?: ''),
                'Score SDR' => '',
                'Prioridade' => '',
                'ICP' => '',
                'Responsável' => (string) ($deal->owner_name ?: 'Não informado'),
                'Status comercial' => match ($v52Status) {
                    'client' => 'Cliente',
                    'known' => 'Conhecido',
                    'reprospecting' => 'Reprospecção',
                    default => '',
                },
                'Status CRM' => $fiscal !== null ? 'Vínculo fiscal confirmado' : 'HubSpot sem vínculo fiscal',
                'Próxima ação' => '',
                'Nome do contato' => $people['names'],
                'Telefones' => $contactExporter->withAssociatedContacts((string) data_get($company, 'phone', ''), $people['namedPhones']),
                'E-mails' => $people['namedEmails'],
                'Exportação' => '',
                'Resumo exportação' => '',
                'HubSpot Company ID' => $deal->companies->pluck('hubspot_id')->filter()->unique()->implode(' | '),
                'HubSpot Deal ID' => (string) $deal->hubspot_id,
                'Etapas HubSpot' => (string) ($stageLabel ?: $deal->stage?->stage_label ?: $deal->stage_label ?: ''),
                'Origem' => 'HubSpot · negócio da etapa',
                'Última atividade HubSpot' => $this->displayDate($deal->last_activity_at),
            ];

            foreach (self::HEADERS as $column => $name) {
                $value = (string) $row[$name];
                if (preg_match('/^\s*[=+@\-]/u', $value) === 1) {
                    $value = "'".$value;
                }
                $sheet->setCellValueExplicit([$column + 1, $rowNumber], $value, DataType::TYPE_STRING);
            }
            $rowNumber++;
        }

        $sheet->getStyle('A1:U1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:U1');
        foreach (range('A', 'U') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getStyle('A1:U'.max(1, $rowNumber - 1))->getAlignment()->setWrapText(true);

        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                try {
                    (new Xlsx($spreadsheet))->save('php://output');
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            },
            'negocios-hubspot-etapa-'.now()->format('Y-m-d_H-i-s').'.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            ],
        );
    }

    /**
     * O espelho hubspot_deals mantém last_activity_at sem cast Eloquent.
     * O valor pode ser string (PostgreSQL) ou DateTimeInterface.
     * Datas inválidas não devem interromper a exportação.
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
}
