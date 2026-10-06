<?php

namespace App\Jobs;

use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadWorkState;
use App\Services\HubSpotLeadStatusSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncHubSpotCompanyRealtimeStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 90;

    public int $uniqueFor = 15;

    public function __construct(
        public int $companyId,
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
        return (string)
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
                    'hubspot-realtime-status-'
                    .$this->companyId
                )
            )
                ->releaseAfter(
                    2
                )
                ->expireAfter(
                    120
                ),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [
            2,
            5,
            10,
            20,
        ];
    }

    public function handle(
        HubSpotLeadStatusSyncService $status,
    ): void {
        $lead =
            CompanyHubSpotLead::query()
                ->where(
                    'company_id',
                    $this->companyId
                )
                ->first();

        /*
         * Ainda não existe uma projeção completa.
         *
         * Nesse caso fazemos fallback para o
         * refresh completo da empresa.
         */
        if (
            $lead === null
            || trim(
                (string)
                $lead->hubspot_company_id
            ) === ''
            || trim(
                (string)
                $lead->hubspot_deal_id
            ) === ''
        ) {
            RefreshCompanyFromHubSpot::dispatch(
                $this->companyId
            );

            return;
        }

        $lead =
            $status->sync(
                $lead
            );

        /*
         * Mantém também a carteira operacional
         * sincronizada com a nova tarefa/data.
         */
        $workState =
            CompanyLeadWorkState::query()
                ->where(
                    'company_id',
                    $this->companyId
                )
                ->first();

        if ($workState !== null) {
            $workState->forceFill([
                'status' => $lead->work_status,

                'last_action_at' => $lead->last_activity_at,

                'next_action_at' => $lead->last_task_due_at,
            ])->save();
        }
    }

    public function failed(
        Throwable $exception
    ): void {
        Log::error(
            'Falha no refresh realtime do HubSpot.',
            [
                'company_id' => $this->companyId,

                'error' => $exception
                    ->getMessage(),
            ]
        );
    }
}
