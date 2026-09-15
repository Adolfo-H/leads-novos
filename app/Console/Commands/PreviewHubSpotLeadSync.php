<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\HubSpotLeadEligibilityService;
use Illuminate\Console\Command;

class PreviewHubSpotLeadSync extends Command
{
    protected $signature =
        'hubspot:leads-preview
        {--limit=20}';

    protected $description =
        'Mostra quais leads seriam enviados automaticamente ao HubSpot';

    public function handle(
        HubSpotLeadEligibilityService $eligibility
    ): int {
        $limit =
            max(
                1,
                (int) $this->option(
                    'limit'
                )
            );

        $companies =
            Company::query()
                ->with([
                    'sdrScore',
                    'crmCheck',
                    'exportIntelligence',
                    'hubSpotLead',
                ])
                ->whereHas(
                    'sdrScore',
                    fn ($query) => $query
                        ->where(
                            'is_eligible',
                            true
                        )
                        ->where(
                            'is_provisional',
                            false
                        )
                        ->where(
                            'score',
                            '>=',
                            (int) config(
                                'services.hubspot.lead_min_score',
                                70
                            )
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
                    'hubSpotLead'
                )
                ->orderByDesc(
                    'id'
                )
                ->limit(
                    $limit
                )
                ->get();

        if ($companies->isEmpty()) {
            $this->info(
                'Nenhuma empresa pronta para sincronização.'
            );

            return self::SUCCESS;
        }

        $rows = [];

        foreach (
            $companies as $company
        ) {
            $result =
                $eligibility->evaluate(
                    $company
                );

            $rows[] = [
                $company->corporate_name,
                $company->cnpj_root,
                $company
                    ->sdrScore
                    ->score
                    ?? 0,
                $company
                    ->sdrScore
                    ->priority
                    ?? '—',
                $result['eligible']
                    ? 'SIM'
                    : 'NÃO',
                $result['reason'],
            ];
        }

        $this->table(
            [
                'Empresa',
                'CNPJ raiz',
                'SDR',
                'Prioridade',
                'Enviar',
                'Motivo',
            ],
            $rows
        );

        return self::SUCCESS;
    }
}
