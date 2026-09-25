<?php

namespace App\Console\Commands;

use App\Models\HubSpotDeal;
use App\Services\HubSpotDealCompanyAssociationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncHubSpotDealPrimaryCompanies extends Command
{
    protected $signature =
        'hubspot:sync-deal-primary-companies
        {--apply : Grava as associações Primary}
        {--limit=0 : Limita a quantidade de Deals}';

    protected $description =
        'Reconcilia a Primary Company de cada Deal HubSpot';

    public function handle(
        HubSpotDealCompanyAssociationService $service,
    ): int {
        $apply =
            (bool) $this->option(
                'apply'
            );

        $limit =
            max(
                0,
                (int) $this->option(
                    'limit'
                )
            );

        $query =
            HubSpotDeal::query()
                ->withCount(
                    'companies'
                )
                ->whereHas(
                    'companies'
                )
                ->orderBy(
                    'id'
                );

        if ($limit > 0) {
            $query->limit(
                $limit
            );
        }

        $deals =
            $query->get();

        $stats = [
            'processed' => 0,
            'singleton' => 0,
            'multiple' => 0,
            'primary' => 0,
            'ambiguous' => 0,
            'failed' => 0,
        ];

        foreach (
            $deals as $deal
        ) {
            $stats[
                'processed'
            ]++;

            $count =
                (int)
                $deal
                    ->companies_count;

            /*
             * Uma única associação é
             * comercialmente inequívoca.
             *
             * Não precisamos gastar API.
             */
            if ($count === 1) {
                $stats[
                    'singleton'
                ]++;

                if ($apply) {
                    DB::table(
                        'hubspot_company_deal'
                    )
                        ->where(
                            'hubspot_deal_id',
                            $deal->id
                        )
                        ->update([
                            'is_primary' => true,

                            'updated_at' => now(),
                        ]);
                }

                continue;
            }

            $stats[
                'multiple'
            ]++;

            if (! $apply) {
                continue;
            }

            try {
                $result =
                    $service
                        ->syncForDeal(
                            $deal
                        );

                if (
                    $result[
                        'ambiguous'
                    ]
                ) {
                    $stats[
                        'ambiguous'
                    ]++;
                } else {
                    $stats[
                        'primary'
                    ] +=
                        $result[
                            'primary'
                        ];
                }

            } catch (
                Throwable $exception
            ) {
                $stats[
                    'failed'
                ]++;

                $this->error(
                    $deal->hubspot_id
                    .' | '
                    .($deal->name ?? '-')
                    .' | '
                    .$exception
                        ->getMessage()
                );
            }
        }

        $this->newLine();

        $this->table(
            [
                'Indicador',
                'Quantidade',
            ],
            [
                [
                    'Deals processados',
                    $stats[
                        'processed'
                    ],
                ],
                [
                    'Com uma Company',
                    $stats[
                        'singleton'
                    ],
                ],
                [
                    'Com várias Companies',
                    $stats[
                        'multiple'
                    ],
                ],
                [
                    'Primary confirmadas',
                    $stats[
                        'primary'
                    ],
                ],
                [
                    'Múltiplos ambíguos',
                    $stats[
                        'ambiguous'
                    ],
                ],
                [
                    'Falhas',
                    $stats[
                        'failed'
                    ],
                ],
            ]
        );

        if (! $apply) {
            $this->warn(
                'PREVIEW: nada foi alterado.'
            );
        } else {
            $this->info(
                'Primary Companies reconciliadas.'
            );
        }

        return
            $stats[
                'failed'
            ] > 0
                ? self::FAILURE
                : self::SUCCESS;
    }
}
