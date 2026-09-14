<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyExportIntelligence;
use App\Models\ImportBatch;
use RuntimeException;

final class ProspectingRunDetailService
{
    public function __construct(
        private readonly ExportResearchSummaryService $exportSummary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        ImportBatch $batch
    ): array {
        if (
            $batch->source_type
            !== 'prospecting'
        ) {
            throw new RuntimeException(
                'O lote informado não pertence ao motor de prospecção.'
            );
        }

        $batch =
            $batch->fresh([
                'items.company.icpScore',
                'items.company.crmCheck',
                'items.company.sdrScore',
                'items.company.exportIntelligence',
            ])
            ?? $batch;

        $rawMetadata =
                $batch->getAttribute(
                    'metadata'
                );

        /** @var mixed $rawMetadata */
        $metadata =
            is_array(
                $rawMetadata
            )
                ? $rawMetadata
                : [];

        $prospecting =
            data_get(
                $metadata,
                'prospecting',
                []
            );

        if (! is_array($prospecting)) {
            $prospecting = [];
        }

        $knownCount =
            data_get(
                $prospecting,
                'known_roots_count'
            );

        if (! is_numeric($knownCount)) {
            $knownCount =
                data_get(
                    $prospecting,
                    'existing_roots_count',
                    0
                );
        }

        $filters =
            data_get(
                $prospecting,
                'filters',
                []
            );

        if (! is_array($filters)) {
            $filters = [];
        }

        $items = [];

        $leadCount = 0;
        $blockedCount = 0;
        $researchedCount = 0;
        $exportIdentifiedCount = 0;
        $failedCount = 0;

        foreach ($batch->items as $item) {
            /** @var Company|null $company */
            $company =
                $item->company;

            $icp =
                $company
                    ?->icpScore;

            $crm =
                $company
                    ?->crmCheck;

            $sdr =
                $company
                    ?->sdrScore;

            /** @var CompanyExportIntelligence|null $export */
            $export =
                $company
                    ?->exportIntelligence;

            if (
                $item->status
                === 'failed'
            ) {
                $failedCount++;
            }

            if (
                $sdr?->is_eligible
                === true
            ) {
                $leadCount++;
            }

            if (
                $sdr !== null
                && $sdr->is_eligible
                    === false
            ) {
                $blockedCount++;
            }

            if (
                $export?->research_status
                === 'completed'
            ) {
                $researchedCount++;
            }

            $exportIdentified =
                $export !== null
                && (
                    $export->direct_status
                        === 'yes'
                    || $export->indirect_status
                        === 'yes'
                    || $export->trading_status
                        === 'yes'
                );

            if ($exportIdentified) {
                $exportIdentifiedCount++;
            }

            $items[] = [
                'id' => $item->id,

                'row_number' => (int) $item->row_number,

                'cnpj' => (string) (
                    $item->normalized_cnpj
                    ?? $item->raw_cnpj
                    ?? ''
                ),

                'item_status' => (string) $item->status,

                'error' => $item->error_message,

                'company_uuid' => $company
                    ?->uuid,

                'company_name' => $company
                    ?->corporate_name,

                'icp_grade' => $icp
                    ?->grade,

                'icp_score' => $icp
                    ?->score,

                'crm_status' => $crm
                    ?->status,

                'crm_label' => $this->crmLabel(
                    $crm
                        ?->status
                ),

                'hubspot_url' => $this->hubSpotCompanyUrl(
                    $crm?->external_id,
                    $crm?->external_url,
                ),

                'export_research_status' => $export
                    ?->research_status,

                'export_label' => $this->exportLabel(
                    $company,
                    $export,
                ),

                'export_identified' => $exportIdentified,

                'sdr_score' => $sdr
                    ?->score,

                'sdr_priority' => $sdr
                    ?->priority,

                'sdr_eligible' => $sdr
                    ?->is_eligible,

                'blocked_reason' => $sdr
                    ?->blocked_reason,

                'outcome' => $this->outcome(
                    itemStatus: (string) $item->status,
                    company: $company,
                    crmStatus: $crm
                        ?->status,
                    sdrEligible: $sdr
                        ?->is_eligible,
                ),
            ];
        }

        return [
            'uuid' => (string) $batch->uuid,

            'status' => (string) $batch->status,

            'status_label' => $this->batchStatusLabel(
                (string) $batch->status
            ),

            'created_at' => $batch
                ->created_at
                ?->toIso8601String(),

            'created_label' => $batch
                ->created_at
                ?->format(
                    'd/m/Y H:i'
                ),

            'total_rows' => (int) $batch->total_rows,

            'processed_rows' => (int) $batch->processed_rows,

            'discovered_count' => (int) data_get(
                $prospecting,
                'discovered_count',
                0
            ),

            'known_count' => is_numeric(
                $knownCount
            )
                    ? (int) $knownCount
                    : 0,

            'new_candidates_count' => (int) data_get(
                $prospecting,
                'new_candidates_count',
                $batch->total_rows
            ),

            'lead_count' => $leadCount,

            'blocked_count' => $blockedCount,

            'researched_count' => $researchedCount,

            'export_identified_count' => $exportIdentifiedCount,

            'failed_count' => $failedCount,

            'filters' => $filters,

            'items' => $items,
        ];
    }

