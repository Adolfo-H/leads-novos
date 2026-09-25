<?php

namespace App\Console\Commands;

use App\Models\CompanyLeadActivity;
use App\Models\HubSpotCompany;
use App\Services\HubSpotActivitySyncService;
use Illuminate\Console\Command;

class RebuildHubSpotActivityProjections extends Command
{
    protected $signature =
        'hubspot:rebuild-activity-projections
        {--dry-run : Apenas mostra o que seria reconstruído}';

    protected $description =
        'Reconstrói atividades fiscais usando somente vínculos HubSpot confiáveis';

    public function handle(
        HubSpotActivitySyncService $activities,
    ): int {
        $query =
            HubSpotCompany::query()
                ->trustedFiscalLink()
                ->whereNotNull(
                    'company_id'
                )
                ->whereHas(
                    'activities'
                );

        $eligible =
            (clone $query)
                ->count();

        $this->info(
            'HubSpot Companies elegíveis: '
            .$eligible
        );

        if (
            (bool) $this->option(
                'dry-run'
            )
        ) {
            return self::SUCCESS;
        }

        /*
         * CompanyLeadActivity(source=hubspot)
         * é uma projeção derivada.
         *
         * O mirror hubspot_activities permanece
         * intacto.
         */
        $removed =
            CompanyLeadActivity::query()
                ->where(
                    'source',
                    'hubspot'
                )
                ->delete();

        $projected = 0;

        $query
            ->with(
                'prospectorCompany'
            )
            ->orderBy(
                'id'
            )
            ->chunkById(
                100,
                function (
                    $records
                ) use (
                    $activities,
                    &$projected
                ): void {
                    foreach (
                        $records as $record
                    ) {
                        $company =
                            $record
                                ->prospectorCompany;

                        if (
                            $company === null
                        ) {
                            continue;
                        }

                        $projected +=
                            $activities
                                ->promoteForCompany(
                                    hubSpotCompany: $record,

                                    company: $company,
                                );
                    }
                }
            );

        $this->line(
            'Projeções antigas removidas: '
            .$removed
        );

        $this->line(
            'Atividades reprojetadas: '
            .$projected
        );

        $this->info(
            'Atividades HubSpot reconstruídas.'
        );

        return self::SUCCESS;
    }
}
