<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Calcula o instante de liberação de uma rodada, sem iniciar campanhas nem SMTP.
 *
 * Planos legados (send_time_local null): preservam +N vezes 24 horas.
 * Planos com horário: N dias de calendário no fuso America/Sao_Paulo,
 * com hora local fixa, ou data explícita para o PRIMEIRO follow-up.
 */
final class CampaignFollowupScheduleClock
{
    public function dueAt(
        EmailCampaign $previous,
        int $delayDays,
        ?string $localTime = null,
        ?string $firstSendDate = null,
    ): ?CarbonImmutable {
        if ($previous->status !== 'completed' || $previous->completed_at === null) {
            return null;
        }

        // O banco grava os horarios da campanha em UTC.
        // Ler o valor bruto evita reinterpretar o fuso pelo cast Eloquent.
        $completed = $this->asUtc(
            $previous->getRawOriginal('completed_at')
        );
        if ($completed === null) {
            return null;
        }

        $lastAcceptedValue = DB::table('email_campaign_recipients')
            ->where('email_campaign_id', $previous->id)
            ->where('status', 'sent')
            ->max('sent_at');
        $lastAccepted = $this->asUtc($lastAcceptedValue);

        // A referencia e a CONCLUSAO da rodada ou a ultima aceitacao SMTP,
        // o que for mais recente. Nao usar a data de criacao do rascunho.
        $reference = $lastAccepted !== null && $lastAccepted->greaterThan($completed)
            ? $lastAccepted : $completed;
        $days = max(1, min(90, $delayDays));

        if ($localTime === null || $localTime === '') {
            return $reference->addDays($days); // Sem alterar planos anteriores.
        }
        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $localTime) !== 1) {
            return null; // Horario invalido: nunca enviar por engano.
        }

        $tz = 'America/Sao_Paulo';
        $localDate = $reference->setTimezone($tz)->startOfDay()->addDays($days);
        $date = $firstSendDate ?: $localDate->toDateString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return null;
        }

        try {
            $at = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$localTime, $tz);
            if ($at->format('Y-m-d H:i') !== $date.' '.$localTime) {
                return null;
            }
            $at = $at->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }

        // Uma data manual nunca pode iniciar a rodada antes da conclusao real.
        return $at->lessThan($reference) ? $reference : $at;
    }

    private function asUtc(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
