<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

final class HubSpotDateTime
{
    public static function parse(
        mixed $value
    ): ?CarbonImmutable {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        try {
            $date =
                self::date(
                    $value
                );

            if ($date === null) {
                return null;
            }

            /*
             * O HubSpot representa timestamps
             * como instantes UTC.
             *
             * A aplicação opera em horário
             * brasileiro.
             *
             * Exemplo:
             *
             * HubSpot/API:
             * 2026-10-06T11:00:00Z
             *
             * Brasil:
             * 06/10/2026 08:00
             */
            return $date->setTimezone(
                self::timezone()
            );

        } catch (Throwable) {
            return null;
        }
    }

    private static function date(
        mixed $value
    ): ?CarbonImmutable {
        if (
            $value instanceof CarbonInterface
        ) {
            return CarbonImmutable::instance(
                $value
            );
        }

        if (is_numeric($value)) {
            $number =
                (int) $value;

            if (
                $number
                > 100000000000
            ) {
                return CarbonImmutable::createFromTimestampMs(
                    $number,
                    'UTC'
                );
            }

            return CarbonImmutable::createFromTimestamp(
                $number,
                'UTC'
            );
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value =
            trim(
                (string) $value
            );

        if ($value === '') {
            return null;
        }

        /*
         * Quando o valor já possui Z/offset,
         * Carbon preserva o instante correto.
         *
         * Caso o HubSpot eventualmente envie
         * uma string sem timezone, tratamos
         * como UTC.
         */
        return CarbonImmutable::parse(
            $value,
            'UTC'
        );
    }

    private static function timezone(): string
    {
        $timezone =
            trim(
                (string) config(
                    'app.timezone',
                    'America/Sao_Paulo'
                )
            );

        return $timezone !== ''
            ? $timezone
            : 'America/Sao_Paulo';
    }
}
