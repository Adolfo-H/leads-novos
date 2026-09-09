<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyExportIntelligence;
use App\Services\ExportIntelligenceScoringService;
use App\Services\ExportResearchSummaryService;
use App\Services\SdrScoringService;
use Illuminate\Console\Command;

final class RecalculateExportScoring extends Command
{
    protected $signature =
        'exports:recalculate-scoring
        {--company-id= : Recalcula apenas uma empresa pelo ID}';

    protected $description =
        'Recalcula a inteligência de exportação usando evidências já armazenadas.';

    public function handle(
        ExportIntelligenceScoringService $scoring,
        ExportResearchSummaryService $summaryService,
        SdrScoringService $sdrScoring,
    ): int {
        $query = Company::query()
            ->whereHas(
                'exportEvidence'
            );

        $companyId =
            $this->option(
                'company-id'
            );

        if (
            is_string($companyId)
            && trim($companyId) !== ''
        ) {
            if (
                ! ctype_digit($companyId)
                || (int) $companyId < 1
            ) {
                $this->components->error(
                    'O --company-id deve ser um ID numérico válido.'
                );

                return self::FAILURE;
            }

            $query->whereKey(
                (int) $companyId
            );
        }

        $total =
            (clone $query)
                ->count();

        if ($total === 0) {
            $this->components->info(
                'Nenhuma empresa com evidências para recalcular.'
            );

            return self::SUCCESS;
        }

        $this->components->info(
            $total === 1
                ? '1 empresa será recalculada.'
                : $total.' empresas serão recalculadas.'
        );

        $processed = 0;

        $query->chunkById(
            100,
            function ($companies) use (
                $scoring,
                $summaryService,
                $sdrScoring,
                &$processed,
            ): void {
                foreach ($companies as $company) {
                    $intelligence =
                        $scoring
                            ->recalculateStoredEvidence(
                                $company
                            );

                    $this->refreshSummary(
                        company: $company,
                        intelligence: $intelligence,
                        summaryService: $summaryService,
                    );

                    /*
                     * A classificação mudou.
                     * O SDR precisa enxergar o novo
                     * estado da inteligência.
                     */
                    $company->unsetRelation(
                        'exportIntelligence'
                    );

                    $sdrScoring->recalculate(
                        $company
                    );

                    $processed++;
                }
            }
        );

        $this->components->info(
            $processed === 1
                ? '1 empresa recalculada com sucesso.'
                : $processed.' empresas recalculadas com sucesso.'
        );

        return self::SUCCESS;
    }

    private function refreshSummary(
        Company $company,
        CompanyExportIntelligence $intelligence,
        ExportResearchSummaryService $summaryService,
    ): void {
        /*
         * O resumo também guarda confiança
         * por dimensão.
         *
         * Portanto ele precisa ser reconstruído
         * depois do scoring, usando somente as
         * evidências locais.
         *
         * Nenhuma pesquisa externa é executada.
         */
        $summary =
            $summaryService->build(
                company: $company,
                intelligence: $intelligence,
            );

        $rawMetadata =
            $intelligence->getAttribute(
                'metadata'
            );

        /** @var array<string, mixed> $metadata */
        $metadata =
            is_array($rawMetadata)
                ? $rawMetadata
                : [];

        $metadata[
            'research_summary'
        ] = $summary;

        $intelligence->update([
            'overall_summary' => $summary[
                    'headline'
                ],

            'metadata' => $metadata,
        ]);
    }
}
