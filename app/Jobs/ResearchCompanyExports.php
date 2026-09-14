<?php

namespace App\Jobs;

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use App\Models\CompanyExportIntelligence;
use App\Services\ExportIntelligenceService;
use App\Services\ExportResearchEligibilityService;
use App\Services\ExportResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ResearchCompanyExports implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(
        public int $companyId,
        public bool $force = false,
        public ?string $queueToken = null,
    ) {}

    /**
     * Impede duas pesquisas da mesma empresa
     * de executarem simultaneamente.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (
                new WithoutOverlapping(
                    'export-research-company-'
                    .$this->companyId
                )
            )
                ->dontRelease()
                ->expireAfter(
                    $this->timeout
                    + 60
                ),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [
            60,
            300,
        ];
    }

    public function handle(
        ExportResearchProvider $provider,
        ExportResearchService $research,
        ExportResearchEligibilityService $eligibility,
        ExportIntelligenceService $intelligenceService,
    ): void {
        $company =
            Company::query()
                ->findOrFail(
                    $this->companyId
                );

        $intelligence =
            $intelligenceService->ensure(
                $company
            );

        /*
         * Um recovery pode gerar um job novo
         * enquanto uma cópia antiga ainda
         * existe na fila.
         *
         * Somente a geração mais recente pode
         * executar a pesquisa.
         */
        if (
            ! $this->isCurrentQueueGeneration(
                $intelligence
            )
        ) {
            return;
        }

        /*
         * Pesquisa automática respeita as
         * regras comerciais.
         *
         * Uma pesquisa manual futura poderá
         * usar force=true.
         */
        if (! $this->force) {
            $evaluation =
                $eligibility->evaluate(
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

                    'research_provider' => $provider->name(),

                    'research_error' => null,

                    'research_completed_at' => now(),

                    'metadata' => $metadata,
                ]);

                return;
            }
        }

        $intelligence->update([
            'research_status' => 'processing',

            'research_provider' => $provider->name(),

            'research_error' => null,

            'research_started_at' => now(),

            'research_completed_at' => null,
        ]);

        try {
            $result =
                $research->research(
                    company: $company,
                    provider: $provider,
                );

            if (
                ! $this->isCurrentQueueGeneration(
                    $result
                )
            ) {
                return;
            }

            $result->update([
                'research_status' => 'completed',

                'research_provider' => $provider->name(),

                'research_error' => null,

                'research_completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            /*
             * Enquanto ainda houver retries,
             * o estado volta para queued.
             *
             * O método failed() abaixo marca
             * definitivamente como failed.
             */
            $intelligence->refresh();

            if (
                ! $this->isCurrentQueueGeneration(
                    $intelligence
                )
            ) {
                return;
            }

            $intelligence->update([
                'research_status' => 'queued',

                'research_error' => mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    2000
                ),
            ]);

            throw $exception;
        }
    }

    public function failed(
        Throwable $exception
    ): void {
        $intelligence =
            CompanyExportIntelligence::query()
                ->where(
                    'company_id',
                    $this->companyId
                )
                ->first();

        if (! $intelligence) {
            return;
        }

        /*
         * Um job antigo não pode sobrescrever
         * o estado de uma geração recuperada
         * mais recente.
         */
        if (
            ! $this->isCurrentQueueGeneration(
                $intelligence
            )
        ) {
            return;
        }

        $intelligence->update([
            'research_status' => 'failed',

            'research_error' => mb_substr(
                $exception
                    ->getMessage(),
                0,
                2000
            ),

            'research_completed_at' => now(),
        ]);
    }

    private function isCurrentQueueGeneration(
        CompanyExportIntelligence $intelligence,
    ): bool {
        /*
         * Jobs antigos, criados antes da
         * introdução do queue token, continuam
         * compatíveis.
         */
        if ($this->queueToken === null) {
            return true;
        }

        $currentToken =
            data_get(
                $this->metadata(
                    $intelligence
                ),
                'research_queue.queue_token'
            );

        return is_string(
            $currentToken
        )
            && hash_equals(
                $currentToken,
                $this->queueToken
            );
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
