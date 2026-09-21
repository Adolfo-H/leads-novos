<?php

namespace App\Services;

use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\HubSpotImportRun;
use App\Models\HubSpotPipelineStage;
use App\Models\HubSpotTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

final class HubSpotExportImportService
{
    /**
     * @param array{
     *     companies: string,
     *     deals: string,
     *     contacts: string,
     *     tasks: string
     * } $files
     */
    public function import(
        array $files,
        bool $replace = false,
    ): HubSpotImportRun {
        if ($replace) {
            $this->clearMirrorData();
        }

        $run =
            HubSpotImportRun::query()
                ->create([
                    'uuid' => (string) Str::uuid(),

                    'source' => 'hubspot_export',

                    'status' => 'processing',

                    'source_files' => [
                        'companies' => basename(
                            $files['companies']
                        ),

                        'deals' => basename(
                            $files['deals']
                        ),

                        'contacts' => basename(
                            $files['contacts']
                        ),

                        'tasks' => basename(
                            $files['tasks']
                        ),
                    ],

                    'started_at' => now(),
                ]);

        $tempDirectory =
            storage_path(
                'app/hubspot-import/tmp/'
                .$run->uuid
            );

        File::ensureDirectoryExists(
            $tempDirectory
        );

        try {
            $csvFiles = [
                'companies' => $this->resolveCsv(
                    $files['companies'],
                    'companies',
                    $tempDirectory,
                ),

                'deals' => $this->resolveCsv(
                    $files['deals'],
                    'deals',
                    $tempDirectory,
                ),

                'contacts' => $this->resolveCsv(
                    $files['contacts'],
                    'contacts',
                    $tempDirectory,
                ),

                'tasks' => $this->resolveCsv(
                    $files['tasks'],
                    'tasks',
                    $tempDirectory,
                ),
            ];

            /*
             * Antes de apagar a base antiga,
             * aproveitamos os matches já
             * conhecidos entre:
             *
             * HubSpot Company ID ↔ CNPJ.
             */
            $legacyMatches =
                $this->legacyCompanyMatches();

            $companyStats =
                $this->importCompanies(
                    $csvFiles['companies'],
                    $run,
                    $legacyMatches,
                );

            $dealStats =
                $this->importDeals(
                    $csvFiles['deals'],
                    $run,
                );

            $contactStats =
                $this->importContacts(
                    $csvFiles['contacts'],
                    $run,
                );

            $taskStats =
                $this->importTasks(
                    $csvFiles['tasks'],
                    $run,
                );

            /*
             * As associações representam
             * o snapshot atual exportado.
             *
             * Limpamos SOMENTE os pivôs
             * hubspot_* antes de reconstruí-los.
             */
            $this->clearAssociations();

            $associationStats =
                $this->importAssociations(
                    $csvFiles
                );

            $stats = [
                'companies' => $companyStats,

                'deals' => $dealStats,

                'contacts' => $contactStats,

                'tasks' => $taskStats,

                'associations' => $associationStats,
            ];

            $reportPath =
                $this->writeReport(
                    $run,
                    $stats
                );

            $stats['report_path'] =
                $reportPath;

            $run
                ->forceFill([
                    'status' => 'completed',

                    'stats' => $stats,

                    'finished_at' => now(),

                    'error' => null,
                ])
                ->save();

            return $run->refresh();
        } catch (Throwable $exception) {
            $run
                ->forceFill([
                    'status' => 'failed',

                    'finished_at' => now(),

                    'error' => mb_substr(
                        $exception->getMessage(),
                        0,
                        5000
                    ),
                ])
                ->save();

            throw $exception;
        } finally {
            File::deleteDirectory(
                $tempDirectory
            );
        }
    }

