{{-- EC_WA_PAIR_V18: monitor somente leitura; QR nunca vai para logs, banco ou público. --}}
@if(auth()->user()?->isCommercialManager())
    @php
        $wa18Cache = \Illuminate\Support\Facades\Cache::class;
        $wa18Base = 'prospector:wa:pair:v13_1:';
        $wa18Heartbeat = $wa18Cache::get($wa18Base.'connector:heartbeat');
        $wa18BeatAt = is_string($wa18Heartbeat) ? strtotime($wa18Heartbeat) : false;
        $wa18ConnectorUp = $wa18BeatAt !== false && (time() - $wa18BeatAt) < 60;
    @endphp
    <section class="wa18-qr-panel" wire:poll.visible.10s aria-label="QR Code para conexão WhatsApp" data-test="wa18-qr-panel">
        <style>
            .wa18-qr-panel{margin-top:18px;border-top:1px solid #d8e7e9;padding-top:16px;display:grid;gap:12px}
            .wa18-qr-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
            .wa18-qr-head strong{font-size:14px;color:#0a3947}
            .wa18-qr-head small{display:block;font-size:11px;color:#7891a0;margin-top:4px}
            .wa18-health{font-size:10px;padding:5px 9px;border-radius:20px;background:#eaf8f3;color:#0b7868}
            .wa18-health.offline{background:#fff0e9;color:#a25738}
            .wa18-qr-slot{border:1px solid #d7e5e9;border-radius:10px;padding:13px;background:white}
            .wa18-qr-slot h4{font-size:13px;color:#123b4e;margin:0 0 7px}
            .wa18-qr-slot p{font-size:11px;color:#6c8796;line-height:1.5;margin:7px 0}
            .wa18-qr-slot .wa18-qr-img{max-width:100%;width:275px;height:auto;display:block;margin:10px auto;border:1px solid #c5ded9;border-radius:10px;background:white;padding:8px}
            .wa18-qr-slot .wa18-qr-warning{color:#a14a37;background:#fff4ee;padding:11px;border-radius:7px}
            .wa18-qr-slot .wa18-qr-ok{color:#147b67;background:#e8f7f0;padding:9px;border-radius:7px}
            .wa18-command{font-size:11px;word-break:break-word;padding:8px;border-radius:6px;background:#f1f5f6;color:#285365;display:block}
        </style>
        <div class="wa18-qr-head">
            <div><strong>Conexão por QR Code</strong><small>O código aparecerá aqui depois de solicitar o pareamento na coluna esquerda.</small></div>
            <span class="wa18-health {{ $wa18ConnectorUp ? '' : 'offline' }}">{{ $wa18ConnectorUp ? 'Conector Ubuntu ativo' : 'Conector Ubuntu offline' }}</span>
        </div>
        @unless($wa18ConnectorUp)
            <div class="wa18-qr-slot">
                <p class="wa18-qr-warning" role="alert">O conector local não está respondendo. Não adianta ficar aguardando indefinidamente: inicie o processo no Ubuntu e tente Conectar novamente.</p>
                <code class="wa18-command">cd ~/projetos/prospector-exportcontrol && bash integrations/whatsapp-web/iniciar-conector.sh</code>
            </div>
        @endunless
        @foreach([1, 2] as $wa18Session)
            @php
                $wa18Agent = $wa7Agents->firstWhere('session', $wa18Session);
                $wa18Connected = $wa18Agent?->online === true;
                $wa18Request = $wa18Cache::get($wa18Base.'request:'.$wa18Session);
                $wa18Qr = $wa18Cache::get($wa18Base.'qr:'.$wa18Session);
                $wa18State = $wa18Cache::get($wa18Base.'state:'.$wa18Session);
                $wa18RequestAt = is_array($wa18Request) && is_string($wa18Request['requested_at'] ?? null)
                    ? strtotime($wa18Request['requested_at']) : false;
                $wa18StateAt = is_array($wa18State) && is_string($wa18State['at'] ?? null)
                    ? strtotime($wa18State['at']) : false;
                $wa18Pending = is_array($wa18Request);
                $wa18StateName = is_array($wa18State) ? ($wa18State['state'] ?? '') : '';
                $wa18Timeout = ($wa18RequestAt !== false && (time() - $wa18RequestAt) >= 90)
                    || ($wa18StateName === 'starting' && $wa18StateAt !== false && (time() - $wa18StateAt) >= 90);
                $wa18HasQr = is_array($wa18Qr) && is_string($wa18Qr['image'] ?? null)
                    && str_starts_with($wa18Qr['image'], 'data:image/png;base64,');
            @endphp
            <div class="wa18-qr-slot" wire:key="wa18-session-{{ $wa18Session }}">
                <h4>WhatsApp {{ $wa18Session }}</h4>
                @if($wa18Connected)
                    <p class="wa18-qr-ok">Conta conectada. Nenhum QR necessário.</p>
                @elseif($wa18StateName === 'error')
                    <p class="wa18-qr-warning" role="alert">{{ is_array($wa18State) ? ($wa18State['message'] ?? 'Falha de conexão. Verifique o conector Ubuntu.') : 'Falha de conexão.' }}</p>
                @elseif($wa18HasQr)
                    <img class="wa18-qr-img" src="{{ $wa18Qr['image'] }}" width="275" height="275" alt="QR temporário do WhatsApp {{ $wa18Session }} para leitura pelo gestor">
                    <p>Abra o WhatsApp do celular → <strong>Aparelhos conectados</strong> → <strong>Conectar aparelho</strong>. Leia o QR, que expira rapidamente.</p>
                @elseif($wa18Timeout)
                    <p class="wa18-qr-warning" role="alert">A conexão não gerou um QR em até 90 segundos. Confira os logs do agente no Ubuntu; em seguida tente Conectar novamente. Se a sessão estiver inválida, use Gerar novo QR.</p>
                @elseif($wa18Pending && $wa18ConnectorUp)
                    <p role="status">Solicitação recebida. Aguardando o agente preparar a sessão ou o QR (máximo de 90 segundos).</p>
                @elseif($wa18StateName === 'starting' && $wa18ConnectorUp)
                    <p role="status">Agente iniciado. Aguardando a confirmação da sessão ou o QR (máximo de 90 segundos).</p>
                @else
                    <p>Nenhum QR solicitado. Use o botão <strong>Conectar / reconectar</strong> na conta correspondente.</p>
                @endif
            </div>
        @endforeach
        <p style="font-size:11px;color:#718998">A conexão usa uma biblioteca não oficial. QR não é garantia de conexão. Nenhuma mensagem é enviada nesta tela.</p>
    </section>
@endif
