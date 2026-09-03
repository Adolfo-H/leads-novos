<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerRegistryEntry;
use App\Support\TextNormalizer;

final class CustomerRegistryService
{
    /**
     * @return array{
     *     entry: CustomerRegistryEntry,
     *     matched_by: string,
     *     matched_value: string
     * }|null
     */
    public function find(
        Company $company
    ): ?array {
        $entry =
            CustomerRegistryEntry::query()
                ->where('enabled', true)
                ->where(
                    'cnpj_root',
                    $company->cnpj_root
                )
                ->first();

        if ($entry) {
            return [
                'entry' => $entry,
                'matched_by' => 'cnpj_root',
                'matched_value' => $company->cnpj_root,
            ];
        }

        /*
         * Fallback extremamente conservador:
         *
         * somente registros da nossa base
         * que NÃO possuem CNPJ podem ser
         * encontrados pelo nome.
         */
        $normalizedName =
            TextNormalizer::companyName(
                $company->corporate_name
            );

        if (! $normalizedName) {
            return null;
        }

        $entry =
            CustomerRegistryEntry::query()
                ->where('enabled', true)
                ->whereNull('cnpj_root')
                ->where(
                    'normalized_name',
                    $normalizedName
                )
                ->first();

        if (! $entry) {
            return null;
        }

        return [
            'entry' => $entry,
            'matched_by' => 'exact_name',
            'matched_value' => $normalizedName,
        ];
    }
}
