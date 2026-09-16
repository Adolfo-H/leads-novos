<?php

namespace App\Console\Commands;

use App\Jobs\SyncCompanyToHubSpot;
use App\Models\CompanyHubSpotLead;
use Illuminate\Console\Command;

class RecoverStaleHubSpotLeadSyncs extends Command
{
    protected $signature =
        'hubspot:leads-recover
        {--minutes=10}
        {--limit=50}';

    protected $description =
        'Reenvia sincronizações incompletas e antigas do HubSpot';

    public function handle(): int
    {
        if (
            ! (bool) config(
                'services.hubspot.lead_sync_enabled',
                false
            )
        ) {
            $this->info(
                'Sincronização automática do HubSpot está desativada.'
            );

            return self::SUCCESS;
        }

        $minutes =
            max(
                1,
                (int) $this->option(
                    'minutes'
                )
            );

        $limit =
            min(
                500,
                max(
                    1,
                    (int) $this->option(
                        'limit'
                    )
                )
            );

        $syncs =
            CompanyHubSpotLead::query()
                ->whereNull(
                    'synced_at'
                )
                ->where(
                    'updated_at',
                    '<=',
                    now()->subMinutes(
                        $minutes
                    )
                )
                ->orderBy(
                    'updated_at'
                )
                ->limit(
                    $limit
                )
                ->get([
                    'company_id',
                ]);

        foreach ($syncs as $sync) {
            SyncCompanyToHubSpot::dispatch(
                $sync->company_id
            );
        }

        $this->info(
            'Sincronizações HubSpot reenfileiradas: '
            .$syncs->count()
        );

        return self::SUCCESS;
    }
}
