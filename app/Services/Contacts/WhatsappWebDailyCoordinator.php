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
    public function settings(): \stdClass
    {
        return DB::table('whatsapp_web_scan_settings')->where('id', 1)->firstOrFail();
    }

    // EC_WA_200_DUAS_CONTAS_V9: orçamento por conta. Nenhuma conta herda saldo da outra.
    /** @return array{checked:int,reserved:int,remaining:int,limit:int,per_account_limit:int,sessions:array<int,array{checked:int,reserved:int,remaining:int}>} */
    public function budget(?\stdClass $settings = null): array
    {
        $settings ??= $this->settings();
        $now = CarbonImmutable::now('America/Sao_Paulo');
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $allChecks = DB::table('whatsapp_web_probe_events')
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end);
        $checked = (int) (clone $allChecks)->count();
        $bySession = (clone $allChecks)->whereIn('session', [1, 2])
            ->select('session')->selectRaw('COUNT(*) AS quantity')
            ->groupBy('session')->pluck('quantity', 'session');

        // Apenas lotes ativos reservam saldo. Finalizados, falhos e cancelados
        // já têm suas consultas efetivas contabilizadas no histórico.
        $byPending = DB::table('whatsapp_web_scan_batches')
            ->where('created_at', '<', $end)
            ->whereIn('status', ['queued', 'running'])
            ->whereIn('session', [1, 2])
            ->select('session')
            ->selectRaw('SUM(CASE WHEN requested_limit > processed THEN requested_limit - processed ELSE 0 END) AS quantity')
            ->groupBy('session')->pluck('quantity', 'session');

        $limit = (int) $settings->daily_limit;
        $perAccount = intdiv($limit, 2);
        $sessions = [];
        foreach ([1, 2] as $session) {
            $done = (int) ($bySession[$session] ?? 0);
            $reservedSlot = (int) ($byPending[$session] ?? 0);
            $sessions[$session] = [
                'checked' => $done,
                'reserved' => $reservedSlot,
                'remaining' => max(0, $perAccount - $done - $reservedSlot),
            ];
        }
        $reserved = $sessions[1]['reserved'] + $sessions[2]['reserved'];

        return [
            'checked' => $checked,
            'reserved' => $reserved,
            'remaining' => min(
                max(0, $limit - $checked - $reserved),
                $sessions[1]['remaining'] + $sessions[2]['remaining'],
            ),
            'limit' => $limit,
            'per_account_limit' => $perAccount,
            'sessions' => $sessions,
        ];
    }

    public function save(
        User $actor, int $dailyLimit, string $time, bool $enabled,
        string $scope = 'all', string $endTime = '18:00',
    ): void {
        abort_unless($actor->isCommercialManager(), 403);
        $validTime = static fn (string $value): bool => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) === 1;
        if (! in_array($dailyLimit, [10, 20, 30, 50, 100, 200], true)
            || ! in_array($scope, ['mobile', 'landline', 'all'], true)
            || ! $validTime($time) || ! $validTime($endTime)
            || strcmp($time, $endTime) >= 0) {
            throw ValidationException::withMessages([
                'wa_plan' => 'Escolha um limite válido (até 200 no total), tipos e horário de início anterior ao fim.',
            ]);
        }
        DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
            'daily_limit' => $dailyLimit,
            'scan_scope' => $scope,
            'start_time' => $time,
            'end_time' => $endTime,
            'auto_enabled' => $enabled,
            'configured_by' => $actor->id,
            'updated_at' => now('UTC'),
        ]);
    }

    /**
     * Solicita um lote em cada conta online, respeitando orçamento e filas ativas.
     *
     * @return array<int, int> IDs dos lotes, indexados pela sessão.
     */
    public function startBoth(User $actor, int $perAccount = 5, string $scope = 'all'): array
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! in_array($perAccount, [3, 5, 10], true)) {
            throw ValidationException::withMessages(['wa_scan' => 'Selecione 3, 5 ou 10 números por conta.']);
        }

        return DB::transaction(function () use ($actor, $perAccount, $scope): array {
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
                    $results[$session] = $panel->request($actor, $session, min($perAccount, $remaining), $scope);
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
        $end = CarbonImmutable::parse($now->format('Y-m-d').' '.$settings->end_time, 'America/Sao_Paulo');
        if ($now->lessThan($start) || $now->greaterThanOrEqualTo($end) || $end->lessThanOrEqualTo($start)) {
            return ['requested' => 0, 'reason' => 'fora do horário programado'];
        }
        $actor = User::query()->whereKey((int) $settings->configured_by)->first();
        if ($actor === null || ! $actor->isCommercialManager()) {
            return ['requested' => 0, 'reason' => 'gestor não autorizado'];
        }
        $budget = $this->budget($settings);
        if ($budget['remaining'] <= 0) {
            return ['requested' => 0, 'reason' => 'limite diário'];
        }
        // Distribui o orçamento ao longo da janela inteira, sem concentrar
        // 200 consultas na primeira hora. Scheduler roda a cada 5 minutos.
        $duration = max(1, $end->getTimestamp() - $start->getTimestamp());
        $elapsed = max(0, min($duration, $now->getTimestamp() - $start->getTimestamp()));
        $target = min($budget['per_account_limit'], (int) floor(
            ($elapsed + 300) * $budget['per_account_limit'] / $duration
        ));

        $requested = DB::transaction(function () use ($actor, $target): int {
            $locked = DB::table('whatsapp_web_scan_settings')->where('id', 1)->lockForUpdate()->first();
            if ($locked === null || ! $locked->auto_enabled) {
                return 0;
            }
            $budget = $this->budget($locked);
            $panel = app(WhatsappWebControlPanel::class);
            $agents = $panel->agents();
            $count = 0;
            foreach ([1, 2] as $session) {
                $state = $budget['sessions'][$session];
                $already = $state['checked'] + $state['reserved'];
                // No máximo 2 por agente a cada 5 minutos, inclusive se o
                // aparelho ficou desconectado e voltou mais tarde.
                $due = min(2, $target - $already, $state['remaining'], $budget['remaining']);
                if ($due <= 0) {
                    continue;
                }
                $agent = $agents->firstWhere('session', $session);
                if ($agent === null || ! $agent->online
                    || ($agent->batch !== null && in_array($agent->batch->status, ['queued', 'running'], true))) {
                    continue;
                }
                try {
                    $panel->request($actor, $session, $due, (string) $locked->scan_scope);
                    $count++;
                    $budget = $this->budget($locked);
                } catch (ValidationException) {
                    // Sem números elegíveis ou conta ocupada; não insiste na
                    // mesma execução e não cria lotes artificiais.
                }
            }
            if ($count > 0) {
                DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
                    'last_scheduled_on' => CarbonImmutable::now('America/Sao_Paulo')->toDateString(),
                    'updated_at' => now('UTC'),
                ]);
            }

            return $count;
        }, 2);

        return ['requested' => $requested, 'reason' => $requested > 0 ? 'lotes solicitados' : 'sem contas livres ou sem quota no momento'];
    }
}
