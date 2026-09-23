<?php

namespace App\Jobs;

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Services\CrmCheckService;
use App\Services\HubSpotLeadStatusSyncService;
use App\Services\HubSpotMirrorLeadProjectionService;
use App\Services\HubSpotRefreshDiffService;
use App\Services\HubSpotRefreshRunService;
use App\Services\LeadOperationalClassificationService;
use App\Services\LeadQualificationScoreService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshCompanyFromHubSpot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 240;

    public int $uniqueFor = 900;

    public function __construct(
        public int $companyId,
        public ?int $refreshItemId = null,
    ) {
        /*
         * O worker background do projeto
         * consome explicitamente Redis.
         */
        $this->onConnection(
            'redis'
        );
    }

    public function uniqueId(): string
    {
        return
            $this->refreshItemId !== null
                ? (
                    $this->companyId
                    .':'
                    .$this->refreshItemId
                )
                : (string)
                    $this->companyId;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (
                new WithoutOverlapping(
                    'hubspot-refresh-company-'
                    .$this->companyId
                )
            )
                ->dontRelease()
                ->expireAfter(
                    $this->timeout + 60
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
            900,
        ];
    }

    public function handle(
        CrmCheckService $crmService,
        CrmCompanyProvider $crmProvider,
        HubSpotLeadStatusSyncService $statusService,
        HubSpotMirrorLeadProjectionService $projectionService,
        LeadOperationalClassificationService $classification,
        LeadQualificationScoreService $qualification,
        HubSpotRefreshDiffService $diff,
        HubSpotRefreshRunService $runs,
    ): void {
        if (
            $this->refreshItemId !== null
        ) {
            $runs->markRunning(
                $this->refreshItemId
            );
        }

        /*
         * Em uma atualização geral, o Job
         * obrigatoriamente precisa conhecer o
         * item responsável pelo progresso.
         *
         * Jobs individuais continuam usando
         * refreshItemId = null normalmente.
         */
        Log::debug(
            'Executando refresh HubSpot.',
            [
                'company_id' => $this->companyId,

                'refresh_item_id' => $this->refreshItemId,
            ]
        );

        $before =
            $diff->snapshot(
                $this->companyId
            );

        $company =
            Company::query()
                ->find(
                    $this->companyId
                );

        if ($company === null) {
            return;
        }

        /*
         * ------------------------------------------------
         * 1. CRM / HUBSPOT COMPLETO
         * ------------------------------------------------
         *
         * Atualiza:
         * - empresa encontrada no HubSpot;
         * - situação CRM;
         * - todos os negócios;
         * - etapas;
         * - negócio ganho/aberto/perdido;
         * - quantidade de contatos;
         * - último contato;
         * - Score SDR.
         */
        $check =
            $crmService->check(
                $company,
                $crmProvider,
            );

        $freshCompany =
            Company::query()
                ->with([
                    'crmCheck',
                    'icpScore',
                    'exportIntelligence',
                ])
                ->find(
                    $this->companyId
                );

        if ($freshCompany === null) {
            return;
        }

        $lead =
            CompanyHubSpotLead::query()
                ->where(
                    'company_id',
                    $this->companyId
                )
                ->first();

        /*
         * ------------------------------------------------
         * 2. EMPRESA QUE AINDA NÃO TINHA PROJEÇÃO
         * ------------------------------------------------
         *
         * Se agora ela passou a existir no HubSpot,
         * criamos a projeção operacional.
         */
        if ($lead === null) {
            $lead =
                $projectionService->sync(
                    $freshCompany
                );
        } else {
            /*
             * A projeção calcula qual negócio é o
             * representante atual sem precisarmos
             * destruir os dados operacionais existentes.
             */
            $projection =
                $projectionService->build(
                    $freshCompany
                );

            $attributes = [];

            foreach (
                [
                    'hubspot_company_id',
                    'hubspot_deal_id',
                    'pipeline_id',
                    'deal_stage_id',
                ] as $key
            ) {
                $value =
                    $projection[
                        $key
                    ]
                    ?? null;

                /*
                 * Nunca apagamos um ID existente
                 * apenas porque uma consulta pontual
                 * não encontrou o registro.
                 */
                if (
                    is_string($value)
                    && trim($value) !== ''
                ) {
                    $attributes[
                        $key
                    ] =
                        trim(
                            $value
                        );
                }
            }

            /*
             * Se o CRM conseguiu identificar
             * efetivamente a empresa, atualizamos
             * a situação comercial.
             *
             * Um "not_found" isolado não deve
             * apagar uma associação existente.
             */
            if (
                $check->status !== 'not_found'
                || trim(
                    (string)
                    $lead->hubspot_company_id
                ) === ''
            ) {
                $attributes[
                    'commercial_status'
                ] =
                    $classification
                        ->commercialStatus(
                            $check
                        );

                /*
                 * Atualiza também o snapshot usado
                 * pela tela para Score/Prioridade.
                 */
                $result =
                    $qualification
                        ->calculate(
                            $freshCompany
                        );

                $rawMetadata =
                    $lead->getAttribute(
                        'metadata'
                    );

                $metadata =
                    is_array(
                        $rawMetadata
                    )
                        ? $rawMetadata
                        : [];

                $metadata[
                    'qualification_snapshot'
                ] = [
                    'score' => $result[
                            'score'
                        ],

                    'priority' => $result[
                            'priority'
                        ],

                    'source' => 'hubspot_live_refresh',

                    'calculated_at' => now()
                        ->toIso8601String(),
                ];

                $attributes[
                    'metadata'
                ] =
                    $metadata;
            }

            if ($attributes !== []) {
                $lead
                    ->forceFill(
                        $attributes
                    )
                    ->save();

                $lead =
                    $lead
                        ->refresh();
            }
        }

        /*
         * ------------------------------------------------
         * 3. ACOMPANHAMENTO / TAREFAS EM TEMPO REAL
         * ------------------------------------------------
         *
         * Atualiza diretamente pelo ID do HubSpot:
         * - etapa atual;
         * - tarefas abertas;
         * - próxima tarefa;
         * - contatos;
         * - datas;
         * - acompanhamento.
         */
        if (
            trim(
                (string)
                $lead->hubspot_company_id
            ) !== ''
            && trim(
                (string)
                $lead->hubspot_deal_id
            ) !== ''
        ) {
            $statusService->sync(
                $lead
            );
        }

        $after =
            $diff->snapshot(
                $this->companyId
            );

        if (
            $this->refreshItemId !== null
        ) {
            $runs->complete(
                itemId: $this->refreshItemId,

                changes: $diff->changes(
                    $before,
                    $after
                ),
            );
        }
    }

    public function failed(
        Throwable $exception
    ): void {
        if (
            $this->refreshItemId !== null
        ) {
            app(
                HubSpotRefreshRunService::class
            )->fail(
                itemId: $this->refreshItemId,

                error: $exception
                    ->getMessage(),
            );
        }

        Log::error(
            'Falha ao atualizar empresa a partir do HubSpot.',
            [
                'company_id' => $this->companyId,

                'error' => $exception
                    ->getMessage(),
            ]
        );
    }
}