    private function hubSpotCompanyUrl(
        mixed $externalId,
        mixed $externalUrl,
    ): ?string {
        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        $companyId =
            is_string($externalId)
            || is_int($externalId)
                ? trim(
                    (string) $externalId
                )
                : '';

        /*
         * Sempre preferimos reconstruir a URL
         * usando o ID real da empresa no CRM.
         *
         * Isso também corrige registros antigos
         * que tenham external_url desatualizada.
         */
        if (
            $portalId !== ''
            && $companyId !== ''
        ) {
            return sprintf(
                'https://app.hubspot.com/contacts/%s/record/0-2/%s',
                rawurlencode(
                    $portalId
                ),
                rawurlencode(
                    $companyId
                ),
            );
        }

        if (
            is_string($externalUrl)
        ) {
            $externalUrl =
                trim(
                    $externalUrl
                );

            if (
                $externalUrl !== ''
                && filter_var(
                    $externalUrl,
                    FILTER_VALIDATE_URL
                ) !== false
            ) {
                return $externalUrl;
            }
        }

        return null;
    }

    private function crmLabel(
        ?string $status
    ): string {
        return match ($status) {
            'client' => 'Cliente',

            'opportunity' => 'Oportunidade',

            'prospected' => 'Prospectado',

            'known' => 'Conhecido',

            'not_found' => 'Novo no CRM',

            default => 'Não verificado',
        };
    }

    private function exportLabel(
        ?Company $company,
        ?CompanyExportIntelligence $export,
    ): string {
        if (
            $company === null
            || $export === null
        ) {
            return 'Não pesquisada';
        }

        if (
            $export->research_status
            !== 'completed'
        ) {
            return match (
                $export->research_status
            ) {
                'queued' => 'Na fila',

                'processing' => 'Pesquisando',

                'failed' => 'Pesquisa falhou',

                'skipped' => 'Pesquisa não executada',

                default => 'Não pesquisada',
            };
        }

        $summary =
            $this->exportSummary
                ->build(
                    $company,
                    $export,
                );

        $label =
            data_get(
                $summary,
                'assessment.label'
            );

        return is_string($label)
            && trim($label) !== ''
                ? $label
                : 'Pesquisa concluída';
    }

    private function outcome(
        string $itemStatus,
        ?Company $company,
        ?string $crmStatus,
        ?bool $sdrEligible,
    ): string {
        if ($itemStatus === 'failed') {
            return 'Falha';
        }

        if (
            in_array(
                $itemStatus,
                [
                    'ready',
                    'queued',
                    'processing',
                ],
                true
            )
        ) {
            return 'Processando';
        }

        if ($company === null) {
            return 'Pendente';
        }

        if ($sdrEligible === true) {
            return 'Lead';
        }

        if ($crmStatus === 'client') {
            return 'Cliente';
        }

        if ($crmStatus === 'opportunity') {
            return 'Oportunidade';
        }

        if ($sdrEligible === false) {
            return 'Bloqueado';
        }

        return 'Analisado';
    }

    private function batchStatusLabel(
        string $status
    ): string {
        return match ($status) {
            'completed' => 'Concluído',

            'processing' => 'Processando',

            'failed' => 'Falhou',

            'ready' => 'Pronto',

            default => ucfirst($status),
        };
    }
}
