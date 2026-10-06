<?php

namespace App\Services;

use App\Models\CompanyHubSpotLead;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CompanyHubSpotLeadMetadataService
{
    /**
     * Atualiza somente partes do metadata usando
     * lock da linha.
     *
     * Isso impede que dois jobs concorrentes:
     *
     * - manual opportunity;
     * - webhook;
     * - realtime refresh;
     *
     * sobrescrevam metadata um do outro.
     *
     * @param  array<string, mixed>  $values
     */
    public function merge(
        int $leadId,
        array $values,
    ): CompanyHubSpotLead {
        return DB::transaction(
            function () use (
                $leadId,
                $values,
            ): CompanyHubSpotLead {
                $lead =
                    CompanyHubSpotLead::query()
                        ->lockForUpdate()
                        ->find(
                            $leadId
                        );

                if ($lead === null) {
                    throw new RuntimeException(
                        'Projeção HubSpot não encontrada.'
                    );
                }

                $rawMetadata =
                    $lead->getAttribute(
                        'metadata'
                    );

                $metadata =
                    is_array(
                        $rawMetadata
                    )
                        ? $rawMetadata
                        : [];

                foreach (
                    $values as $key => $value
                ) {
                    $metadata[
                        $key
                    ] =
                        $value;
                }

                $lead->forceFill([
                    'metadata' => $metadata,
                ])->save();

                return $lead->refresh();
            },
            3
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function mergeManualSync(
        int $leadId,
        array $values,
    ): CompanyHubSpotLead {
        return DB::transaction(
            function () use (
                $leadId,
                $values,
            ): CompanyHubSpotLead {
                $lead =
                    CompanyHubSpotLead::query()
                        ->lockForUpdate()
                        ->find(
                            $leadId
                        );

                if ($lead === null) {
                    throw new RuntimeException(
                        'Projeção HubSpot não encontrada.'
                    );
                }

                $rawMetadata =
                    $lead->getAttribute(
                        'metadata'
                    );

                $metadata =
                    is_array(
                        $rawMetadata
                    )
                        ? $rawMetadata
                        : [];

                $rawManual =
                    $metadata[
                        'manual_sync'
                    ]
                    ?? [];

                $manual =
                    is_array(
                        $rawManual
                    )
                        ? $rawManual
                        : [];

                $metadata[
                    'manual_sync'
                ] =
                    array_merge(
                        $manual,
                        $values,
                    );

                $lead->forceFill([
                    'metadata' => $metadata,
                ])->save();

                return $lead->refresh();
            },
            3
        );
    }
}
