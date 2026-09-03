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
         * A base oficial interna é a nossa
         * fonte prioritária para saber se
         * uma empresa já é cliente.
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
             * Se a empresa já está confirmada
             * como cliente na base interna,
             * uma indisponibilidade do CRM
             * não pode apagar essa informação.
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
         * A base interna sempre ganha.
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
                : 'crm';

        $metadata['crm_checked'] =
            $crmChecked;

        $metadata['crm_reported_status'] =
            $crmReportedStatus;

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
                     * Continuamos armazenando
                     * os dados reais do HubSpot,
                     * mesmo quando a base interna
                     * sobrescreve a classificação.
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

        if (
            $result[
                'lifecycle_stage'
            ] === 'customer'
        ) {
            return 'client';
        }

        if (
            $result[
                'lifecycle_stage'
            ] === 'opportunity'
            || $result[
                'associated_deals_count'
            ] > 0
        ) {
            return 'opportunity';
        }

        if (
            $result[
                'contacted_count'
            ] > 0
            || $result[
                'last_contacted_at'
            ] !== null
        ) {
            return 'prospected';
        }

        return 'known';
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

            'last_contacted_at' => null,

            'matched_by' => null,

            'matched_value' => null,

            'external_url' => null,

            'metadata' => [],
        ];
    }
}
