<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\HubSpotMirrorCrmSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncHubSpotMirrorCrm extends Command
{
    protected $signature =
        'hubspot:sync-mirror-crm
        {--limit=0}
        {--dry-run}';

    protected $description =
        'Reconstrói o CRM operacional usando o espelho local do HubSpot';

    public function handle(
        HubSpotMirrorCrmSyncService $service
    ): int {
        $query =
            Company::query()
                ->orderBy(
                    'id'
                );

        $limit =
            max(
                0,
                (int) $this->option(
                    'limit'
                )
            );

        if ($limit > 0) {
            $query->limit(
                $limit
            );
        }

        $companies =
            $query->get();

        $dryRun =
            (bool) $this->option(
                'dry-run'
            );

        $stats = [
            'processed' => 0,
            'client' => 0,
            'opportunity' => 0,
            'prospected' => 0,
            'known' => 0,
            'not_found' => 0,
            'failed' => 0,
        ];

        foreach (
            $companies as $company
        ) {
            try {
                $result =
                    $dryRun
                        ? $service->preview(
                            $company
                        )
                        : [
                            'status' => $service
                                ->sync(
                                    $company
                                )
                                ->status,
                        ];

                $status =
                    (string)
                    $result[
                        'status'
                    ];

                $stats[
                    'processed'
                ]++;

                if (
                    array_key_exists(
                        $status,
                        $stats
                    )
                ) {
                    $stats[
                        $status
                    ]++;
                }

            } catch (
                Throwable $exception
            ) {
                $stats[
                    'failed'
                ]++;

                $this->error(
                    $company->cnpj_root
                    .' → '
                    .$exception
                        ->getMessage()
                );
            }
        }

        $this->newLine();

        $this->table(
            [
                'Status',
                'Quantidade',
            ],
            [
                [
                    'Processadas',
                    $stats[
                        'processed'
                    ],
                ],
                [
                    'Clientes',
                    $stats[
                        'client'
                    ],
                ],
                [
                    'Oportunidades',
                    $stats[
                        'opportunity'
                    ],
                ],
                [
                    'Prospectadas',
                    $stats[
                        'prospected'
                    ],
                ],
                [
                    'Conhecidas',
                    $stats[
                        'known'
                    ],
                ],
                [
                    'Não encontradas',
                    $stats[
                        'not_found'
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

        if ($dryRun) {
            $this->warn(
                'Preview: nada foi gravado.'
            );
        } else {
            $this->info(
                'CRM reconstruído a partir do espelho HubSpot.'
            );
        }

        return $stats[
            'failed'
        ] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
