<?php

namespace App\Console\Commands;

use App\Models\CompanyHubSpotLead;
use App\Services\HubSpotRefreshRunService;
use Illuminate\Console\Command;

class RefreshHubSpotOnStartup extends Command
{
    protected $signature =
        'hubspot:startup-refresh
        {--limit=0}';

    protected $description =
        'Enfileira uma reconciliação completa das empresas vinculadas ao HubSpot';

    public function handle(
        HubSpotRefreshRunService $runs
    ): int {
        $active =
            $runs->active();

        if ($active !== null) {
            $this->info(
                'Já existe uma conferência HubSpot em andamento '
                .'(#'
                .$active->id
                .').'
            );

            return self::SUCCESS;
        }

        $limit =
            max(
                0,
                (int) $this->option(
                    'limit'
                )
            );

        $query =
            CompanyHubSpotLead::query()
                ->whereNotNull(
                    'hubspot_company_id'
                )
                /*
                 * Quem nunca foi conferido entra
                 * primeiro.
                 */
                ->orderByRaw(
                    '
                    CASE
                        WHEN status_synced_at IS NULL
                            THEN 0
                        ELSE 1
                    END
                    '
                )
                ->orderBy(
                    'status_synced_at'
                )
                ->orderBy(
                    'id'
                );

        if ($limit > 0) {
            $query->limit(
                $limit
            );
        }

        $rawCompanyIds =
            $query
                ->pluck(
                    'company_id'
                )
                ->all();

        /** @var list<int> $companyIds */
        $companyIds =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn (
                                mixed $id
                            ): int => (int) $id,
                            $rawCompanyIds
                        ),
                        static fn (
                            int $id
                        ): bool => $id > 0
                    )
                )
            );

        if ($companyIds === []) {
            $this->info(
                'Nenhuma empresa HubSpot precisa ser enfileirada.'
            );

            return self::SUCCESS;
        }

        $run =
            $runs->start(
                companyIds: $companyIds,

                userId: null,
            );

        $this->info(
            'Conferência HubSpot #'
            .$run->id
            .' enfileirada: '
            .count(
                $companyIds
            )
            .' empresa(s).'
        );

        if ($limit === 0) {
            $this->line(
                'Escopo: todas as empresas vinculadas.'
            );
        }

        return self::SUCCESS;
    }
}
