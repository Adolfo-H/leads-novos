<?php

namespace App\Services\Campaigns;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Política global persistida para campanhas. Não controla CAMPAIGN_MAIL_LIVE_ENABLED.
 * Apenas gestores podem configurá-la; valores altos exigem confirmação do limite SMTP.
 */
final class CampaignSendPolicy
{
    /** @return array{target_volume:int,duration_hours:int,window_start:string,hourly_limit:int,per_tick_limit:int,active:bool,updated_at:string|null} */
    public function snapshot(): array
    {
        $defaults = [
            'target_volume' => min(500, max(1, (int) config('campaign_mail.per_day', 500))),
            'duration_hours' => 24,
            'window_start' => '00:00',
            'hourly_limit' => min(100, max(1, (int) config('campaign_mail.per_hour', 25))),
            'per_tick_limit' => min(5, max(1, (int) config('campaign_mail.per_tick', 1))),
            'active' => false,
            'updated_at' => null,
        ];

        if (! Schema::hasTable('email_campaign_send_policies')) {
            return $defaults;
        }
        $row = DB::table('email_campaign_send_policies')->where('id', 1)->first();
        if ($row === null) {
            return $defaults;
        }

        return [
            'target_volume' => (int) data_get($row, 'target_volume'),
            'duration_hours' => (int) data_get($row, 'duration_hours'),
            'window_start' => (string) data_get($row, 'window_start'),
            'hourly_limit' => (int) data_get($row, 'hourly_limit'),
            'per_tick_limit' => (int) data_get($row, 'per_tick_limit'),
            'active' => (bool) data_get($row, 'active'),
            'updated_at' => (string) data_get($row, 'updated_at'),
        ];
    }

    /** @return array{hour:int,day:int,tick:int,window_active:bool,window_start:string,duration_hours:int,source:string} */
    public function effective(): array
    {
        $plan = $this->snapshot();

        return [
            'hour' => $plan['active'] ? $plan['hourly_limit'] : min(100, max(1, (int) config('campaign_mail.per_hour', 25))),
            'day' => $plan['active']
                ? min(500, max(1, (int) config('campaign_mail.per_day', 500)), $plan['target_volume'])
                : min(500, max(1, (int) config('campaign_mail.per_day', 500))),
            'tick' => $plan['active'] ? $plan['per_tick_limit'] : min(5, max(1, (int) config('campaign_mail.per_tick', 1))),
            'window_active' => $plan['active'],
            'window_start' => $plan['window_start'],
            'duration_hours' => $plan['duration_hours'],
            'source' => $plan['active'] ? 'prospector' : 'ambiente',
        ];
    }

    /** @return array{hourly_limit:int,per_tick_limit:int,possible:bool,reason:string} */
    public function quote(int $volume, int $hours): array
    {
        $perHour = (int) ceil($volume / max(1, $hours));
        $perTick = (int) ceil($volume / max(1, $hours * 60));
        $possible = $volume >= 1 && $volume <= 500 && $hours >= 1 && $hours <= 24
            && $perHour <= 100 && $perTick <= 5;

        return [
            'hourly_limit' => $perHour,
            'per_tick_limit' => $perTick,
            'possible' => $possible,
            'reason' => $possible
                ? 'Capacidade teórica, sujeita a cotas SMTP, filas e janela móvel de 24h.'
                : 'Volume ou duração incompatíveis com os limites máximos do motor.',
        ];
    }

    /** Salva um plano; só o torna efetivo mediante confirmação expressa da capacidade SMTP. */
    public function save(User $user, int $volume, int $hours, string $start, bool $activate, bool $capacityConfirmed): void
    {
        abort_unless($user->isCommercialManager(), 403);
        if (config('campaign_mail.live_enabled')) {
            throw ValidationException::withMessages(['policy' => 'Pause os disparos comerciais antes de alterar a política.']);
        }
        $startValid = preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $start) === 1;
        $quote = $this->quote($volume, $hours);
        if (! $startValid || ! $quote['possible']) {
            throw ValidationException::withMessages(['policy' => 'Informe de 1 a 500 destinatários, duração de 1 a 24 horas e início HH:MM.']);
        }
        if ($activate && ! $capacityConfirmed) {
            throw ValidationException::withMessages(['capacityConfirmed' => 'Confirme primeiro a autorização da capacidade pelo administrador do SMTP.']);
        }
        if ($activate && $volume > (int) config('campaign_mail.per_day', 500)) {
            throw ValidationException::withMessages(['policy' => 'O plano ultrapassa o teto técnico global de 24 horas.']);
        }

        $now = now('UTC');
        DB::transaction(static function () use ($user, $volume, $hours, $start, $quote, $activate, $now): void {
            // Serializa mudanças de política com as reservas globais da V4.
            DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            DB::table('email_campaign_send_policies')->updateOrInsert(
                ['id' => 1],
                [
                    'target_volume' => $volume,
                    'duration_hours' => $hours,
                    'window_start' => $start,
                    'hourly_limit' => $quote['hourly_limit'],
                    'per_tick_limit' => $quote['per_tick_limit'],
                    'active' => $activate,
                    'updated_by' => $user->id,
                    'capacity_confirmed_at' => $activate ? $now : null,
                    'updated_at' => $now,
                ],
            );
        });
    }

    /** @param array{window_active:bool,window_start:string,duration_hours:int} $policy */
    public function windowOpen(array $policy, ?CarbonImmutable $instant = null): bool
    {
        if (! $policy['window_active']) {
            return true;
        }
        $local = ($instant ?? CarbonImmutable::now('UTC'))->setTimezone((string) config('app.timezone', 'America/Sao_Paulo'));
        $parts = explode(':', $policy['window_start']);
        $start = ((int) $parts[0]) * 60 + (int) $parts[1];
        $current = ((int) $local->format('H')) * 60 + (int) $local->format('i');
        $elapsed = ($current - $start + 1440) % 1440;

        return $elapsed < $policy['duration_hours'] * 60;
    }
}
