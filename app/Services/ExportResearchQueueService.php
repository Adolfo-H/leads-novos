<?php

namespace App\Services;

use App\Jobs\ResearchCompanyExports;
use App\Models\Company;
use App\Models\CompanyExportIntelligence;
use Illuminate\Support\Str;
use Throwable;

final class ExportResearchQueueService
{
    public function __construct(
        private readonly ExportResearchEligibilityService $eligibility,
        private readonly ExportIntelligenceService $intelligence,
    ) {}

    /**
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

        $intelligence =
            $this->dispatch(
                $company
            );

        $queued =
            $intelligence
                ->research_status
                === 'queued';

        return [
            'enabled' => true,

            'eligible' => true,

            'queued' => $queued,

            'reason' => $queued
                    ? $evaluation[
                        'reason'
                    ]
                    : 'dispatch_failed',

            'message' => $queued
                    ? $evaluation[
                        'message'
                    ]
                    : (
                        $intelligence
                            ->research_error
                        ?: 'Não foi possível enviar '
                            .'a pesquisa para a fila.'
                    ),
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

        return $this->dispatchResearch(
            company: $company,
            intelligence: $intelligence,
            force: $force,
            recovery: false,
        );
    }

    public function recoverStale(
        int $afterMinutes = 20,
        int $limit = 100,
    ): int {
        $afterMinutes =
            max(
                1,
                $afterMinutes
            );

        $limit =
            max(
                1,
                min(
                    500,
                    $limit
                )
            );

        $cutoff =
            now()
                ->subMinutes(
                    $afterMinutes
                );

        $researches =
            CompanyExportIntelligence::query()
                ->with('company')
                ->whereIn(
                    'research_status',
                    [
                        'queued',
                        'processing',
                        'failed',
                    ]
                )
                ->where(
                    'updated_at',
                    '<=',
                    $cutoff
                )
                ->orderBy('id')
                ->limit(
                    $limit
                )
                ->get();

        $recovered = 0;

        foreach ($researches as $intelligence) {
            $queueMetadata =
                $this->queueMetadata(
                    $intelligence
                );

            $activeStale =
                in_array(
                    $intelligence
                        ->research_status,
                    [
                        'queued',
                        'processing',
                    ],
                    true
                );

            /*
             * Um FAILED comum significa que
             * a própria pesquisa esgotou seus
             * retries.
             *
             * Só recuperamos FAILED automaticamente
             * quando sabemos que a falha ocorreu
             * antes mesmo de o job entrar na fila.
             */
            $dispatchFailed =
                $intelligence
                    ->research_status
                    === 'failed'
                && isset(
                    $queueMetadata[
                        'last_dispatch_error'
                    ]
                );

            if (
                ! $activeStale
                && ! $dispatchFailed
            ) {
                continue;
            }

            $company =
                $intelligence
                    ->company;

            if (! $company) {
                continue;
            }

            $force =
                (bool) (
                    $queueMetadata[
                        'force'
                    ]
                    ?? false
                );

            $result =
                $this->dispatchResearch(
                    company: $company,
                    intelligence: $intelligence,
                    force: $force,
                    recovery: true,
                );

            if (
                $result
                    ->research_status
                === 'queued'
            ) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function dispatchResearch(
        Company $company,
        CompanyExportIntelligence $intelligence,
        bool $force,
        bool $recovery,
    ): CompanyExportIntelligence {
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

        return $this->enqueue(
            company: $company,
            intelligence: $intelligence,
            force: $force,
            recovery: $recovery,
        );
    }

    private function enqueue(
        Company $company,
        CompanyExportIntelligence $intelligence,
        bool $force,
        bool $recovery,
    ): CompanyExportIntelligence {
        $metadata =
            $this->metadata(
                $intelligence
            );

        $queueMetadata =
            $this->queueMetadata(
                $intelligence
            );

        $previousStatus =
            $intelligence
                ->research_status;

        unset(
            $queueMetadata[
                'last_dispatch_error'
            ]
        );

        /*
         * Cada envio recebe uma geração única.
         *
         * Se uma pesquisa antiga reaparecer na
         * fila depois de um recovery, o job
         * antigo perceberá que ficou obsoleto
         * e não fará outra chamada ao provider.
         */
        $queueToken =
            (string) Str::uuid();

        $queueMetadata[
            'queue_token'
        ] = $queueToken;

        $queueMetadata[
            'force'
        ] = $force;

        $queueMetadata[
            'last_enqueued_at'
        ] =
            now()
                ->toIso8601String();

        if ($recovery) {
            $queueMetadata[
                'recovery_count'
            ] =
                (int) (
                    $queueMetadata[
                        'recovery_count'
                    ]
                    ?? 0
                )
                + 1;

            $queueMetadata[
                'last_recovered_at'
            ] =
                now()
                    ->toIso8601String();

            $queueMetadata[
                'previous_status'
            ] =
                $previousStatus;
        } else {
            $queueMetadata[
                'recovery_count'
            ] ??= 0;
        }

        $metadata[
            'research_queue'
        ] = $queueMetadata;

        $intelligence->update([
            'research_status' => 'queued',

            'research_error' => null,

            'research_started_at' => null,

            'research_completed_at' => null,

            'metadata' => $metadata,
        ]);

        try {
            ResearchCompanyExports::dispatch(
                companyId: $company->id,
                force: $force,
                queueToken: $queueToken,
            );

            return $intelligence
                ->refresh();
        } catch (Throwable $exception) {
            /*
             * O banco não deve afirmar que uma
             * pesquisa está na fila quando o
             * Redis rejeitou o dispatch.
             */
            $queueMetadata[
                'last_dispatch_error'
            ] =
                mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    1000
                );

            $metadata[
                'research_queue'
            ] = $queueMetadata;

            $intelligence->update([
                'research_status' => 'failed',

                'research_error' => 'Não foi possível enviar '
                    .'a pesquisa para a fila: '
                    .mb_substr(
                        $exception
                            ->getMessage(),
                        0,
                        1800
                    ),

                'research_completed_at' => now(),

                'metadata' => $metadata,
            ]);

            return $intelligence
                ->refresh();
        }
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

    /**
     * @return array<string, mixed>
     */
    private function queueMetadata(
        CompanyExportIntelligence $intelligence,
    ): array {
        $metadata =
            $this->metadata(
                $intelligence
            );

        $queue =
            data_get(
                $metadata,
                'research_queue',
                []
            );

        return is_array($queue)
            ? $queue
            : [];
    }
}
