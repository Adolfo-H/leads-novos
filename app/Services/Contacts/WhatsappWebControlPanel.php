<?php

namespace App\Services\Contacts;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Painel operacional de consultas NÃO oficiais, com filas de tamanho explícito.
 * Não cria conexão WhatsApp, não envia mensagens e não converte indícios em confirmações humanas.
 */
final class WhatsappWebControlPanel
{
    /** @return array<string,int> */
    public function metrics(): array
    {
        $row = DB::table('whatsapp_phone_numbers as n')
            ->leftJoin('whatsapp_web_probe_results as p', 'p.phone_id', '=', 'n.id')
            ->where('n.contacts_count', '>', 0)
            ->selectRaw('COUNT(*) AS total') // EC_WA_FULL_DIRECTORY_V8_PANEL
            ->selectRaw("COALESCE(SUM(CASE WHEN n.status = 'yes' THEN 1 ELSE 0 END), 0) AS confirmed")
            ->selectRaw("COALESCE(SUM(CASE WHEN n.status = 'no' THEN 1 ELSE 0 END), 0) AS denied")
            ->selectRaw("COALESCE(SUM(CASE WHEN n.status = 'unknown' THEN 1 ELSE 0 END), 0) AS pending")
            ->selectRaw('COALESCE(SUM(CASE WHEN p.phone_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS consulted')
            ->selectRaw('COALESCE(SUM(CASE WHEN p.result = \'found\' THEN 1 ELSE 0 END), 0) AS found')
            ->selectRaw('COALESCE(SUM(CASE WHEN p.result = \'not_found\' THEN 1 ELSE 0 END), 0) AS not_found')
            ->selectRaw('COALESCE(SUM(CASE WHEN p.result IN (\'inconclusive\', \'error\') THEN 1 ELSE 0 END), 0) AS uncertain')
            ->selectRaw('COALESCE(SUM(CASE WHEN p.phone_id IS NULL THEN 1 ELSE 0 END), 0) AS never_consulted')
            ->selectRaw('COALESCE(SUM(CASE WHEN n.status = \'unknown\' AND p.phone_id IS NULL THEN 1 ELSE 0 END), 0) AS eligible_never')
            ->selectRaw('COALESCE(SUM(CASE WHEN n.status = \'yes\' THEN 1 ELSE 0 END), 0) AS human_confirmed')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'confirmed' => (int) ($row->confirmed ?? 0),
            'denied' => (int) ($row->denied ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'consulted' => (int) ($row->consulted ?? 0),
            'found' => (int) ($row->found ?? 0),
            'not_found' => (int) ($row->not_found ?? 0),
            'uncertain' => (int) ($row->uncertain ?? 0),
            'never_consulted' => (int) ($row->never_consulted ?? 0),
            'eligible_never' => (int) ($row->eligible_never ?? 0),
            'human_confirmed' => (int) ($row->human_confirmed ?? 0),
        ];
    }

    /** @return Collection<int,\stdClass> */
    public function latest(int $limit = 12): Collection
    {
        return DB::table('whatsapp_web_probe_results as p')
            ->join('whatsapp_phone_numbers as n', 'n.id', '=', 'p.phone_id')
            ->where('n.contacts_count', '>', 0)
            ->select('n.id', 'n.e164', 'n.digits', 'n.kind', 'n.status', 'n.contacts_count',
                'p.result', 'p.session', 'p.checked_at', 'p.attempts')
            ->orderByDesc('p.checked_at')->orderByDesc('p.id')
            ->limit(max(1, min($limit, 30)))
            ->get();
    }

