<?php

namespace App\Services;

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use App\Models\CompanyExportIntelligence;

final class ExportResearchService
{
    public function __construct(
        private readonly ExportResearchQueryPlanner $planner,
        private readonly ExportIntelligenceService $intelligence,
    ) {}

    public function research(
        Company $company,
        ExportResearchProvider $provider,
    ): CompanyExportIntelligence {
        $queries =
            $this->planner->queries(
                $company
            );

        $findings =
            $provider->research(
                $company,
                $queries,
            );

        foreach (
            $findings as $finding
        ) {
            $metadata =
                $finding['metadata'];

            $metadata[
                'research_provider'
            ] = $provider->name();

            $metadata[
                'research_query_count'
            ] = count(
                $queries
            );

            $this->intelligence
                ->recordEvidence(
                    company: $company,

                    dimension: $finding[
                            'dimension'
                        ],

                    signal: $finding[
                            'signal'
                        ],

                    sourceType: $finding[
                            'source_type'
                        ],

                    evidenceText: $finding[
                            'evidence_text'
                        ],

                    confidence: $finding[
                            'confidence'
                        ],

                    confirmed: false,

                    sourceName: $finding[
                            'source_name'
                        ],

                    sourceUrl: $finding[
                            'source_url'
                        ],

                    title: $finding[
                            'title'
                        ],

                    metadata: $metadata,
                );
        }

        $result =
            $this->intelligence
                ->ensure(
                    $company
                );

        $result->update([
            'researched_at' => now(),
        ]);

        return $result->refresh();
    }
}
