<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Canal local Ubuntu -> PostgreSQL. Sem endpoints web, senhas ou execução de shell. */
final class WhatsappWebScanAgent extends Command
{
    protected $signature = 'contacts:wa-web:agent
        {action : pulse|recover|claim|current|continue|progress|finish}
        {--session= : Conta 1 ou 2}
        {--state= : connected|connecting|disconnected|error}
        {--account= : Número do dispositivo conectado}
        {--phone= : Telefone em consulta (0 para limpar)}
        {--batch= : ID do lote}
        {--result= : found|not_found|inconclusive|error}
        {--error= : Resumo do erro, sem dados pessoais}';

    protected $description = 'Controle local de lotes WhatsApp Web sem envios e sem acesso HTTP público.';

    public function handle(): int
    {
        $session = (string) $this->option('session');
        $action = (string) $this->argument('action');
        if (! in_array($session, ['1', '2'], true)
            || ! in_array($action, ['pulse', 'recover', 'claim', 'current', 'continue', 'progress', 'finish'], true)) {
            $this->error('Parâmetros inválidos.');

            return self::FAILURE;
        }
        $session = (int) $session;
        $batchId = (string) ($this->option('batch') ?? '');
        if (in_array($action, ['continue', 'progress', 'finish'], true)
            && (! ctype_digit($batchId) || (int) $batchId < 1)) {
            $this->error('--batch é obrigatório para a operação.');

            return self::FAILURE;
        }
        $batchId = (int) $batchId;
        $now = now('UTC');
        if ($action === 'pulse') {
            $state = (string) $this->option('state');
            if (! in_array($state, ['connected', 'connecting', 'disconnected', 'error'], true)) {
                return self::FAILURE;
            }
            $account = (string) ($this->option('account') ?? '');
            if ($account !== '' && ! preg_match('/^[0-9]{10,15}$/D', $account)) {
                return self::FAILURE;
            }
            DB::table('whatsapp_web_scan_agents')->where('session', $session)->update([
                'account_number' => $account !== '' ? $account : DB::raw('account_number'),
                'connection_state' => $state, 'last_heartbeat_at' => $now,
                'last_connected_at' => $state === 'connected'
                    ? DB::raw("CASE WHEN connection_state = 'connected' AND last_connected_at IS NOT NULL THEN last_connected_at ELSE CURRENT_TIMESTAMP END")
                    : DB::raw('last_connected_at'),
                'last_error' => $state === 'error'
                    ? mb_substr((string) ($this->option('error') ?: 'Falha na conexão'), 0, 200)
                    : null,
                'updated_at' => $now,
            ]);
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }
        if ($action === 'recover') {
            DB::transaction(static function () use ($session, $now): void {
                $agent = DB::table('whatsapp_web_scan_agents')->where('session', $session)->lockForUpdate()->first();
                // O agente Node já obteve um lock de processo exclusivo neste host.
                // Se havia um lote 'running', o processo anterior não está mais vivo,
                // mesmo que seu último heartbeat ainda seja recente.
                if ($agent !== null) {
                    DB::table('whatsapp_web_scan_batches')->where('session', $session)
                        ->where('status', 'running')->update([
                            'status' => 'failed', 'finished_at' => $now, 'updated_at' => $now,
                            'error_message' => 'Agente anterior interrompido; consultas já registradas foram preservadas.',
                        ]);
                    DB::table('whatsapp_web_scan_agents')->where('session', $session)->update([
                        'current_phone_id' => null, 'updated_at' => $now,
                    ]);
                }
            });
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }
        if ($action === 'claim') {
            $batch = DB::transaction(static function () use ($session, $now): ?object {
                $agent = DB::table('whatsapp_web_scan_agents')->where('session', $session)->lockForUpdate()->first();
                if ($agent === null || $agent->connection_state !== 'connected'
                    || $agent->last_heartbeat_at === null
                    || Carbon::parse($agent->last_heartbeat_at)->lt(now('UTC')->subSeconds(100))) {
                    return null;
                }
                if (DB::table('whatsapp_web_scan_batches')->where('session', $session)
                    ->where('status', 'running')->exists()) {
                    return null;
                }
                $batch = DB::table('whatsapp_web_scan_batches')->where('session', $session)
                    ->where('status', 'queued')->orderBy('id')->lockForUpdate()->first();
                if ($batch === null) {
                    return null;
                }
                DB::table('whatsapp_web_scan_batches')->where('id', $batch->id)->update([
                    'status' => 'running', 'started_at' => $now, 'updated_at' => $now,
                ]);

                return $batch;
            });
            $this->line(json_encode(['id' => $batch?->id, 'limit' => $batch?->requested_limit, 'scope' => $batch->scan_scope ?? 'mobile', 'target_ids' => $batch?->target_phone_ids /* EC_WA_V16_AGENT */], JSON_THROW_ON_ERROR)); // EC_WA_FULL_DIRECTORY_V8_AGENT

            return self::SUCCESS;
        }
        if ($action === 'current') {
            $rawPhone = (string) ($this->option('phone') ?? '');
            if (! ctype_digit($rawPhone)) {
                return self::FAILURE;
            }
            $phone = (int) $rawPhone;
            if ($phone !== 0) {
                $exists = DB::table('whatsapp_phone_numbers')->where('id', $phone)
                    ->whereRaw('(id % 2) = ?', [$session === 1 ? 0 : 1])
                    ->exists();
                if (! $exists) {
                    return self::FAILURE;
                }
            }
            DB::table('whatsapp_web_scan_agents')->where('session', $session)->update([
                'current_phone_id' => $phone ?: null, 'updated_at' => $now,
            ]);
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }
        if ($action === 'continue') {
            $row = DB::table('whatsapp_web_scan_batches')->where('id', $batchId)
                ->where('session', $session)->first(['status', 'cancel_requested_at']);
            $this->line(json_encode(['continue' => $row !== null && $row->status === 'running'
                && $row->cancel_requested_at === null], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if ($action === 'progress') {
            $result = (string) $this->option('result');
            if (! in_array($result, ['found', 'not_found', 'inconclusive', 'error'], true)) {
                return self::FAILURE;
            }
            DB::transaction(static function () use ($session, $batchId, $result, $now): void {
                $batch = DB::table('whatsapp_web_scan_batches')->where('id', $batchId)
                    ->where('session', $session)->lockForUpdate()->first();
                if ($batch === null || $batch->status !== 'running'
                    || $batch->processed >= $batch->requested_limit) {
                    throw new \RuntimeException('Lote indisponível para progresso.');
                }
                $field = $result === 'error' ? 'errors' : $result;
                DB::table('whatsapp_web_scan_batches')->where('id', $batchId)->update([
                    'processed' => $batch->processed + 1, $field => $batch->{$field} + 1,
                    'updated_at' => $now,
                ]);
            });
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }
        // Todas as outras ações válidas já retornaram; resta somente "finish".
        DB::transaction(function () use ($session, $batchId, $now): void {
            $batch = DB::table('whatsapp_web_scan_batches')->where('id', $batchId)
                ->where('session', $session)->lockForUpdate()->first();
            if ($batch === null || $batch->status !== 'running') {
                return;
            }
            $error = mb_substr(trim((string) ($this->option('error') ?? '')), 0, 240);
            $status = $batch->cancel_requested_at !== null ? 'cancelled' : ($error !== '' ? 'failed' : 'completed');
            DB::table('whatsapp_web_scan_batches')->where('id', $batchId)->update([
                'status' => $status, 'finished_at' => $now,
                'error_message' => $error !== '' ? $error : null, 'updated_at' => $now,
            ]);
        });
        $this->line('{"ok":true}');

        return self::SUCCESS;
    }
}
