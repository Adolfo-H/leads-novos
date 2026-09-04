<?php

namespace App\Services;

use App\Jobs\ResearchCompanyExports;
use App\Models\Company;
use App\Models\CompanyExportIntelligence;

final class ExportResearchQueueService
{
    public function __construct(
        private readonly ExportResearchEligibilityService $eligibility,
        private readonly ExportIntelligenceService $intelligence,
    ) {}

    public function dispatch(
        Company $company,
        bool $force = false,
    ): CompanyExportIntelligence {
        $intelligence =
            $this->intelligence->ensure(
                $company
            );

        if (! $force) {
            $evaluation =
                $this->eligibility
                    ->evaluate(
                        $company
                    );

            if (
                ! $evaluation[
                    'eligible'
                ]
            ) {
                $metadata =
                    $this->metadata(
                        $intelligence
                    );

                $metadata[
                    'research_eligibility'
                ] = $evaluation;

                $intelligence->update([
                    'research_status' => 'skipped',

                    'research_error' => null,

                    'research_completed_at' => now(),

                    'metadata' => $metadata,
                ]);

                return $intelligence
                    ->refresh();
            }
        }

        $intelligence->update([
            'research_status' => 'queued',

            'research_error' => null,

            'research_started_at' => null,

            'research_completed_at' => null,
        ]);

        ResearchCompanyExports::dispatch(
            companyId: $company->id,
            force: $force,
        );

        return $intelligence
            ->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(
        CompanyExportIntelligence $intelligence,
    ): array {
        $raw =
            $intelligence->getAttribute(
                'metadata'
            );

        return is_array($raw)
            ? $raw
            : [];
    }
}
