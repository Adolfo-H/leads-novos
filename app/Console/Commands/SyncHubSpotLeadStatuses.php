<?php

namespace App\Console\Commands;

use App\Services\HubSpotLeadStatusCandidateService;
use App\Services\HubSpotLeadStatusSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncHubSpotLeadStatuses extends Command
{
    protected $signature =
        'hubspot:lead-statuses
        {--limit=100}';

    protected $description =
        'Atualiza o acompanhamento dos leads usando o HubSpot';

    public function handle(
        HubSpotLeadStatusSyncService $service,
        HubSpotLeadStatusCandidateService $candidates,
    ): int {
        $limit =
            max(
                1,
                (int) $this->option(
                    'limit'
                )
            );

        $leads =
            $candidates
                ->candidates(
                    $limit
                );

        $processed = 0;
        $failed = 0;

        foreach ($leads as $lead) {
            try {
                $updated =
                    $service->sync(
                        $lead
                    );

                $processed++;

                $this->line(
                    '#'
                    .$updated->company_id
                    .' → '
                    .$updated->work_status
                );
            } catch (Throwable $exception) {
                $failed++;

                $lead->update([
                    'sync_error' => mb_substr(
                        $exception->getMessage(),
                        0,
                        2000
                    ),
                ]);

                $this->error(
                    '#'
                    .$lead->company_id
                    .' → '
                    .$exception->getMessage()
                );
            }
        }

        $this->newLine();

        $this->info(
            'Processados: '
            .$processed
            .' | Falhas: '
            .$failed
        );

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