    /**
     * @param array<string, array{
     *     company_id: int,
     *     cnpj_root: string,
     *     corporate_name: string,
     *     source: string
     * }> $legacyMatches
     * @return array{
     *     rows: int,
     *     imported: int,
     *     missing_id: int,
     *     matched_cnpj: int
     * }
     */
    private function importCompanies(
        string $path,
        HubSpotImportRun $run,
        array $legacyMatches,
    ): array {
        $this->assertHeaders(
            $path,
            [
                'ID do registro',
                'Nome da empresa',
            ]
        );

        $stats = [
            'rows' => 0,
            'imported' => 0,
            'missing_id' => 0,
            'matched_cnpj' => 0,
        ];

        $this->eachRow(
            $path,
            function (
                array $row
            ) use (
                &$stats,
                $run,
                $legacyMatches,
            ): void {
                $stats['rows']++;

                $hubSpotId =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                if ($hubSpotId === '') {
                    $stats['missing_id']++;

                    return;
                }

                $legacy =
                    $legacyMatches[
                        $hubSpotId
                    ]
                    ?? null;

                if ($legacy !== null) {
                    $stats['matched_cnpj']++;
                }

                HubSpotCompany::query()
                    ->updateOrCreate(
                        [
                            'hubspot_id' => $hubSpotId,
                        ],
                        [
                            'hubspot_import_run_id' => $run->id,

                            'company_id' => $legacy[
                                    'company_id'
                                ]
                                ?? null,

                            'matched_cnpj_root' => $legacy[
                                    'cnpj_root'
                                ]
                                ?? null,

                            'matched_company_name' => $legacy[
                                    'corporate_name'
                                ]
                                ?? null,

                            'match_source' => $legacy[
                                    'source'
                                ]
                                ?? null,

                            'name' => $this->nullable(
                                $row,
                                'Nome da empresa'
                            ),

                            'domain' => $this->domain(
                                $this->value(
                                    $row,
                                    'Nome de domínio da empresa'
                                )
                            ),

                            'lifecycle_stage' => $this->nullable(
                                $row,
                                'Fase do ciclo de vida'
                            ),

                            'lead_status' => $this->nullable(
                                $row,
                                'Status do lead'
                            ),

                            'owner_name' => $this->nullable(
                                $row,
                                'Proprietário da empresa'
                            ),

                            'city' => $this->nullable(
                                $row,
                                'Cidade'
                            ),

                            'state' => $this->firstValue(
                                $row,
                                [
                                    'Estado/Região',
                                    'Código do estado/região',
                                ]
                            ),

                            'phone' => $this->nullable(
                                $row,
                                'Número de telefone'
                            ),

                            'last_activity_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data da última atividade'
                                )
                            ),

                            'hubspot_created_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data de criação'
                                )
                            ),

                            'hubspot_updated_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data da última modificação'
                                )
                            ),

                            'raw_properties' => $this->rawProperties(
                                $row
                            ),
                        ]
                    );

                $stats['imported']++;
            }
        );

        return $stats;
    }

    /**
     * @return array{
     *     rows: int,
     *     imported: int,
     *     missing_id: int,
     *     stages: array<string, int>
     * }
     */
    private function importDeals(
        string $path,
        HubSpotImportRun $run,
    ): array {
        $this->assertHeaders(
            $path,
            [
                'ID do registro',
                'Nome do negócio',
                'Pipeline',
                'Etapa do negócio',
            ]
        );

        $stats = [
            'rows' => 0,
            'imported' => 0,
            'missing_id' => 0,
            'stages' => [],
        ];

        $stageCache = [];

        $this->eachRow(
            $path,
            function (
                array $row
            ) use (
                &$stats,
                &$stageCache,
                $run,
            ): void {
                $stats['rows']++;

                $hubSpotId =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                if ($hubSpotId === '') {
                    $stats['missing_id']++;

                    return;
                }

                $pipelineLabel =
                    $this->value(
                        $row,
                        'Pipeline'
                    );

                if ($pipelineLabel === '') {
                    $pipelineLabel =
                        'Sem pipeline';
                }

                $stageLabel =
                    $this->value(
                        $row,
                        'Etapa do negócio'
                    );

                if ($stageLabel === '') {
                    $stageLabel =
                        'Sem etapa';
                }

                $isWon =
                    $this->boolValue(
                        $this->value(
                            $row,
                            'É negócio fechado'
                        )
                    );

                $isClosed =
                    $isWon
                    || $this->boolValue(
                        $this->value(
                            $row,
                            'O negócio está fechado?'
                        )
                    )
                    || $this->boolValue(
                        $this->value(
                            $row,
                            'Está fechado (numérico)'
                        )
                    );

                $stageKey =
                    mb_strtolower(
                        $pipelineLabel
                        .'|'
                        .$stageLabel
                    );

                if (
                    ! isset(
                        $stageCache[
                            $stageKey
                        ]
                    )
                ) {
                    $stage =
                        HubSpotPipelineStage::query()
                            ->updateOrCreate(
                                [
                                    'pipeline_label' => $pipelineLabel,

                                    'stage_label' => $stageLabel,
                                ],
                                [
                                    'is_closed' => $isClosed,

                                    'is_closed_won' => $isWon,
                                ]
                            );

                    $stageCache[
                        $stageKey
                    ] =
                        $stage->id;
                }

                $stats['stages'][
                    $stageLabel
                ] =
                    (
                        $stats['stages'][
                            $stageLabel
                        ]
                        ?? 0
                    )
                    + 1;

                HubSpotDeal::query()
                    ->updateOrCreate(
                        [
                            'hubspot_id' => $hubSpotId,
                        ],
                        [
                            'hubspot_import_run_id' => $run->id,

                            'hubspot_pipeline_stage_id' => $stageCache[
                                    $stageKey
                                ],

                            /*
                             * O CSV fornece os labels,
                             * não necessariamente os
                             * IDs internos do pipeline.
                             */
                            'pipeline_id' => null,

                            'pipeline_label' => $pipelineLabel,

                            'stage_id' => null,

                            'stage_label' => $stageLabel,

                            'name' => $this->nullable(
                                $row,
                                'Nome do negócio'
                            ),

                            'owner_name' => $this->nullable(
                                $row,
                                'Proprietário do negócio'
                            ),

                            'amount' => $this->decimal(
                                $this->value(
                                    $row,
                                    'Valor'
                                )
                            ),

                            'currency' => null,

                            'is_closed' => $isClosed,

                            'is_closed_won' => $isWon,

                            'hubspot_created_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data de criação'
                                )
                            ),

                            'closed_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data de fechamento'
                                )
                            ),

                            'last_activity_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data da última atividade'
                                )
                            ),

                            'raw_properties' => $this->rawProperties(
                                $row
                            ),
                        ]
                    );

                $stats['imported']++;
            }
        );

        ksort(
            $stats['stages']
        );

        return $stats;
    }

    /**
     * @return array{
     *     rows: int,
     *     imported: int,
     *     missing_id: int
     * }
     */
    private function importContacts(
        string $path,
        HubSpotImportRun $run,
    ): array {
        $this->assertHeaders(
            $path,
            [
                'ID do registro',
                'Nome',
            ]
        );

        $stats = [
            'rows' => 0,
            'imported' => 0,
            'missing_id' => 0,
        ];

        $this->eachRow(
            $path,
            function (
                array $row
            ) use (
                &$stats,
                $run,
            ): void {
                $stats['rows']++;

                $hubSpotId =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                if ($hubSpotId === '') {
                    $stats['missing_id']++;

                    return;
                }

                HubSpotContact::query()
                    ->updateOrCreate(
                        [
                            'hubspot_id' => $hubSpotId,
                        ],
                        [
                            'hubspot_import_run_id' => $run->id,

                            'first_name' => $this->nullable(
                                $row,
                                'Nome'
                            ),

                            'last_name' => $this->nullable(
                                $row,
                                'Sobrenome'
                            ),

                            'email' => $this->nullable(
                                $row,
                                'E-mail'
                            ),

                            'phone' => $this->nullable(
                                $row,
                                'Número de telefone'
                            ),

                            'mobile_phone' => $this->nullable(
                                $row,
                                'Número de telefone do WhatsApp'
                            ),

                            'job_title' => $this->nullable(
                                $row,
                                'Cargo'
                            ),

                            'company_name' => $this->nullable(
                                $row,
                                'Nome da empresa'
                            ),

                            'owner_name' => $this->nullable(
                                $row,
                                'Proprietário do contato'
                            ),

                            'lifecycle_stage' => $this->nullable(
                                $row,
                                'Fase do ciclo de vida'
                            ),

                            'last_activity_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data da última atividade'
                                )
                            ),

                            'hubspot_created_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data de criação'
                                )
                            ),

                            'raw_properties' => $this->rawProperties(
                                $row
                            ),
                        ]
                    );

                $stats['imported']++;
            }
        );

        return $stats;
    }

    /**
     * @return array{
     *     rows: int,
     *     imported: int,
     *     missing_id: int
     * }
     */
    private function importTasks(
        string $path,
        HubSpotImportRun $run,
    ): array {
        $this->assertHeaders(
            $path,
            [
                'ID do registro',
                'Título da tarefa',
            ]
        );

        $stats = [
            'rows' => 0,
            'imported' => 0,
            'missing_id' => 0,
        ];

        $this->eachRow(
            $path,
            function (
                array $row
            ) use (
                &$stats,
                $run,
            ): void {
                $stats['rows']++;

                $hubSpotId =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                if ($hubSpotId === '') {
                    $stats['missing_id']++;

                    return;
                }

                HubSpotTask::query()
                    ->updateOrCreate(
                        [
                            'hubspot_id' => $hubSpotId,
                        ],
                        [
                            'hubspot_import_run_id' => $run->id,

                            'title' => $this->nullable(
                                $row,
                                'Título da tarefa'
                            ),

                            'status' => $this->nullable(
                                $row,
                                'Status da tarefa'
                            ),

                            'stage' => $this->nullable(
                                $row,
                                'Fase da tarefa'
                            ),

                            'type' => $this->nullable(
                                $row,
                                'Tipo de tarefa'
                            ),

                            'assigned_to' => $this->nullable(
                                $row,
                                'Atribuído a'
                            ),

                            'is_open' => $this->boolValue(
                                $this->value(
                                    $row,
                                    'A tarefa está aberta'
                                )
                            ),

                            'is_overdue' => $this->boolValue(
                                $this->value(
                                    $row,
                                    'Vencido'
                                )
                            ),

                            'due_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Data de vencimento'
                                )
                            ),

                            'completed_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Concluído em'
                                )
                            ),

                            'hubspot_created_at' => $this->date(
                                $this->value(
                                    $row,
                                    'Criado em'
                                )
                            ),

                            'notes' => $this->nullable(
                                $row,
                                'Observações da tarefa'
                            ),

                            'raw_properties' => $this->rawProperties(
                                $row
                            ),
                        ]
                    );

                $stats['imported']++;
            }
        );

        return $stats;
    }

    /**
     * @param array{
     *     companies: string,
     *     deals: string,
     *     contacts: string,
     *     tasks: string
     * } $paths
     * @return array<string, mixed>
     */
    private function importAssociations(
        array $paths
    ): array {
        $companyMap =
            $this->externalIdMap(
                'hubspot_companies'
            );

        $dealMap =
            $this->externalIdMap(
                'hubspot_deals'
            );

        $contactMap =
            $this->externalIdMap(
                'hubspot_contacts'
            );

        $taskMap =
            $this->externalIdMap(
                'hubspot_tasks'
            );

        /** @var array<string, array<string, int|bool>> $companyDeals */
        $companyDeals = [];

        /** @var array<string, array<string, int|bool>> $companyContacts */
        $companyContacts = [];

        /** @var array<string, array<string, int|bool>> $dealContacts */
        $dealContacts = [];

        /** @var array<string, array<string, int|bool>> $companyTasks */
        $companyTasks = [];

        /** @var array<string, array<string, int|bool>> $dealTasks */
        $dealTasks = [];

        /** @var array<string, array<string, int|bool>> $contactTasks */
        $contactTasks = [];

        $orphans = [
            'company' => 0,
            'deal' => 0,
            'contact' => 0,
            'task' => 0,
        ];

        /*
         * EMPRESA:
         * usa negócios e contatos.
         *
         * Não usamos histórico de tarefas
         * daqui porque o export de tarefas
         * fornecido contém apenas a fila
         * atual e não todo histórico.
         */
        $this->eachRow(
            $paths['companies'],
            function (
                array $row
            ) use (
                &$companyDeals,
                &$companyContacts,
                &$orphans,
                $companyMap,
                $dealMap,
                $contactMap,
            ): void {
                $companyExternal =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                foreach (
                    $this->ids(
                        $row,
                        'Associated Deal IDs'
                    ) as $dealExternal
                ) {
                    $this->rememberAssociation(
                        $companyDeals,
                        $orphans,
                        $companyMap,
                        $dealMap,
                        $companyExternal,
                        $dealExternal,
                        'hubspot_company_id',
                        'hubspot_deal_id',
                        'company',
                        'deal',
                        false,
                    );
                }

                foreach (
                    $this->ids(
                        $row,
                        'Associated Contact IDs'
                    ) as $contactExternal
                ) {
                    $this->rememberAssociation(
                        $companyContacts,
                        $orphans,
                        $companyMap,
                        $contactMap,
                        $companyExternal,
                        $contactExternal,
                        'hubspot_company_id',
                        'hubspot_contact_id',
                        'company',
                        'contact',
                        false,
                    );
                }
            }
        );

        /*
         * NEGÓCIOS.
         */
        $this->eachRow(
            $paths['deals'],
            function (
                array $row
            ) use (
                &$companyDeals,
                &$dealContacts,
                &$orphans,
                $companyMap,
                $dealMap,
                $contactMap,
            ): void {
                $dealExternal =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                $primaryCompanies =
                    array_fill_keys(
                        $this->ids(
                            $row,
                            'Associated Company IDs (Primary)'
                        ),
                        true
                    );

                foreach (
                    $this->ids(
                        $row,
                        'Associated Company IDs'
                    ) as $companyExternal
                ) {
                    $this->rememberAssociation(
                        $companyDeals,
                        $orphans,
                        $companyMap,
                        $dealMap,
                        $companyExternal,
                        $dealExternal,
                        'hubspot_company_id',
                        'hubspot_deal_id',
                        'company',
                        'deal',
                        isset(
                            $primaryCompanies[
                                $companyExternal
                            ]
                        ),
                    );
                }

                foreach (
                    $this->ids(
                        $row,
                        'Associated Contact IDs'
                    ) as $contactExternal
                ) {
                    $this->rememberAssociation(
                        $dealContacts,
                        $orphans,
                        $dealMap,
                        $contactMap,
                        $dealExternal,
                        $contactExternal,
                        'hubspot_deal_id',
                        'hubspot_contact_id',
                        'deal',
                        'contact',
                    );
                }
            }
        );

        /*
         * CONTATOS.
         */
        $this->eachRow(
            $paths['contacts'],
            function (
                array $row
            ) use (
                &$companyContacts,
                &$dealContacts,
                &$orphans,
                $companyMap,
                $dealMap,
                $contactMap,
            ): void {
                $contactExternal =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                $primaryCompanies =
                    array_fill_keys(
                        $this->ids(
                            $row,
                            'Associated Company IDs (Primary)'
                        ),
                        true
                    );

                foreach (
                    $this->ids(
                        $row,
                        'Associated Company IDs'
                    ) as $companyExternal
                ) {
                    $this->rememberAssociation(
                        $companyContacts,
                        $orphans,
                        $companyMap,
                        $contactMap,
                        $companyExternal,
                        $contactExternal,
                        'hubspot_company_id',
                        'hubspot_contact_id',
                        'company',
                        'contact',
                        isset(
                            $primaryCompanies[
                                $companyExternal
                            ]
                        ),
                    );
                }

                foreach (
                    $this->ids(
                        $row,
                        'Associated Deal IDs'
                    ) as $dealExternal
                ) {
                    $this->rememberAssociation(
                        $dealContacts,
                        $orphans,
                        $dealMap,
                        $contactMap,
                        $dealExternal,
                        $contactExternal,
                        'hubspot_deal_id',
                        'hubspot_contact_id',
                        'deal',
                        'contact',
                    );
                }
            }
        );

        /*
         * TAREFAS:
         * o próprio arquivo de tarefas
         * é a fonte da verdade dos vínculos.
         */
        $this->eachRow(
            $paths['tasks'],
            function (
                array $row
            ) use (
                &$companyTasks,
                &$dealTasks,
                &$contactTasks,
                &$orphans,
                $companyMap,
                $dealMap,
                $contactMap,
                $taskMap,
            ): void {
                $taskExternal =
                    $this->value(
                        $row,
                        'ID do registro'
                    );

                foreach (
                    $this->ids(
                        $row,
                        'Associated Company IDs'
                    ) as $companyExternal
                ) {
                    $this->rememberAssociation(
                        $companyTasks,
                        $orphans,
                        $companyMap,
                        $taskMap,
                        $companyExternal,
                        $taskExternal,
                        'hubspot_company_id',
                        'hubspot_task_id',
                        'company',
                        'task',
                    );
                }

                foreach (
                    $this->ids(
                        $row,
                        'Associated Deal IDs'
                    ) as $dealExternal
                ) {
                    $this->rememberAssociation(
                        $dealTasks,
                        $orphans,
                        $dealMap,
                        $taskMap,
                        $dealExternal,
                        $taskExternal,
                        'hubspot_deal_id',
                        'hubspot_task_id',
                        'deal',
                        'task',
                    );
                }

                foreach (
                    $this->ids(
                        $row,
                        'Associated Contact IDs'
                    ) as $contactExternal
                ) {
                    $this->rememberAssociation(
                        $contactTasks,
                        $orphans,
                        $contactMap,
                        $taskMap,
                        $contactExternal,
                        $taskExternal,
                        'hubspot_contact_id',
                        'hubspot_task_id',
                        'contact',
                        'task',
                    );
                }
            }
        );

        $this->insertAssociations(
            'hubspot_company_deal',
            $companyDeals,
        );

        $this->insertAssociations(
            'hubspot_company_contact',
            $companyContacts,
        );

        $this->insertAssociations(
            'hubspot_deal_contact',
            $dealContacts,
        );

        $this->insertAssociations(
            'hubspot_company_task',
            $companyTasks,
        );

        $this->insertAssociations(
            'hubspot_deal_task',
            $dealTasks,
        );

        $this->insertAssociations(
            'hubspot_contact_task',
            $contactTasks,
        );

        $dealsWithCompanies = [];

        $companiesByDeal = [];

        foreach (
            $companyDeals as $association
        ) {
            $dealId =
                (int) $association[
                    'hubspot_deal_id'
                ];

            $dealsWithCompanies[
                $dealId
            ] =
                true;

            $companiesByDeal[
                $dealId
            ] =
                (
                    $companiesByDeal[
                        $dealId
                    ]
                    ?? 0
                )
                + 1;
        }

        $multiCompanyDeals =
            count(
                array_filter(
                    $companiesByDeal,
                    static fn (
                        int $count
                    ): bool => $count > 1
                )
            );

        return [
            'company_deal' => count(
                $companyDeals
            ),

            'company_deal_primary' => count(
                array_filter(
                    $companyDeals,
                    static fn (
                        array $row
                    ): bool => (
                        $row[
                            'is_primary'
                        ]
                        ?? false
                    ) === true
                )
            ),

            'company_contact' => count(
                $companyContacts
            ),

            'company_contact_primary' => count(
                array_filter(
                    $companyContacts,
                    static fn (
                        array $row
                    ): bool => (
                        $row[
                            'is_primary'
                        ]
                        ?? false
                    ) === true
                )
            ),

            'deal_contact' => count(
                $dealContacts
            ),

            'company_task' => count(
                $companyTasks
            ),

            'deal_task' => count(
                $dealTasks
            ),

            'contact_task' => count(
                $contactTasks
            ),

            'deals_without_company' => max(
                0,
                HubSpotDeal::query()
                    ->count()
                - count(
                    $dealsWithCompanies
                )
            ),

            'multi_company_deals' => $multiCompanyDeals,

            'orphan_references' => $orphans,
        ];
    }

    /**
     * @param  array<string, array<string, int|bool>>  $target
     * @param  array<string, int>  $orphans
     * @param  array<string, int>  $leftMap
     * @param  array<string, int>  $rightMap
     */
    private function rememberAssociation(
        array &$target,
        array &$orphans,
        array $leftMap,
        array $rightMap,
        string $leftExternalId,
        string $rightExternalId,
        string $leftColumn,
        string $rightColumn,
        string $leftOrphanKey,
        string $rightOrphanKey,
        ?bool $primary = null,
    ): void {
        if (
            $leftExternalId === ''
            || ! isset(
                $leftMap[
                    $leftExternalId
                ]
            )
        ) {
            $orphans[
                $leftOrphanKey
            ]++;

            return;
        }

        if (
            $rightExternalId === ''
            || ! isset(
                $rightMap[
                    $rightExternalId
                ]
            )
        ) {
            $orphans[
                $rightOrphanKey
            ]++;

            return;
        }

        $leftId =
            $leftMap[
                $leftExternalId
            ];

        $rightId =
            $rightMap[
                $rightExternalId
            ];

        $key =
            $leftId
            .'|'
            .$rightId;

        $row = [
            $leftColumn => $leftId,

            $rightColumn => $rightId,
        ];

        if ($primary !== null) {
            $existingPrimary =
                (
                    $target[
                        $key
                    ][
                        'is_primary'
                    ]
                    ?? false
                ) === true;

            $row[
                'is_primary'
            ] =
                $existingPrimary
                || $primary;
        }

        $target[
            $key
        ] =
            $row;
    }

    /**
     * @param  array<string, array<string, int|bool>>  $rows
     */
    private function insertAssociations(
        string $table,
        array $rows,
    ): void {
        if ($rows === []) {
            return;
        }

        $now =
            now();

        $prepared = [];

        foreach ($rows as $row) {
            $row['created_at'] =
                $now;

            $row['updated_at'] =
                $now;

            $prepared[] =
                $row;
        }

        foreach (
            array_chunk(
                $prepared,
                500
            ) as $chunk
        ) {
            DB::table(
                $table
            )->insert(
                $chunk
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function externalIdMap(
        string $table
    ): array {
        $map = [];

        $rows =
            DB::table(
                $table
            )
                ->select([
                    'id',
                    'hubspot_id',
                ])
                ->get();

        foreach ($rows as $row) {
            $external =
                trim(
                    (string) $row
                        ->hubspot_id
                );

            if ($external === '') {
                continue;
            }

            $map[
                $external
            ] =
                (int) $row->id;
        }

        return $map;
    }

    /**
     * @return array<string, array{
     *     company_id: int,
     *     cnpj_root: string,
     *     corporate_name: string,
     *     source: string
     * }>
     */
    private function legacyCompanyMatches(): array
    {
        $matches = [];

        $crmMatches =
            DB::table(
                'company_crm_checks as crm'
            )
                ->join(
                    'companies',
                    'companies.id',
                    '=',
                    'crm.company_id'
                )
                ->whereNotNull(
                    'crm.external_id'
                )
                ->select([
                    'crm.external_id',
                    'companies.id as company_id',
                    'companies.cnpj_root',
                    'companies.corporate_name',
                ])
                ->get();

        foreach ($crmMatches as $row) {
            $hubSpotId =
                trim(
                    (string) $row
                        ->external_id
                );

            if ($hubSpotId === '') {
                continue;
            }

            $matches[
                $hubSpotId
            ] = [
                'company_id' => (int) $row
                    ->company_id,

                'cnpj_root' => (string) $row
                    ->cnpj_root,

                'corporate_name' => (string) $row
                    ->corporate_name,

                'source' => 'legacy_crm_check',
            ];
        }

        $leadMatches =
            DB::table(
                'company_hubspot_leads as lead'
            )
                ->join(
                    'companies',
                    'companies.id',
                    '=',
                    'lead.company_id'
                )
                ->whereNotNull(
                    'lead.hubspot_company_id'
                )
                ->select([
                    'lead.hubspot_company_id',
                    'companies.id as company_id',
                    'companies.cnpj_root',
                    'companies.corporate_name',
                ])
                ->get();

        foreach ($leadMatches as $row) {
            $hubSpotId =
                trim(
                    (string) $row
                        ->hubspot_company_id
                );

            if (
                $hubSpotId === ''
                || isset(
                    $matches[
                        $hubSpotId
                    ]
                )
            ) {
                continue;
            }

            $matches[
                $hubSpotId
            ] = [
                'company_id' => (int) $row
                    ->company_id,

                'cnpj_root' => (string) $row
                    ->cnpj_root,

                'corporate_name' => (string) $row
                    ->corporate_name,

                'source' => 'legacy_hubspot_lead',
            ];
        }

        return $matches;
    }

    private function clearAssociations(): void
    {
        $tables = [
            'hubspot_contact_task',
            'hubspot_deal_task',
            'hubspot_company_task',
            'hubspot_deal_contact',
            'hubspot_company_contact',
            'hubspot_company_deal',
        ];

        foreach ($tables as $table) {
            DB::table(
                $table
            )->delete();
        }
    }

    private function clearMirrorData(): void
    {
        $this->clearAssociations();

        $tables = [
            'hubspot_tasks',
            'hubspot_contacts',
            'hubspot_deals',
            'hubspot_companies',
            'hubspot_pipeline_stages',
            'hubspot_import_runs',
        ];

        foreach ($tables as $table) {
            DB::table(
                $table
            )->delete();
        }
    }

    private function resolveCsv(
        string $input,
        string $type,
        string $tempDirectory,
    ): string {
        $path =
            $this->absolutePath(
                $input
            );

        if (! File::exists($path)) {
            throw new RuntimeException(
                'Arquivo não encontrado: '
                .$path
            );
        }

        $extension =
            mb_strtolower(
                pathinfo(
                    $path,
                    PATHINFO_EXTENSION
                )
            );

        if ($extension === 'csv') {
            return $path;
        }

        if ($extension !== 'zip') {
            throw new RuntimeException(
                'Formato inválido para '
                .$type
                .': '
                .$extension
            );
        }

        if (
            ! class_exists(
                ZipArchive::class
            )
        ) {
            throw new RuntimeException(
                'Extensão PHP ZIP não disponível.'
            );
        }

        $zip =
            new ZipArchive;

        if (
            $zip->open(
                $path
            ) !== true
        ) {
            throw new RuntimeException(
                'Não foi possível abrir ZIP: '
                .$path
            );
        }

        try {
            for (
                $index = 0;
                $index < $zip->numFiles;
                $index++
            ) {
                $name =
                    $zip->getNameIndex(
                        $index
                    );

                if (
                    ! is_string($name)
                    || ! str_ends_with(
                        mb_strtolower($name),
                        '.csv'
                    )
                ) {
                    continue;
                }

                $contents =
                    $zip->getFromIndex(
                        $index
                    );

                if (! is_string($contents)) {
                    continue;
                }

                $destination =
                    $tempDirectory
                    .DIRECTORY_SEPARATOR
                    .$type
                    .'.csv';

                File::put(
                    $destination,
                    $contents
                );

                return $destination;
            }
        } finally {
            $zip->close();
        }

        throw new RuntimeException(
            'Nenhum CSV encontrado dentro de '
            .$path
        );
    }

    private function absolutePath(
        string $path
    ): string {
        $path =
            trim(
                $path
            );

        if (
            str_starts_with(
                $path,
                DIRECTORY_SEPARATOR
            )
        ) {
            return $path;
        }

        return base_path(
            $path
        );
    }

    /**
     * @param  list<string>  $required
     */
    private function assertHeaders(
        string $path,
        array $required,
    ): void {
        $headers =
            $this->headers(
                $path
            );

        foreach ($required as $header) {
            if (
                ! in_array(
                    $header,
                    $headers,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Coluna obrigatória não encontrada: '
                    .$header
                    .' em '
                    .basename($path)
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function headers(
        string $path
    ): array {
        $handle =
            fopen(
                $path,
                'rb'
            );

        if ($handle === false) {
            throw new RuntimeException(
                'Não foi possível abrir CSV: '
                .$path
            );
        }

        try {
            $headers =
                fgetcsv(
                    $handle,
                    0,
                    ',',
                    '"',
                    ''
                );

            if (! is_array($headers)) {
                throw new RuntimeException(
                    'CSV sem cabeçalho: '
                    .$path
                );
            }

            $result = [];

            foreach ($headers as $header) {
                $value =
                    preg_replace(
                        '/^\xEF\xBB\xBF/',
                        '',
                        (string) $header
                    )
                    ?? '';

                $result[] =
                    trim(
                        $value
                    );
            }

            return $result;
        } finally {
            fclose(
                $handle
            );
        }
    }

    /**
     * @param  callable(array<string, string>): void  $callback
     */
    private function eachRow(
        string $path,
        callable $callback,
    ): void {
        $handle =
            fopen(
                $path,
                'rb'
            );

        if ($handle === false) {
            throw new RuntimeException(
                'Não foi possível abrir CSV: '
                .$path
            );
        }

        try {
            $headers =
                fgetcsv(
                    $handle,
                    0,
                    ',',
                    '"',
                    ''
                );

            if (! is_array($headers)) {
                throw new RuntimeException(
                    'CSV sem cabeçalho.'
                );
            }

            $normalizedHeaders = [];

            foreach ($headers as $header) {
                $value =
                    preg_replace(
                        '/^\xEF\xBB\xBF/',
                        '',
                        (string) $header
                    )
                    ?? '';

                $normalizedHeaders[] =
                    trim(
                        $value
                    );
            }

            while (
                (
                    $values =
                        fgetcsv(
                            $handle,
                            0,
                            ',',
                            '"',
                            ''
                        )
                ) !== false
            ) {
                $row = [];

                foreach (
                    $normalizedHeaders as $index => $header
                ) {
                    if ($header === '') {
                        continue;
                    }

                    $row[
                        $header
                    ] =
                        isset(
                            $values[
                                $index
                            ]
                        )
                            ? (string) $values[
                                $index
                            ]
                            : '';
                }

                $callback(
                    $row
                );
            }
        } finally {
            fclose(
                $handle
            );
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function ids(
        array $row,
        string $column,
    ): array {
        $value =
            $this->value(
                $row,
                $column
            );

        if ($value === '') {
            return [];
        }

        $ids = [];

        foreach (
            explode(
                ';',
                $value
            ) as $id
        ) {
            $id =
                trim(
                    $id
                );

            if ($id !== '') {
                $ids[] =
                    $id;
            }
        }

        return array_values(
            array_unique(
                $ids
            )
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function value(
        array $row,
        string $column,
    ): string {
        return trim(
            $row[
                $column
            ]
            ?? ''
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function nullable(
        array $row,
        string $column,
    ): ?string {
        $value =
            $this->value(
                $row,
                $column
            );

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $columns
     */
    private function firstValue(
        array $row,
        array $columns,
    ): ?string {
        foreach ($columns as $column) {
            $value =
                $this->value(
                    $row,
                    $column
                );

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function domain(
        string $value
    ): ?string {
        $value =
            mb_strtolower(
                trim(
                    $value
                )
            );

        if ($value === '') {
            return null;
        }

        $value =
            preg_replace(
                '#^https?://#',
                '',
                $value
            )
            ?? $value;

        $value =
            explode(
                '/',
                $value
            )[0];

        if (
            str_starts_with(
                $value,
                'www.'
            )
        ) {
            $value =
                mb_substr(
                    $value,
                    4
                );
        }

        return rtrim(
            $value,
            '.'
        );
    }

    private function boolValue(
        string $value
    ): bool {
        return in_array(
            mb_strtolower(
                trim(
                    $value
                )
            ),
            [
                '1',
                '1.0',
                'true',
                'yes',
                'sim',
                'verdadeiro',
            ],
            true
        );
    }

    private function decimal(
        string $value
    ): ?string {
        $value =
            trim(
                $value
            );

        if (
            $value === ''
            || ! is_numeric(
                $value
            )
        ) {
            return null;
        }

        return number_format(
            (float) $value,
            2,
            '.',
            ''
        );
    }

    private function date(
        string $value
    ): ?CarbonImmutable {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value,
                config(
                    'app.timezone'
                )
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function rawProperties(
        array $row
    ): array {
        return array_filter(
            $row,
            static fn (
                string $value
            ): bool => trim(
                $value
            ) !== ''
        );
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function writeReport(
        HubSpotImportRun $run,
        array $stats,
    ): string {
        $directory =
            app()->environment(
                'testing'
            )
                ? storage_path(
                    'framework/testing/hubspot-reports'
                )
                : storage_path(
                    'app/hubspot-import/reports'
                );

        File::ensureDirectoryExists(
            $directory
        );

        $path =
            $directory
            .DIRECTORY_SEPARATOR
            .'hubspot-import-'
            .$run->uuid
            .'.json';

        File::put(
            $path,
            json_encode(
                [
                    'run_uuid' => $run->uuid,

                    'generated_at' => now()
                        ->toIso8601String(),

                    'stats' => $stats,
                ],
                JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
        );

        return $path;
    }
}
