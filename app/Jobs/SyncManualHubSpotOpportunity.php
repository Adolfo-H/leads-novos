<?php

namespace App\Jobs;

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use App\Services\CompanyHubSpotLeadMetadataService;
use App\Services\CrmCheckService;
use App\Services\HubSpotLeadStatusSyncService;
use App\Services\HubSpotLeadSyncService;
use App\Services\LeadOwnershipService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Gate;
use Throwable;

class SyncManualHubSpotOpportunity implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    private ?CompanyHubSpotLeadMetadataService $metadataService = null;

    public function __construct(
        public int $companyId,
        public int $actorId,
    ) {
        $this->onConnection(
            'redis'
        );

        $this->onQueue(
            'hubspot-realtime'
        );
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (
                new WithoutOverlapping(
                    'hubspot-manual-opportunity-'
                    .$this->companyId
                )
            )
                ->releaseAfter(15)
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
        HubSpotLeadSyncService $syncService,
        CrmCheckService $crmService,
        CrmCompanyProvider $crmProvider,
        HubSpotLeadStatusSyncService $statusService,
        LeadOwnershipService $ownership,
        CompanyHubSpotLeadMetadataService $metadataService,
    ): void {
        $this->metadataService =
            $metadataService;

        if (
            ! (bool) config(
                'services.hubspot.manual_opportunity_enabled',
                false
            )
        ) {
            $this->markFailed(
                'A criação manual de oportunidades '
                .'foi desativada antes do processamento.'
            );

            return;
        }

        $company =
            Company::query()
                ->find(
                    $this->companyId
                );

        if ($company === null) {
            $this->markFailed(
                'A empresa não existe mais '
                .'no Prospector.'
            );

            return;
        }

        $actor =
            User::query()
                ->find(
                    $this->actorId
                );

        if ($actor === null) {
            $this->markFailed(
                'O usuário responsável não existe '
                .'mais no Prospector.'
            );

            return;
        }

        if (
            ! Gate::forUser(
                $actor
            )->allows(
                'createHubSpotOpportunity',
                $company
            )
        ) {
            $this->markFailed(
                'O usuário não possui mais '
                .'permissão para trabalhar '
                .'esta empresa.'
            );

            return;
        }

        $this->markProcessing();

        try {
            $sync =
                $syncService
                    ->syncManual(
                        company: $company,
                        actor: $actor,
                    );

            /*
             * A criação remota terminou.
             *
             * Não esperamos o próximo refresh
             * manual nem o webhook para atualizar
             * a tela.
             *
             * Relemos imediatamente:
             *
             * - existência da empresa no CRM;
             * - negócios;
             * - etapa atual;
             * - tarefas abertas;
             * - próxima ação.
             */
            $this->updateState(
                status: 'processing',

                values: [
                    'progress' => 90,

                    'step' => 'refreshing_crm',

                    'progress_message' => 'Objetos criados. Atualizando CRM local.',
                ],
            );

            $crmService->check(
                $company,
                $crmProvider,
            );

            $this->updateState(
                status: 'processing',

                values: [
                    'progress' => 95,

                    'step' => 'syncing_status',

                    'progress_message' => 'CRM identificado. Atualizando etapa e tarefas.',
                ],
            );

            $sync =
                $statusService
                    ->sync(
                        $sync
                            ->refresh()
                    );

            /*
             * Quem iniciou a oportunidade
             * passa a ser também o responsável
             * pela carteira local.
             */
            $workState =
                $ownership->assign(
                    company: $company,

                    owner: $actor,
                );

            $sync =
                $sync->refresh();

            $workState->forceFill([
                'status' => $sync->work_status
                    ?: 'new',

                'last_action_at' => $sync->last_activity_at,

                'next_action_at' => $sync->last_task_due_at,
            ])->save();

            $this->updateState(
                status: 'processing',

                values: [
                    'progress' => 98,

                    'step' => 'saving_local',

                    'progress_message' => 'Salvando responsável e próxima ação.',
                ],
            );

            $this->markCompleted(
                $sync
            );

        } catch (Throwable $exception) {
            $this->markRetrying(
                $exception
            );

            throw $exception;
        }
    }

    public function failed(
        ?Throwable $exception
    ): void {
        $message =
            $exception?->getMessage()
            ?? 'Falha definitiva ao criar '
                .'a oportunidade no HubSpot.';

        $this->markFailed(
            $message
        );
    }

    private function markProcessing(): void
    {
        $this->updateState(
            status: 'processing',

            values: [
                'progress' => 10,

                'step' => 'starting',

                'progress_message' => 'Iniciando criação no HubSpot.',

                'started_at' => now()
                    ->toIso8601String(),

                'last_error' => null,
            ],
        );
    }

    private function markRetrying(
        Throwable $exception
    ): void {
        $message =
            mb_substr(
                $exception
                    ->getMessage(),
                0,
                2000
            );

        $this->updateState(
            status: 'retrying',
            values: [
                'last_error' => $message,

                'last_attempt_failed_at' => now()
                    ->toIso8601String(),
            ],
            syncError: $message,
        );
    }

    private function markFailed(
        string $message
    ): void {
        $message =
            mb_substr(
                $message,
                0,
                2000
            );

        $this->updateState(
            status: 'failed',
            values: [
                'failed_at' => now()
                    ->toIso8601String(),

                'last_error' => $message,
            ],
            syncError: $message,
        );
    }

    private function markCompleted(
        CompanyHubSpotLead $sync
    ): void {
        $metadata =
            $this->metadata(
                $sync
            );

        $manual =
            $this->manualMetadata(
                $metadata
            );

        $metadata[
            'manual_sync'
        ] =
            array_merge(
                $manual,
                [
                    'status' => 'completed',

                    'progress' => 100,

                    'step' => 'completed',

                    'progress_message' => 'Oportunidade criada e sincronizada com sucesso.',

                    'completed_at' => now()
                        ->toIso8601String(),

                    'last_error' => null,
                ]
            );

        $sync->forceFill([
            'sync_error' => null,

            'metadata' => $metadata,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateState(
        string $status,
        array $values = [],
        ?string $syncError = null,
    ): void {
        $sync =
            CompanyHubSpotLead::query()
                ->where(
                    'company_id',
                    $this->companyId
                )
                ->first();

        if ($sync === null) {
            return;
        }

        $service =
            $this->metadataService
            ?? app(
                CompanyHubSpotLeadMetadataService::class
            );

        $service->mergeManualSync(
            leadId: $sync->id,

            values: array_merge(
                [
                    'status' => $status,
                ],
                $values,
            ),
        );

        if ($syncError !== null) {
            CompanyHubSpotLead::query()
                ->whereKey(
                    $sync->id
                )
                ->update([
                    'sync_error' => $syncError,
                ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(
        CompanyHubSpotLead $sync
    ): array {
        $raw =
            $sync->getAttribute(
                'metadata'
            );

        return is_array($raw)
            ? $raw
            : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function manualMetadata(
        array $metadata
    ): array {
        $manual =
            $metadata[
                'manual_sync'
            ]
            ?? [];

        return is_array($manual)
            ? $manual
            : [];
    }
}
