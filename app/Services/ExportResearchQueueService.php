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

    /**
     * Avalia a empresa e somente coloca a
     * pesquisa na fila quando:
     *
     * - a pesquisa automática está ligada;
     * - a empresa passou pela peneira.
     *
     * @return array{
     *     enabled: bool,
     *     eligible: bool,
     *     queued: bool,
     *     reason: string,
     *     message: string
     * }
     */
    public function dispatchIfEnabled(
        Company $company
    ): array {
        $evaluation =
            $this->eligibility
                ->evaluate(
                    $company
                );

        $enabled =
            (bool) config(
                'prospector.export_research.enabled',
                false
            );

        if (
            ! $enabled
            || ! $evaluation[
                'eligible'
            ]
        ) {
            return [
                'enabled' => $enabled,

                'eligible' => $evaluation[
                        'eligible'
                    ],

                'queued' => false,

                'reason' => $evaluation[
                        'reason'
                    ],

                'message' => $evaluation[
                        'message'
                    ],
            ];
        }

        $this->dispatch(
            $company
        );

        return [
            'enabled' => true,

            'eligible' => true,

            'queued' => true,

            'reason' => $evaluation[
                    'reason'
                ],

            'message' => $evaluation[
                    'message'
                ],
        ];
    }

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
