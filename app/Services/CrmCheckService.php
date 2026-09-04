<?php

namespace App\Services;

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CompanyCrmCheck;
use Throwable;

final class CrmCheckService
{
    public function __construct(
        private readonly CustomerRegistryService $customers,
    ) {}

    public function check(
        Company $company,
        CrmCompanyProvider $provider,
    ): CompanyCrmCheck {
        /*
         * Fonte oficial interna.
         *
         * Se a empresa estiver na base oficial
         * de clientes ExportControl, essa
         * informação sempre prevalece.
         */
        $customerMatch =
            $this->customers->find(
                $company
            );

        $crmChecked = true;
        $crmError = null;

        try {
            $result =
                $provider->findCompany(
                    $company
                );
        } catch (Throwable $exception) {
            /*
             * Se a empresa já é cliente
             * confirmado pela base interna,
             * indisponibilidade do HubSpot
             * não deve apagar essa informação.
             */
            if (! $customerMatch) {
                throw $exception;
            }

            $crmChecked = false;

            $crmError = mb_substr(
                $exception->getMessage(),
                0,
                1000
            );

            $result =
                $this->emptyResult();
        }

        $crmReportedStatus =
            $crmChecked
                ? $this->status(
                    $result
                )
                : null;

        /*
         * Hierarquia:
         *
         * 1. Base oficial ExportControl.
         * 2. Negócios reais no HubSpot.
         * 3. Histórico/interações.
         */
        $status =
            $customerMatch
                ? 'client'
                : (
                    $crmReportedStatus
                    ?? 'not_found'
                );

        $metadata =
            $result['metadata'];

        $metadata['status_source'] =
            $customerMatch
                ? 'exportcontrol_customer_registry'
                : 'hubspot_commercial_history';

        $metadata['crm_checked'] =
            $crmChecked;

        $metadata['crm_reported_status'] =
            $crmReportedStatus;

        $metadata['hubspot_lifecycle_stage'] =
            $result['lifecycle_stage'];

        /*
         * O lifecycle continua salvo para
         * auditoria, mas NÃO determina mais
         * sozinho se a empresa é cliente.
         */
        $metadata['lifecycle_used_for_status'] =
            false;

        $metadata['deals'] =
            $result['deals'];

        $metadata['deal_summary'] =
            $this->dealSummary(
                $result['deals']
            );

        $metadata['crm_conflict'] =
            $customerMatch !== null
            && $crmChecked
            && $crmReportedStatus
                !== 'client';

        if ($customerMatch) {
            $entry =
                $customerMatch[
                    'entry'
                ];

            $metadata[
                'customer_registry'
            ] = [
                'entry_id' => $entry->id,

                'source' => $entry->source,

                'corporate_name' => $entry
                    ->corporate_name,

                'matched_by' => $customerMatch[
                        'matched_by'
                    ],

                'matched_value' => $customerMatch[
                        'matched_value'
                    ],
            ];
        }

        if ($crmError !== null) {
            $metadata['crm_error'] =
                $crmError;
        }

        return CompanyCrmCheck::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'provider' => $provider->name(),

                    'status' => $status,

                    /*
                     * Dados originais do
                     * HubSpot continuam
                     * armazenados.
                     */
                    'external_id' => $result[
                            'external_id'
                        ],

                    'external_name' => $result[
                            'name'
                        ],

                    'external_domain' => $result[
                            'domain'
                        ],

                    'lifecycle_stage' => $result[
                            'lifecycle_stage'
                        ],

                    'owner_external_id' => $result[
                            'owner_id'
                        ],

                    'contacted_count' => $result[
                            'contacted_count'
                        ],

                    'associated_deals_count' => $result[
                            'associated_deals_count'
                        ],

                    'last_contacted_at' => $result[
                            'last_contacted_at'
                        ],

                    'matched_by' => $result[
                            'matched_by'
                        ],

                    'matched_value' => $result[
                            'matched_value'
                        ],

                    'external_url' => $result[
                            'external_url'
                        ],

                    'metadata' => $metadata,

                    'checked_at' => now(),
                ]
            );
    }

    /**
     * @param array{
     *     found: bool,
     *     external_id: string|null,
     *     name: string|null,
     *     domain: string|null,
     *     lifecycle_stage: string|null,
     *     owner_id: string|null,
     *     contacted_count: int,
     *     associated_deals_count: int,
     *     deals: list<array{
     *         id: string,
     *         name: string|null,
     *         stage_id: string|null,
     *         stage_label: string|null,
     *         pipeline_id: string|null,
     *         is_closed: bool,
     *         is_closed_won: bool,
     *         closed_at: string|null
     *     }>,
     *     last_contacted_at: string|null,
     *     matched_by: string|null,
     *     matched_value: string|null,
     *     external_url: string|null,
     *     metadata: array<string, mixed>
     * } $result
     */
    private function status(
        array $result
    ): string {
        if (! $result['found']) {
            return 'not_found';
        }

        /*
         * CLIENTE:
         *
         * precisa existir negócio efetivamente
         * fechado como ganho.
         */
        foreach (
            $result['deals'] as $deal
        ) {
            if (
                $deal[
                    'is_closed_won'
                ]
            ) {
                return 'client';
            }
        }

        /*
         * OPORTUNIDADE:
         *
         * existe pelo menos um negócio
         * ainda aberto/ativo.
         */
        foreach (
            $result['deals'] as $deal
        ) {
            if (
                ! $deal[
                    'is_closed'
                ]
            ) {
                return 'opportunity';
            }
        }

        /*
         * PROSPECTADO:
         *
         * possui histórico comercial,
         * porém nenhum negócio ganho
         * e nenhum negócio atualmente ativo.
         *
         * Exemplo:
         * recusado, perdido, cancelado,
         * desqualificado.
         */
        if (
            $result['deals'] !== []
            || $result[
                'associated_deals_count'
            ] > 0
            || $result[
                'contacted_count'
            ] > 0
            || $result[
                'last_contacted_at'
            ] !== null
        ) {
            return 'prospected';
        }

        /*
         * Existe no CRM, mas sem histórico
         * comercial relevante.
         */
        return 'known';
    }

    /**
     * @param list<array{
     *     id: string,
     *     name: string|null,
     *     stage_id: string|null,
     *     stage_label: string|null,
     *     pipeline_id: string|null,
     *     is_closed: bool,
     *     is_closed_won: bool,
     *     closed_at: string|null
     * }> $deals
     * @return array{
     *     total: int,
     *     active: int,
     *     won: int,
     *     closed_lost: int,
     *     stages: list<string>
     * }
     */
    private function dealSummary(
        array $deals
    ): array {
        $active = 0;
        $won = 0;
        $closedLost = 0;
        $stages = [];

        foreach ($deals as $deal) {
            if (
                $deal[
                    'is_closed_won'
                ]
            ) {
                $won++;
            } elseif (
                $deal[
                    'is_closed'
                ]
            ) {
                $closedLost++;
            } else {
                $active++;
            }

            $stage =
                $deal[
                    'stage_label'
                ];

            if (
                is_string($stage)
                && trim($stage) !== ''
            ) {
                $stages[] =
                    trim($stage);
            }
        }

        return [
            'total' => count($deals),

            'active' => $active,

            'won' => $won,

            'closed_lost' => $closedLost,

            'stages' => array_values(
                array_unique(
                    $stages
                )
            ),
        ];
    }

    /**
     * @return array{
     *     found: bool,
     *     external_id: null,
     *     name: null,
     *     domain: null,
     *     lifecycle_stage: null,
     *     owner_id: null,
     *     contacted_count: int,
     *     associated_deals_count: int,
     *     deals: list<array{
     *         id: string,
     *         name: string|null,
     *         stage_id: string|null,
     *         stage_label: string|null,
     *         pipeline_id: string|null,
     *         is_closed: bool,
     *         is_closed_won: bool,
     *         closed_at: string|null
     *     }>,
     *     last_contacted_at: null,
     *     matched_by: null,
     *     matched_value: null,
     *     external_url: null,
     *     metadata: array<string, mixed>
     * }
     */
    private function emptyResult(): array
    {
        return [
            'found' => false,

            'external_id' => null,

            'name' => null,

            'domain' => null,

            'lifecycle_stage' => null,

            'owner_id' => null,

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'deals' => [],

            'last_contacted_at' => null,

            'matched_by' => null,

            'matched_value' => null,

            'external_url' => null,

            'metadata' => [],
        ];
    }
}
