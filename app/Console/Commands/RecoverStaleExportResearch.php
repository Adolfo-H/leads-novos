<?php

namespace App\Console\Commands;

use App\Services\ExportResearchQueueService;
use Illuminate\Console\Command;

class RecoverStaleExportResearch extends Command
{
    protected $signature =
        'exports:recover-stale
        {--minutes=20 : Tempo mínimo sem progresso}
        {--limit=100 : Máximo de pesquisas por execução}';

    protected $description =
        'Recupera pesquisas de exportação abandonadas na fila.';

    public function handle(
        ExportResearchQueueService $queue
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
                    500,
                    (int) $this->option(
                        'limit'
                    )
                )
            );

        $recovered =
            $queue->recoverStale(
                afterMinutes: $minutes,
                limit: $limit,
            );

        $this->components->info(
            $recovered === 1
                ? '1 pesquisa de exportação recuperada.'
                : $recovered
                    .' pesquisas de exportação recuperadas.'
        );

        return self::SUCCESS;
    }
}
