<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fallback HTTP para solicitar o pareamento ao conector Ubuntu.
 * Nao conecta diretamente, nao consulta numeros e nao envia mensagens.
 * O QR continua restrito a pagina autenticada e nunca e recebido neste POST.
 */
final class WhatsappPairHttpController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        $data = $request->validate([
            'session' => ['required', 'integer', 'in:1,2'],
            'reset' => ['required', 'boolean'],
        ]);
        $session = (int) $data['session'];
        $reset = (string) $data['reset'] === '1';
        $base = 'prospector:wa:pair:v13_1:';
        // PRG: uma submissao POST sempre volta para a aba correta por HTTP.
        $returnTo = route('contacts.whatsapp', ['aba' => 'operation']).'#wa12-operation';

        $beat = Cache::get($base.'connector:heartbeat');
        $connectorUp = is_string($beat) && strtotime($beat) !== false
            && strtotime($beat) > time() - 60;
        if (! $connectorUp) {
            return redirect()->to($returnTo)->with('wa_pair_notice_error',
                'Conector Ubuntu offline. Execute: bash integrations/whatsapp-web/recuperar-conector-v83.sh; depois atualize a pagina. Nenhuma solicitacao foi criada.');
        }

        $busy = DB::table('whatsapp_web_scan_batches')
            ->where('session', $session)
            ->whereIn('status', ['queued', 'running'])->exists();
        if ($busy) {
            return redirect()->to($returnTo)->with('wa_pair_notice_error',
                'WhatsApp '.$session.' possui um lote pendente ou em execucao. Finalize ou interrompa o lote antes de reconectar.');
        }
        if (Cache::has($base.'request:'.$session)) {
            return redirect()->to($returnTo)->with('wa_pair_notice',
                'WhatsApp '.$session.': ja existe uma solicitacao de conexao em andamento. Atualize o estado do QR.');
        }

        $now = now('UTC');
        $created = Cache::put($base.'request:'.$session, [
            'token' => Str::random(40),
            'reset' => $reset,
            'actor_id' => $actor->id,
            'requested_at' => $now->toIso8601String(),
        ], $now->copy()->addSeconds(125));
        if (! $created) {
            return redirect()->to($returnTo)->with('wa_pair_notice_error',
                'Nao foi possivel registrar o pedido no cache. Verifique o Redis e tente novamente.');
        }
        Cache::forget($base.'qr:'.$session);
        Cache::put($base.'state:'.$session, [
            'state' => 'requested', 'at' => $now->toIso8601String(),
        ], $now->copy()->addMinutes(3));

        return redirect()->to($returnTo)->with('wa_pair_notice',
            'WhatsApp '.$session.': solicitacao recebida pelo Prospector. O conector Ubuntu preparara '.($reset ? 'um novo QR' : 'a reconexao').'. Se o QR ainda nao aparecer, clique em Atualizar QR e estado.');
    }
}
