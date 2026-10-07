<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\HubSpotRefreshItem;
use Illuminate\Console\Command;

final class RecoverStaleHubSpotBulkRefreshes extends Command
{
    protected $signature =
        'hubspot:bulk-recover
        {--minutes=20 : Tempo mínimo para considerar o item abandonado}
        {--limit=100 : Máximo de itens por execução}';

    protected $description =
        'Reenfileira itens abandonados de conferências gerais do HubSpot';

    public function handle(): int
    {
        $minutes =
            max(
                5,
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

        /*
         * RefreshCompanyFromHubSpot possui timeout
         * de 240 segundos e uniqueFor de 15 minutos.
         *
         * O padrão de 20 minutos garante que não
         * tentaremos recuperar um job saudável que
         * ainda esteja dentro da janela normal.
         */
        $items =
            HubSpotRefreshItem::query()
                ->whereIn(
                    'status',
                    [
                        'queued',
                        'running',
                    ]
                )
                ->where(
                    'updated_at',
                    '<=',
                    now()->subMinutes(
                        $minutes
                    )
                )
                ->whereHas(
                    'run',
                    function ($query): void {
                        $query->whereIn(
                            'status',
                            [
                                'queued',
                                'running',
                            ]
                        );
                    }
                )
                ->orderBy(
                    'updated_at'
                )
                ->orderBy(
                    'id'
                )
                ->limit(
                    $limit
                )
                ->get();

        $queued = 0;

        foreach ($items as $item) {
            RefreshCompanyFromHubSpot::dispatch(
                $item->company_id,
                $item->id,
            );

            /*
             * Marca a tentativa de recuperação.
             *
             * Caso o worker morra novamente, o
             * item voltará a ser elegível depois
             * da janela configurada.
             */
            $item->touch();

            $queued++;
        }

        $this->info(
            'Itens stale selecionados: '
            .$items->count()
            .' | reenfileirados: '
            .$queued
        );

        return self::SUCCESS;
    }
}