    /** @return Collection<int,\stdClass> */
    public function agents(): Collection
    {
        $agents = DB::table('whatsapp_web_scan_agents as a')
            ->leftJoin('whatsapp_phone_numbers as n', 'n.id', '=', 'a.current_phone_id')
            ->select('a.*', 'n.e164 as current_e164', 'n.digits as current_digits')
            ->orderBy('a.session')->get();
        $recent = DB::table('whatsapp_web_scan_batches')
            ->whereIn('session', [1, 2])->orderByDesc('id')->limit(20)->get()->groupBy('session');
        $now = now('UTC');

        return $agents->map(static function (\stdClass $agent) use ($recent, $now): \stdClass {
            $agent->online = $agent->connection_state === 'connected'
                && $agent->last_heartbeat_at !== null
                && Carbon::parse($agent->last_heartbeat_at)->gte($now->copy()->subSeconds(100));
            $agent->batch = $recent->get($agent->session)?->first();

            return $agent;
        });
    }

    public function request(User $actor, int $session, int $limit, string $scope = 'mobile'): int
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! in_array($session, [1, 2], true) || $limit < 1 || $limit > 10 || ! in_array($scope, ['mobile', 'landline', 'all'], true)) {
            throw ValidationException::withMessages(['wa_scan' => 'Escolha uma conta e um lote de 1 a 10 números.']);
        }

        // EC_WA_UNIFIED_V7_PANEL: serializa solicitações e respeita quota global de hoje.
        return DB::transaction(static function () use ($actor, $session, $limit, $scope): int {
            $settings = DB::table('whatsapp_web_scan_settings')->where('id', 1)->lockForUpdate()->first();
            // EC_WA_200_DUAS_CONTAS_V9: saldo global E saldo independente de cada conta.
            $budget = app(WhatsappWebDailyCoordinator::class)->budget($settings);
            if ($budget['remaining'] <= 0 || $budget['sessions'][$session]['remaining'] <= 0) {
                throw ValidationException::withMessages([
                    'wa_scan' => 'Limite diário atingido nesta conta (orçamento individual).',
                ]);
            }
            $limit = min($limit, $budget['remaining'], $budget['sessions'][$session]['remaining']);
            $agent = DB::table('whatsapp_web_scan_agents')->where('session', $session)->lockForUpdate()->first();
            if ($agent === null || $agent->connection_state !== 'connected'
                || $agent->last_heartbeat_at === null
                || Carbon::parse($agent->last_heartbeat_at)->lt(now('UTC')->subSeconds(100))) {
                throw ValidationException::withMessages(['wa_scan' => 'Conta '.$session.' não está conectada ao agente Ubuntu. Inicie o agente antes de solicitar o lote.']);
            }
            if (DB::table('whatsapp_web_scan_batches')->where('session', $session)
                ->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['wa_scan' => 'Já existe um lote pendente ou em execução nessa conta.']);
            }
            $count = DB::table('whatsapp_phone_numbers as n')
                ->leftJoin('whatsapp_web_probe_results as p', 'p.phone_id', '=', 'n.id')
                ->whereIn('n.kind', $scope === 'all' ? ['mobile', 'landline'] : [$scope])->where('n.status', 'unknown')
                ->where('n.contacts_count', '>', 0)
                ->whereRaw('(n.id % 2) = ?', [$session === 1 ? 0 : 1])
                ->where(static function ($query): void {
                    $query->whereNull('p.phone_id')->orWhere('p.checked_at', '<', now('UTC')->subDays(30));
                })->count();
            if ($count === 0) {
                throw ValidationException::withMessages(['wa_scan' => 'Não há números válidos elegíveis nesta partição (prazo de reconsulta: 30 dias).']);
            }
            $now = now('UTC');

            return (int) DB::table('whatsapp_web_scan_batches')->insertGetId([
                'session' => $session, 'requested_by' => $actor->id,
                'scan_scope' => $scope,
                'requested_limit' => min($limit, $count), 'status' => 'queued',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    public function cancel(User $actor, int $batchId): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        DB::transaction(static function () use ($batchId): void {
            $row = DB::table('whatsapp_web_scan_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($row->status === 'queued') {
                DB::table('whatsapp_web_scan_batches')->where('id', $batchId)->update([
                    'status' => 'cancelled', 'cancel_requested_at' => now('UTC'),
                    'finished_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            } elseif ($row->status === 'running') {
                DB::table('whatsapp_web_scan_batches')->where('id', $batchId)->update([
                    'cancel_requested_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
        });
    }
}
