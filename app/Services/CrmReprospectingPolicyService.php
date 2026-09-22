<?php

namespace App\Services;

use App\Models\CompanyCrmCheck;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class CrmReprospectingPolicyService
{
    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     message: string,
     *     cooldown_days: int,
     *     last_activity_at: string|null,
     *     next_allowed_at: string|null
     * }
     */
    public function evaluate(
        CompanyCrmCheck $crm
    ): array {
        $cooldownDays =
            max(
                1,
                (int) config(
                    'prospector.crm.reprospecting_after_days',
                    90
                )
            );

        $lastActivityAt =
            $this->lastCommercialActivityAt(
                $crm
            );

        /*
         * Se não conseguimos determinar quando
         * aconteceu a última atividade comercial,
         * não liberamos automaticamente.
         */
        if ($lastActivityAt === null) {
            return [
                'eligible' => false,

                'reason' => 'activity_date_unknown',

                'message' => 'Não há data suficiente para '
                    .'liberar reprospecção automática.',

                'cooldown_days' => $cooldownDays,

                'last_activity_at' => null,

                'next_allowed_at' => null,
            ];
        }

        $nextAllowedAt =
            $lastActivityAt
                ->addDays(
                    $cooldownDays
                );

        $eligible =
            CarbonImmutable::now()
                ->greaterThanOrEqualTo(
                    $nextAllowedAt
                );

        return [
            'eligible' => $eligible,

            'reason' => $eligible
                    ? 'cooldown_elapsed'
                    : 'cooldown_active',

            'message' => $eligible
                    ? 'Empresa liberada para reprospecção.'
                    : 'Empresa prospectada recentemente. '
                        .'Aguarde o prazo de reprospecção.',

            'cooldown_days' => $cooldownDays,

            'last_activity_at' => $lastActivityAt
                ->toIso8601String(),

            'next_allowed_at' => $nextAllowedAt
                ->toIso8601String(),
        ];
    }

    private function lastCommercialActivityAt(
        CompanyCrmCheck $crm
    ): ?CarbonImmutable {
        $latest = null;

        /*
         * Último contato registrado no HubSpot.
         */
        $lastContactedAt =
            $this->parseDate(
                $crm->getAttribute(
                    'last_contacted_at'
                )
            );

        if ($lastContactedAt !== null) {
            $latest =
                $lastContactedAt;
        }

        /*
         * Também consideramos a data de
         * fechamento dos negócios encerrados.
         */
        $rawMetadata =
            $crm->getAttribute(
                'metadata'
            );

        $metadata =
            is_array($rawMetadata)
                ? $rawMetadata
                : [];

        $deals =
            $metadata['deals']
            ?? [];

        if (is_array($deals)) {
            foreach ($deals as $deal) {
                if (! is_array($deal)) {
                    continue;
                }

                $closedAt =
                    $this->parseDate(
                        $deal[
                            'closed_at'
                        ] ?? null
                    );

                if ($closedAt === null) {
                    continue;
                }

                if (
                    $latest === null
                    || $closedAt->greaterThan(
                        $latest
                    )
                ) {
                    $latest =
                        $closedAt;
                }
            }
        }

        return $latest;
    }

    private function parseDate(
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
