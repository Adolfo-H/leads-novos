{{-- EC_WA_PAIR_V13_1: conexão por QR disponível SOMENTE para gestor. Sem envio de mensagens. --}}
@if(auth()->user()?->isCommercialManager())
    <section class="wa131-pair" aria-label="Conectar ou reconectar contas WhatsApp" wire:poll.visible.15s>
        <div class="wa131-pair-head">
            <div><strong>Conexão dos WhatsApps</strong><small>Reconecte cada dispositivo aqui. O QR é temporário e visível somente ao gestor.</small></div>
        </div>
        <div class="wa131-pair-grid">
            @foreach([1, 2] as $wa131Session)
                @php
                    $wa131Agent = $wa7Agents->firstWhere('session', $wa131Session);
                    $wa131Online = $wa131Agent?->online === true;
                    $wa131Busy = $wa131Agent?->batch && in_array($wa131Agent->batch->status, ['queued', 'running'], true);
                    $wa131Qr = \Illuminate\Support\Facades\Cache::get('prospector:wa:pair:v13_1:qr:'.$wa131Session);
                    $wa131State = \Illuminate\Support\Facades\Cache::get('prospector:wa:pair:v13_1:state:'.$wa131Session);
                    $wa131Pending = \Illuminate\Support\Facades\Cache::has('prospector:wa:pair:v13_1:request:'.$wa131Session);
                @endphp
                <div class="wa131-pair-card" wire:key="wa131-qr-{{ $wa131Session }}">
                    <div class="wa131-pair-title"><strong>WhatsApp {{ $wa131Session }}</strong><span class="wa131-pill {{ $wa131Online ? 'connected' : '' }}">{{ $wa131Online ? 'Conectado' : 'Desconectado' }}</span></div>
                    @if($wa131Online)
                        <p class="wa131-caption">Conta conectada. Não precisa ler um novo QR.</p>
                    @else
                        <div class="wa131-pair-actions">
                            <button type="button" wire:click="requestWebPairing({{ $wa131Session }})" wire:loading.attr="disabled" wire:target="requestWebPairing" @disabled($wa131Busy) class="wa131-btn primary">Conectar / reconectar</button>
                            <button type="button" wire:click="requestWebPairing({{ $wa131Session }}, true)" wire:loading.attr="disabled" wire:target="requestWebPairing" @disabled($wa131Busy) class="wa131-btn">Gerar novo QR</button>
                        </div>
                        @if(is_array($wa131State) && ($wa131State['state'] ?? '') === 'error')
                            <p class="wa131-error" role="alert">{{ $wa131State['message'] ?? 'Não foi possível conectar. Tente novamente.' }}</p>
                        @elseif(is_array($wa131Qr) && is_string($wa131Qr['image'] ?? null))
                            <p class="wa131-caption">QR gerado. Escaneie o código exibido na <strong>coluna direita</strong>.</p>
                        @elseif($wa131Pending || (is_array($wa131State) && in_array($wa131State['state'] ?? '', ['requested', 'starting'], true)))
                            <p class="wa131-caption">Preparando conexão. O estado, QR e o aviso de timeout aparecem na <strong>coluna direita</strong>.</p>
                        @else
                            <p class="wa131-caption">Use <strong>Conectar</strong> para retomar a sessão salva. Use <strong>Gerar novo QR</strong> somente quando necessário.</p>
                        @endif
                        @if($wa131Busy)<p class="wa131-error">Existe um lote pendente. Aguarde ou interrompa o lote antes de reconectar.</p>@endif
                    @endif
                </div>
            @endforeach
        </div>
        @if(session('wa_pair_notice'))<p class="wa131-notice" role="status">{{ session('wa_pair_notice') }}</p>@endif
        @error('wa_pair')<p class="wa131-error" role="alert">{{ $message }}</p>@enderror
        <small class="wa131-caption">O botão solicita a conexão ao conector no Ubuntu; não inicia mensagens. Se o conector não estiver ativo, use o comando indicado no README.</small>
    </section>
@endif
