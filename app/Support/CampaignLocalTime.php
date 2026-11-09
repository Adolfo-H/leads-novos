<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Formata instantes salvos no banco para a visualização comercial em Brasília.
 * Não muda as colunas TIMESTAMPTZ, nem o relógio de disparos/agendamentos.
 *
 * Prefira receber getRawOriginal() do Eloquent: evita interpretar como horário
 * local uma data UTC antes de aplicar a conversão visual.
 */
final class CampaignLocalTime
{
    private const ZONE = 'America/Sao_Paulo';

    public static function format(mixed $rawTimestamp, string $fallback = '—'): string
    {
        if ($rawTimestamp instanceof DateTimeInterface) {
            $instant = CarbonImmutable::instance($rawTimestamp);
        } elseif (is_string($rawTimestamp) && trim($rawTimestamp) !== '') {
            try {
                // Se o PostgreSQL devolver um offset (+00/-03), ele é respeitado.
                // Em respostas sem offset, usamos UTC, padrão de gravação do envio.
                $instant = CarbonImmutable::parse($rawTimestamp, 'UTC');
            } catch (Throwable) {
                return $fallback;
            }
        } else {
            return $fallback;
        }

        return $instant->setTimezone(self::ZONE)->format('d/m/Y H:i');
    }
}
