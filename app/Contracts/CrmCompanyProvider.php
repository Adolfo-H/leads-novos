<?php

namespace App\Contracts;

use App\Models\Company;

interface CrmCompanyProvider
{
    public function name(): string;

    /**
     * @return array{
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
     * }
     */
    public function findCompany(
        Company $company
    ): array;
}
