<?php

namespace App\Services\Contacts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Limite operacional por dia e fila unificada para dois agentes já existentes.
 * NÃO chama WhatsApp nem envia mensagens: gera pedidos no PostgreSQL.
 */
final class WhatsappWebDailyCoordinator
{
    public function settings(): object
    {
        return DB::table('whatsapp_web_scan_settings')->where('id', 1)->firstOrFail();
    }

    /** @return array{checked:int,reserved:int,remaining:int,limit:int} */
    public function budget(?object $settings = null): array
    {
        $settings ??= $this->settings();
        $now = CarbonImmutable::now('America/Sao_Paulo');
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $checked = (int) DB::table('whatsapp_web_probe_events')
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)->count();
        // Reserva também falhas/lotes parciais: nunca exceder o limite por reenvio.
        $batches = DB::table('whatsapp_web_scan_batches')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw('COALESCE(SUM(requested_limit - processed), 0) AS unprocessed')->first();
        $reserved = max(0, (int) ($batches->unprocessed ?? 0));
        $limit = (int) $settings->daily_limit;

        return [
            'checked' => $checked,
            'reserved' => $reserved,
            'remaining' => max(0, $limit - $checked - $reserved),
            'limit' => $limit,
        ];
    }

    public function save(User $actor, int $dailyLimit, string $time, bool $enabled): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! in_array($dailyLimit, [10, 20, 30], true)
            || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            throw ValidationException::withMessages(['wa_plan' => 'Escolha 10, 20 ou 30 consultas e um horário válido.']);
        }
        DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
            'daily_limit' => $dailyLimit,
            'start_time' => $time,
            'auto_enabled' => $enabled,
            'configured_by' => $actor->id,
            'updated_at' => now('UTC'),
        ]);
    }

    /** Solicita um lote em cada conta online, respeitando orçamento e filas ativas. */
    public function startBoth(User $actor, int $perAccount = 5): array
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! in_array($perAccount, [3, 5, 10], true)) {
            throw ValidationException::withMessages(['wa_scan' => 'Selecione 3, 5 ou 10 números por conta.']);
        }

        return DB::transaction(function () use ($actor, $perAccount): array {
            $settings = DB::table('whatsapp_web_scan_settings')->where('id', 1)->lockForUpdate()->first();
            $budget = $this->budget($settings);
            if ($budget['remaining'] === 0) {
                throw ValidationException::withMessages(['wa_scan' => 'Limite diário atingido. Ajuste o limite ou retome amanhã.']);
            }
            $results = [];
            $panel = app(WhatsappWebControlPanel::class);
            $agents = $panel->agents();
            foreach ([1, 2] as $session) {
                $agent = $agents->firstWhere('session', $session);
                if ($agent === null || ! $agent->online
                    || ($agent->batch !== null && in_array($agent->batch->status, ['queued', 'running'], true))) {
                    continue;
                }
                $remaining = $this->budget($settings)['remaining'];
                if ($remaining <= 0) {
                    break;
                }
                try {
                    $results[$session] = $panel->request($actor, $session, min($perAccount, $remaining));
                } catch (ValidationException) {
                    // Uma partição sem candidatos não bloqueia a outra conta.
                }
            }
            if ($results === []) {
                throw ValidationException::withMessages(['wa_scan' => 'Nenhuma conta disponível com números elegíveis. Confira se os dois agentes estão conectados.']);
            }
            return $results;
        }, 2);
    }

    /**
     * Agendamento opt-in. Em até uma hora após o horário escolhido, cria
     * lotes conforme as contas liberam suas filas e respeita o teto diário.
     * Sem conta online não há consulta nem processo automático forçado.
     *
     * @return array{requested:int,reason:string}
     */
    public function planDaily(): array
    {
        $settings = $this->settings();
        if (! $settings->auto_enabled) {
            return ['requested' => 0, 'reason' => 'desativado'];
        }
        $now = CarbonImmutable::now('America/Sao_Paulo');
        $start = CarbonImmutable::parse($now->format('Y-m-d').' '.$settings->start_time, 'America/Sao_Paulo');
        if ($now->lessThan($start) || $now->greaterThanOrEqualTo($start->addHour())) {
            return ['requested' => 0, 'reason' => 'fora da janela'];
        }
        $budget = $this->budget($settings);
        if ($budget['remaining'] <= 0) {
            return ['requested' => 0, 'reason' => 'limite diário'];
        }
        $actor = User::query()->whereKey((int) $settings->configured_by)->first();
        if ($actor === null || ! $actor->isCommercialManager()) {
            return ['requested' => 0, 'reason' => 'gestor não autorizado'];
        }
        try {
            $perAccount = $budget['remaining'] >= 20 ? 10 : ($budget['remaining'] >= 8 ? 5 : 3);
            $results = $this->startBoth($actor, $perAccount);
            DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
                'last_scheduled_on' => $now->toDateString(), 'updated_at' => now('UTC'),
            ]);
            return ['requested' => count($results), 'reason' => 'lotes solicitados'];
        } catch (ValidationException) {
            return ['requested' => 0, 'reason' => 'contas ocupadas ou offline'];
        }
    }
}
