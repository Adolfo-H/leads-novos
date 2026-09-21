<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyLeadWorkState;
use App\Models\User;
use App\Services\HubSpotMirrorLeadProjectionService;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Throwable;

class RebuildHubSpotLeadProjections extends Command
{
    protected $signature =
        'hubspot:rebuild-lead-projections
        {--limit=0}
        {--dry-run}';

    protected $description =
        'Reconstrói a projeção operacional dos Leads usando o espelho HubSpot';

    public function handle(
        HubSpotMirrorLeadProjectionService $service
    ): int {
        $limit =
            max(
                0,
                (int) $this->option(
                    'limit'
                )
            );

        $query =
            Company::query()
                ->with([
                    'crmCheck',
                    'icpScore',
                    'exportIntelligence',
                ])
                ->orderBy(
                    'id'
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

        /*
         * Nome HubSpot → usuário interno.
         *
         * Só usamos match EXATO normalizado.
         */
        $usersByName = [];

        $users =
            User::query()
                ->orderBy(
                    'id'
                )
                ->get([
                    'id',
                    'name',
                ]);

        foreach ($users as $user) {
            $normalized =
                TextNormalizer::companyName(
                    $user->name
                );

            if ($normalized === null) {
                continue;
            }

            $usersByName[
                $normalized
            ] ??= [];

            $usersByName[
                $normalized
            ][] =
                $user;
        }

        $stats = [
            'processed' => 0,

            'new' => 0,

            'contacting' => 0,

            'waiting' => 0,

            'future' => 0,

            'refused' => 0,

            'converted' => 0,

            'discarded' => 0,

            'owner_assigned' => 0,

            'owner_unmatched' => 0,

            'owner_ambiguous' => 0,

            'multiple_deals' => 0,

            'failed' => 0,
        ];

        foreach (
            $companies as $company
        ) {
            try {
                $projection =
                    $service->build(
                        $company
                    );

                $status =
                    $projection[
                        'work_status'
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

                $deals =
                    data_get(
                        $projection,
                        'metadata.deals',
                        []
                    );

                if (
                    is_array($deals)
                    && count($deals) > 1
                ) {
                    $stats[
                        'multiple_deals'
                    ]++;
                }

                $ownerId = null;

                $ownerNames =
                    $projection[
                        'owner_names'
                    ];

                foreach (
                    $ownerNames as $ownerName
                ) {
                    $normalized =
                        TextNormalizer::companyName(
                            $ownerName
                        );

                    if ($normalized === null) {
                        continue;
                    }

                    $matches =
                        $usersByName[
                            $normalized
                        ]
                        ?? [];

                    if (count($matches) === 1) {
                        $ownerId =
                            $matches[0]
                                ->id;

                        break;
                    }

                    if (count($matches) > 1) {
                        $stats[
                            'owner_ambiguous'
                        ]++;
                    }
                }

                if (
                    $ownerId === null
                    && $ownerNames !== []
                ) {
                    $stats[
                        'owner_unmatched'
                    ]++;
                }

                if ($dryRun) {
                    continue;
                }

                $lead =
                    $service->sync(
                        $company
                    );

                $state =
                    CompanyLeadWorkState::query()
                        ->where(
                            'company_id',
                            $company->id
                        )
                        ->first();

                if ($state === null) {
                    $state =
                        CompanyLeadWorkState::query()
                            ->create([
                                'company_id' => $company->id,

                                'status' => $lead->work_status,

                                'assigned_user_id' => $ownerId,

                                'last_action_at' => $lead
                                    ->last_activity_at,

                                'next_action_at' => $lead
                                    ->last_task_due_at,
                            ]);

                    if ($ownerId !== null) {
                        $stats[
                            'owner_assigned'
                        ]++;
                    }
                } else {
                    $payload = [
                        'status' => $lead->work_status,

                        'last_action_at' => $lead
                            ->last_activity_at,

                        'next_action_at' => $lead
                            ->last_task_due_at,
                    ];

                    /*
                     * Nunca sobrescrevemos uma
                     * atribuição manual existente.
                     */
                    if (
                        $state
                            ->assigned_user_id
                        === null
                        && $ownerId !== null
                    ) {
                        $payload[
                            'assigned_user_id'
                        ] =
                            $ownerId;

                        $stats[
                            'owner_assigned'
                        ]++;
                    }

                    $state->update(
                        $payload
                    );
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
                'Indicador',
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
                    'Novo',
                    $stats['new'],
                ],
                [
                    'Em contato',
                    $stats[
                        'contacting'
                    ],
                ],
                [
                    'Aguardando',
                    $stats[
                        'waiting'
                    ],
                ],
                [
                    'Futuro',
                    $stats[
                        'future'
                    ],
                ],
                [
                    'Recusado',
                    $stats[
                        'refused'
                    ],
                ],
                [
                    'Convertido',
                    $stats[
                        'converted'
                    ],
                ],
                [
                    'Descartado',
                    $stats[
                        'discarded'
                    ],
                ],
                [
                    'Responsáveis vinculados',
                    $stats[
                        'owner_assigned'
                    ],
                ],
                [
                    'Owner sem usuário local',
                    $stats[
                        'owner_unmatched'
                    ],
                ],
                [
                    'Owner ambíguo',
                    $stats[
                        'owner_ambiguous'
                    ],
                ],
                [
                    'Empresas com +1 negócio',
                    $stats[
                        'multiple_deals'
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
                'Projeção operacional reconstruída.'
            );
        }

        return $stats[
            'failed'
        ] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
