<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Ponte local para pareamento. Nenhum endpoint HTTP recebe o QR;
 * somente a sessão autenticada do gestor pode visualizá-lo na Central.
 * O segredo do QR transita pelo stdin, nunca por parâmetros do processo ou logs.
 */
final class WhatsappWebPairingBridge extends Command
{
    protected $signature = 'contacts:wa-web:pairing
        {action : poll|ack|publish|clear|error|heartbeat}
        {--session= : Conta 1 ou 2}
        {--token= : Token da solicitação a reconhecer}';

    protected $description = 'Ponte de pareamento local Ubuntu/Livewire, sem enviar mensagens.';

    private const BASE = 'prospector:wa:pair:v13_1:';

    public function handle(): int
    {
        $session = (int) $this->option('session');
        $action = (string) $this->argument('action');
        if (! in_array($session, [1, 2], true)
            || ! in_array($action, ['poll', 'ack', 'publish', 'clear', 'error', 'heartbeat'], true)) {
            $this->error('Ação ou conta inválida.');

            return self::FAILURE;
        }

        $requestKey = self::BASE.'request:'.$session;
        $qrKey = self::BASE.'qr:'.$session;
        $stateKey = self::BASE.'state:'.$session;
        $current = Cache::get($requestKey);

        if ($action === 'heartbeat') {
            // O front-end não inicia processos Node; ele mostra se o daemon Ubuntu está vivo.
            Cache::put(self::BASE.'connector:heartbeat', now('UTC')->toIso8601String(), now()->addSeconds(55));
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }

        if ($action === 'poll') {
            $this->line(json_encode([
                'token' => is_array($current) ? ($current['token'] ?? '') : '',
                'reset' => is_array($current) && ($current['reset'] ?? false) === true,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($action === 'ack') {
            $token = (string) $this->option('token');
            if (! is_array($current) || $token === '' || ! hash_equals((string) ($current['token'] ?? ''), $token)) {
                $this->error('Solicitação expirada ou diferente.');

                return self::FAILURE;
            }
            Cache::forget($requestKey);
            Cache::put($stateKey, ['state' => 'starting', 'at' => now()->toISOString()], now()->addMinutes(5));
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }

        if ($action === 'publish') {
            // Apenas data URI gerada localmente por qrcode.toDataURL(qr).
            // Nunca persistir o QR no PostgreSQL ou em logs.
            $raw = stream_get_contents(STDIN, 135000);
            $raw = trim(is_string($raw) ? $raw : '');
            if (strlen($raw) > 130000
                || ! preg_match('~^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$~D', $raw, $m)
                || base64_decode($m[1], true) === false) {
                $this->error('Imagem de QR inválida.');

                return self::FAILURE;
            }
            Cache::put($qrKey, ['image' => $raw, 'at' => now()->toISOString()], now()->addSeconds(85));
            Cache::put($stateKey, ['state' => 'qr_ready', 'at' => now()->toISOString()], now()->addMinutes(5));
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }

        if ($action === 'clear') {
            Cache::forget($qrKey);
            Cache::forget($stateKey);
            $this->line('{"ok":true}');

            return self::SUCCESS;
        }

        // Todas as outras ações válidas já retornaram; resta somente "error".
        // Erros não contêm tokens de pareamento, telefones ou credenciais.
        $message = stream_get_contents(STDIN, 500);
        $message = trim((string) $message);
        Cache::forget($qrKey);
        Cache::put($stateKey, [
            'state' => 'error',
            'message' => mb_substr($message ?: 'Não foi possível iniciar a conexão.', 0, 180),
            'at' => now()->toISOString(),
        ], now()->addMinutes(5));
        $this->line('{"ok":true}');

        return self::SUCCESS;
    }
}
