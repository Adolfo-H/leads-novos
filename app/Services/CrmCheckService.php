<?php

namespace App\Services;

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CompanyCrmCheck;

final class CrmCheckService
{
    public function check(
        Company $company,
        CrmCompanyProvider $provider,
    ): CompanyCrmCheck {
        $result =
            $provider->findCompany(
                $company
            );

        return CompanyCrmCheck::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'provider' => $provider->name(),

                    'status' => $this->status(
                        $result
                    ),

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

                    'metadata' => $result[
                            'metadata'
                        ],

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
            $result['lifecycle_stage']
            === 'customer'
        ) {
            return 'client';
        }

        if (
            $result['lifecycle_stage']
            === 'opportunity'
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
}
