<?php

namespace App\Console\Commands;

use App\Services\HubSpotWebhookQueueService;
use Illuminate\Console\Command;

final class RecoverHubSpotWebhookEvents extends Command
{
    protected $signature =
        'hubspot:webhooks-recover
        {--minutes=10 : Tempo para considerar queued/processing abandonado}
        {--limit=100 : Máximo de eventos por execução}';

    protected $description =
        'Recupera webhooks HubSpot persistidos que não concluíram o processamento';

    public function handle(
        HubSpotWebhookQueueService $queue,
    ): int {
        $minutes =
            max(
                1,
                (int) $this->option(
                    'minutes'
                )
            );

        $limit =
            max(
                1,
                min(
                    1000,
                    (int) $this->option(
                        'limit'
                    )
                )
            );

        $result =
            $queue->recover(
                staleMinutes: $minutes,

                limit: $limit,
            );

        $this->line(
            'Selecionados: '
            .$result[
                'selected'
            ]
            .' | Enfileirados: '
            .$result[
                'queued'
            ]
            .' | Ignorados: '
            .$result[
                'skipped'
            ]
        );

        return self::SUCCESS;
    }
}
