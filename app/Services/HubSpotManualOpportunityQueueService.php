<?php

namespace App\Services;

use App\Jobs\SyncManualHubSpotOpportunity;
use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class HubSpotManualOpportunityQueueService
{
    public function __construct(
        private readonly HubSpotLeadEligibilityService $eligibility,
    ) {}

    public function enqueue(
        Company $company,
        User $actor,
    ): CompanyHubSpotLead {
        if (! $this->enabled()) {
            throw new RuntimeException(
                'A criação manual de oportunidades '
                .'no HubSpot está desativada.'
            );
        }

        $company->loadMissing([
            'crmCheck',
            'hubSpotLead',
        ]);

        $evaluation =
            $this->eligibility
                ->evaluateManual(
                    $company
                );

        if (! $evaluation['eligible']) {
            throw new RuntimeException(
                $evaluation['reason']
            );
        }

        $sync =
            CompanyHubSpotLead::query()
                ->firstOrNew([
                    'company_id' => $company->id,
                ]);

        if (
            $sync->exists
            && $sync->synced_at !== null
        ) {
            throw new RuntimeException(
                'A empresa já foi sincronizada '
                .'com o HubSpot.'
            );
        }

        $metadata =
            $this->metadata(
                $sync
            );

        $manual =
            $this->manualMetadata(
                $metadata
            );

        $status =
            trim(
                (string) (
                    $manual['status']
                    ?? ''
                )
            );

        if (
            in_array(
                $status,
                [
                    'queued',
                    'processing',
                    'retrying',
                ],
                true
            )
            && ! $this->isStale(
                $manual
            )
        ) {
            throw new RuntimeException(
                'A oportunidade já está sendo '
                .'processada pelo HubSpot.'
            );
        }

        $metadata[
            'manual_sync'
        ] =
            array_merge(
                $manual,
                [
                    'status' => 'queued',

                    'progress' => 5,

                    'step' => 'queued',

                    'progress_message' => 'Solicitação enviada para a fila.',

                    'initiated_by_user_id' => $actor->id,

                    'initiated_by_email' => $actor->email,

                    'queued_at' => now()
                        ->toIso8601String(),

                    'started_at' => null,

                    'failed_at' => null,

                    'last_error' => null,
                ]
            );

        $sync->forceFill([
            'company_id' => $company->id,

            'pipeline_id' => trim(
                (string) config(
                    'services.hubspot.lead_pipeline',
                    'default'
                )
            ),

            'deal_stage_id' => trim(
                (string) config(
                    'services.hubspot.lead_initial_stage',
                    'appointmentscheduled'
                )
            ),

            'sync_error' => null,

            'metadata' => $metadata,
        ])->save();

        SyncManualHubSpotOpportunity::dispatch(
            companyId: $company->id,
            actorId: $actor->id,
        );

        return $sync->refresh();
    }

    public function enabled(): bool
    {
        return (bool) config(
            'services.hubspot.manual_opportunity_enabled',
            false
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(
        CompanyHubSpotLead $sync
    ): array {
        $raw =
            $sync->getAttribute(
                'metadata'
            );

        return is_array($raw)
            ? $raw
            : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function manualMetadata(
        array $metadata
    ): array {
        $manual =
            $metadata[
                'manual_sync'
            ]
            ?? [];

        return is_array($manual)
            ? $manual
            : [];
    }

    /**
     * @param  array<string, mixed>  $manual
     */
    private function isStale(
        array $manual
    ): bool {
        $rawTimestamp =
            $manual['started_at']
            ?? $manual['queued_at']
            ?? null;

        if (
            ! is_scalar(
                $rawTimestamp
            )
            || trim(
                (string) $rawTimestamp
            ) === ''
        ) {
            return false;
        }

        try {
            $timestamp =
                CarbonImmutable::parse(
                    (string) $rawTimestamp
                );
        } catch (Throwable) {
            return false;
        }

        $minutes =
            max(
                5,
                (int) config(
                    'services.hubspot.manual_opportunity_stale_minutes',
                    20
                )
            );

        return $timestamp
            ->lessThanOrEqualTo(
                now()
                    ->subMinutes(
                        $minutes
                    )
            );
    }
}
