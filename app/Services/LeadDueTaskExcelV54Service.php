<?php

namespace App\Services;

use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Support\Cnpj;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Uma linha por tarefa autorizada; mantém as 21 colunas da V51. */
final class LeadDueTaskExcelV54Service
{
    private const HEADERS = [
        'Empresa', 'CNPJ', 'Cidade', 'UF', 'Score SDR', 'Prioridade', 'ICP',
        'Responsável', 'Status comercial', 'Status CRM', 'Próxima ação',
        'Nome do contato', 'Telefones', 'E-mails', 'Exportação', 'Resumo exportação',
        'HubSpot Company ID', 'HubSpot Deal ID', 'Etapas HubSpot',
        'Origem', 'Última atividade HubSpot',
    ];

    /** @param Collection<int, HubSpotTask> $tasks */
    public function excel(Collection $tasks): StreamedResponse
    {
        (new \Illuminate\Database\Eloquent\Collection($tasks->all()))->loadMissing([
            'companies.prospectorCompany.matrix',
            'companies.prospectorCompany.sdrScore',
            'companies.prospectorCompany.icpScore',
            'companies.prospectorCompany.exportIntelligence',
            'deals.companies.prospectorCompany.matrix',
            'contacts',
        ]);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Tarefas HubSpot');
        foreach (self::HEADERS as $col => $header) {
            $sheet->setCellValueExplicit([$col + 1, 1], $header, DataType::TYPE_STRING);
        }

        $rowIndex = 2;
        foreach ($tasks as $task) {
            $row = $this->taskRow($task);
            foreach (self::HEADERS as $col => $header) {
                $value = trim((string) ($row[$header] ?? ''));
                if (preg_match('/^[=+@\-]/u', $value) === 1) {
                    $value = "'".$value;
                }
                $sheet->setCellValueExplicit([$col + 1, $rowIndex], $value, DataType::TYPE_STRING);
            }
            $rowIndex++;
        }

        $sheet->getStyle('A1:U1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:U1');
        foreach (range('A', 'U') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getStyle('A1:U'.max(1, $rowIndex - 1))->getAlignment()->setWrapText(true);

        return response()->streamDownload(static function () use ($book): void {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'tarefas-hubspot-'.now()->format('Y-m-d_H-i-s').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    /** @return array<string, string> */
    public function taskRow(HubSpotTask $task): array
    {
        $task->loadMissing([
            'companies.prospectorCompany.matrix',
            'companies.prospectorCompany.sdrScore',
            'companies.prospectorCompany.icpScore',
            'deals.companies.prospectorCompany.matrix',
            'contacts',
        ]);

        $hub = $task->companies->first(static fn (HubSpotCompany $item): bool => $item->hasTrustedFiscalLink())
            ?? $task->companies->first();
        $deal = $task->deals->first();
        if ($hub === null) {
            $hub = $deal?->companies->first(static fn ($item): bool => (bool) data_get($item, 'pivot.is_primary', false))
                ?? $deal?->companies->first();
        }
        $company = $hub?->hasTrustedFiscalLink() ? $hub->prospectorCompany : null;
        $matrix = $company?->matrix;
        $name = trim((string) ($company?->corporate_name ?: $hub?->name ?: ''));
        $title = trim((string) $task->title) ?: 'Tarefa sem título';
        // EC_PROSPECTOR_V54_3: resolver data para um objeto tipado.
        // O cast Eloquent retorna DateTimeInterface em runtime, mas o dado cru
        // pode ser string; não chamar métodos de data diretamente em string.
        $rawDueAt = $task->getAttribute('due_at');
        $dueAt = null;
        if ($rawDueAt instanceof DateTimeInterface) {
            $dueAt = CarbonImmutable::instance($rawDueAt);
        } elseif (is_string($rawDueAt) && trim($rawDueAt) !== '') {
            try {
                $dueAt = CarbonImmutable::parse($rawDueAt);
            } catch (\Throwable) {
                $dueAt = null;
            }
        }
        $names = [];
        $phones = [];
        $emails = [];

        foreach ($task->contacts as $contact) {
            $person = trim(trim((string) $contact->first_name).' '.trim((string) $contact->last_name));
            $person = $person !== '' ? $person : trim((string) $contact->email);
            if ($person !== '') {
                $names[$person] = $person;
            }
            foreach ([$contact->phone, $contact->mobile_phone] as $phone) {
                $phone = trim((string) $phone);
                if ($phone !== '') {
                    $phones[$person.'|'.$phone] = $person !== '' ? $person.': '.$phone : $phone;
                }
            }
            $email = trim((string) $contact->email);
            if ($email !== '') {
                $emails[$person.'|'.$email] = $person !== '' ? $person.': '.$email : $email;
            }
        }
        $companyPhone = trim((string) ($hub->phone ?? ''));
        if ($companyPhone !== '') {
            $phones['company|'.$companyPhone] = 'Empresa: '.$companyPhone;
        }

        return [
            'Empresa' => $name !== '' ? $name : 'Tarefa sem empresa vinculada',
            'CNPJ' => $matrix?->cnpj ? Cnpj::format((string) $matrix->cnpj) : '',
            'Cidade' => (string) ($matrix?->municipality_name ?: $hub?->city ?: ''),
            'UF' => (string) ($matrix?->state ?: $hub?->state ?: ''),
            'Score SDR' => (string) data_get($company, 'sdrScore.score', ''),
            'Prioridade' => (string) data_get($company, 'sdrScore.priority', ''),
            'ICP' => (string) data_get($company, 'icpScore.grade', ''),
            'Responsável' => (string) ($task->assigned_to ?: ''),
            'Status comercial' => $dueAt === null ? 'Sem prazo' : ($dueAt->lte(now()) ? 'Atrasada' : 'Vence hoje'),
            'Status CRM' => 'Tarefa aberta no HubSpot',
            'Próxima ação' => $title.' · '.($dueAt !== null ? $dueAt->format('d/m/Y H:i') : ''),
            'Nome do contato' => implode(' | ', array_values($names)),
            'Telefones' => implode(' | ', array_values($phones)),
            'E-mails' => implode(' | ', array_values($emails)),
            'Exportação' => '',
            'Resumo exportação' => '',
            'HubSpot Company ID' => (string) ($hub->hubspot_id ?? ''),
            'HubSpot Deal ID' => (string) ($deal->hubspot_id ?? ''),
            'Etapas HubSpot' => (string) ($deal->stage_label ?? ''),
            'Origem' => 'Tarefa HubSpot #'.$task->hubspot_id,
            'Última atividade HubSpot' => '',
        ];
    }
}
