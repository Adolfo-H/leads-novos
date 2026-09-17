<?php

namespace App\Services;

use App\Models\CompanyHubSpotLead;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class HubSpotLeadReprospectingService
{
    /**
     * @return array{
     *     applicable: bool,
     *     eligible: bool,
     *     reason: string,
     *     message: string,
     *     next_allowed_at: string|null,
     *     days_remaining: int|null
     * }
     */
    public function evaluate(
        CompanyHubSpotLead $lead
    ): array {
        $status =
            trim(
                (string) $lead
                    ->work_status
            );

        if ($status === 'future') {
            return $this->future(
                $lead
            );
        }

        if ($status === 'refused') {
            return $this->refused(
                $lead
            );
        }

        if ($status === 'discarded') {
            return [
                'applicable' => true,

                'eligible' => false,

                'reason' => 'manual_discard',

                'message' => 'Descartado exige revisão manual antes de uma nova prospecção.',

                'next_allowed_at' => null,

                'days_remaining' => null,
            ];
        }

        return [
            'applicable' => false,

            'eligible' => false,

            'reason' => 'not_applicable',

            'message' => 'Lead não está em estado de reprospecção.',

            'next_allowed_at' => null,

            'days_remaining' => null,
        ];
    }

    /**
     * @return array{
     *     applicable: bool,
     *     eligible: bool,
     *     reason: string,
     *     message: string,
     *     next_allowed_at: string|null,
     *     days_remaining: int|null
     * }
     */
    private function future(
        CompanyHubSpotLead $lead
    ): array {
        $dueAt =
            $this->dateValue(
                $lead->last_task_due_at
            );

        if ($dueAt === null) {
            return [
                'applicable' => true,

                'eligible' => false,

                'reason' => 'future_without_date',

                'message' => 'Oportunidade futura sem data de retomada definida.',

                'next_allowed_at' => null,

                'days_remaining' => null,
            ];
        }

        $now =
            CarbonImmutable::now();

        $eligible =
            $now->greaterThanOrEqualTo(
                $dueAt
            );

        return [
            'applicable' => true,

            'eligible' => $eligible,

            'reason' => $eligible
                    ? 'future_due'
                    : 'future_scheduled',

            'message' => $eligible
                    ? 'Oportunidade futura pronta para retomada.'
                    : 'Retomada agendada para '
                        .$dueAt->format(
                            'd/m/Y H:i'
                        )
                        .'.',

            'next_allowed_at' => $dueAt->toIso8601String(),

            'days_remaining' => $this->daysRemaining(
                $now,
                $dueAt
            ),
        ];
    }

    /**
     * @return array{
     *     applicable: bool,
     *     eligible: bool,
     *     reason: string,
     *     message: string,
     *     next_allowed_at: string|null,
     *     days_remaining: int|null
     * }
     */
    private function refused(
        CompanyHubSpotLead $lead
    ): array {
        $cooldownDays =
            max(
                1,
                (int) config(
                    'prospector.crm.reprospecting_after_days',
                    180
                )
            );

        $changedAt =
            $this->dateValue(
                $lead->work_status_changed_at
            )
            ?? $this->dateValue(
                $lead->last_activity_at
            )
            ?? $this->dateValue(
                $lead->synced_at
            );

        if ($changedAt === null) {
            return [
                'applicable' => true,

                'eligible' => false,

                'reason' => 'refused_date_unknown',

                'message' => 'Não há data segura para liberar a reprospecção automática.',

                'next_allowed_at' => null,

                'days_remaining' => null,
            ];
        }

        $nextAllowedAt =
            $changedAt->addDays(
                $cooldownDays
            );

        $now =
            CarbonImmutable::now();

        $eligible =
            $now->greaterThanOrEqualTo(
                $nextAllowedAt
            );

        return [
            'applicable' => true,

            'eligible' => $eligible,

            'reason' => $eligible
                    ? 'refused_cooldown_elapsed'
                    : 'refused_cooldown_active',

            'message' => $eligible
                    ? 'Lead recusado liberado para nova abordagem.'
                    : 'Reprospecção disponível em '
                        .$this->daysRemaining(
                            $now,
                            $nextAllowedAt
                        )
                        .' dia(s).',

            'next_allowed_at' => $nextAllowedAt
                ->toIso8601String(),

            'days_remaining' => $this->daysRemaining(
                $now,
                $nextAllowedAt
            ),
        ];
    }

    private function daysRemaining(
        CarbonImmutable $now,
        CarbonImmutable $target
    ): int {
        if (
            $now->greaterThanOrEqualTo(
                $target
            )
        ) {
            return 0;
        }

        $seconds =
            $target->getTimestamp()
            - $now->getTimestamp();

        return max(
            1,
            (int) ceil(
                $seconds / 86400
            )
        );
    }

    private function dateValue(
        mixed $value
    ): ?CarbonImmutable {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance(
                $value
            );
        }

        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value
            );
        } catch (Throwable) {
            return null;
        }
    }
}
