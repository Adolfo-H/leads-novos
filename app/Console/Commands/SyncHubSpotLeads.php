<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\HubSpotLeadEligibilityService;
use App\Services\HubSpotLeadSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncHubSpotLeads extends Command
{
    protected $signature =
        'hubspot:leads-sync
        {--limit=1}
        {--execute}';

    protected $description =
        'Cria leads qualificados no HubSpot';

    public function handle(
        HubSpotLeadEligibilityService $eligibility,
        HubSpotLeadSyncService $syncService,
    ): int {
        $limit =
            max(
                1,
                (int) $this->option(
                    'limit'
                )
            );

        $execute =
            (bool) $this->option(
                'execute'
            );

        $companies =
            Company::query()
                ->select(
                    'companies.*'
                )
                ->join(
                    'company_sdr_scores as sdr',
                    'sdr.company_id',
                    '=',
                    'companies.id'
                )
                ->where(
                    'sdr.is_eligible',
                    true
                )
                ->where(
                    'sdr.is_provisional',
                    false
                )
                ->where(
                    'sdr.score',
                    '>=',
                    (int) config(
                        'services.hubspot.lead_min_score',
                        70
                    )
                )
                ->whereHas(
                    'crmCheck',
                    fn ($query) => $query->where(
                        'status',
                        'not_found'
                    )
                )
                ->whereDoesntHave(
                    'hubSpotLead',
                    fn ($query) => $query->whereNotNull(
                        'synced_at'
                    )
                )
                ->with([
                    'establishments',
                    'matrix',
                    'sdrScore',
                    'crmCheck',
                    'hubSpotLead',
                ])
                ->orderByDesc(
                    'sdr.score'
                )
                ->limit(
                    $limit
                )
                ->get()
                ->filter(
                    fn (Company $company): bool => $eligibility
                        ->evaluate(
                            $company
                        )['eligible']
                )
                ->values();

        if ($companies->isEmpty()) {
            $this->info(
                'Nenhum lead pronto para sincronização.'
            );

            return self::SUCCESS;
        }

        if (! $execute) {
            $rows = [];

            foreach (
                $companies as $company
            ) {
                $rows[] = [
                    $company->corporate_name,

                    $company->cnpj_root,

                    $company
                        ->sdrScore
                        ->score
                        ?? 0,
                ];
            }

            $this->table(
                [
                    'Empresa',
                    'CNPJ raiz',
                    'SDR',
                ],
                $rows
            );

            $this->warn(
                'Prévia apenas. Nada foi criado.'
            );

            $this->line(
                'Para criar, use --execute.'
            );

            return self::SUCCESS;
        }

        if (
            ! (bool) config(
                'services.hubspot.lead_sync_enabled',
                false
            )
        ) {
            $this->error(
                'Sincronização automática está desativada.'
            );

            $this->line(
                'Defina HUBSPOT_LEAD_SYNC_ENABLED=true.'
            );

            return self::FAILURE;
        }

        $failures = 0;

        foreach (
            $companies as $company
        ) {
            $this->line(
                'Sincronizando: '
                .$company->corporate_name
            );

            try {
                $sync =
                    $syncService->sync(
                        $company
                    );

                $this->info(
                    'OK | Empresa: '
                    .$sync->hubspot_company_id
                    .' | Contato: '
                    .(
                        $sync->hubspot_contact_id
                        ?? 'não criado'
                    )
                    .' | Negócio: '
                    .$sync->hubspot_deal_id
                );
            } catch (Throwable $exception) {
                $failures++;

                $this->error(
                    $company->corporate_name
                    .' | '
                    .$exception->getMessage()
                );
            }
        }

        return $failures > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
