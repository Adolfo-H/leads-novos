<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\HubSpotLeadEligibilityService;
use App\Services\HubSpotLeadSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCompanyToHubSpot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public int $companyId,
    ) {}

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
                    'hubspot-lead-sync-company-'
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
        HubSpotLeadEligibilityService $eligibility,
        HubSpotLeadSyncService $sync,
    ): void {
        if (
            ! (bool) config(
                'services.hubspot.lead_sync_enabled',
                false
            )
        ) {
            return;
        }

        $company =
            Company::query()->find(
                $this->companyId
            );

        if ($company === null) {
            return;
        }

        $evaluation =
            $eligibility->evaluate(
                $company
            );

        if (
            ! $evaluation['eligible']
        ) {
            return;
        }

        /*
         * Não capturamos a exceção.
         *
         * Uma falha temporária precisa voltar
         * para o sistema de filas para que o
         * retry automático seja executado.
         */
        $sync->sync(
            $company
        );
    }

    public function failed(
        Throwable $exception
    ): void {
        Log::error(
            'Falha definitiva ao sincronizar empresa com o HubSpot.',
            [
                'company_id' => $this->companyId,

                'error' => $exception
                    ->getMessage(),
            ]
        );
    }
}
