@php
    $wa6 = $this->webMetrics;
    $agents = $this->webAgents;
    $wa6Percent = $wa6['total'] > 0 ? round(100 * $wa6['consulted'] / $wa6['total']) : 0;
@endphp
<section class="wa6-main" data-test="whatsapp-web-v6-dashboard" wire:poll.visible.25s>
    <div class="wa6-head">
        <div>
            <span class="wa6-eyebrow">INTEGRAÇÃO EXPERIMENTAL · WHATSAPP WEB</span>
            <h2>Monitoramento das verificações</h2>
            <p>Veja o que foi consultado, o que falta e inicie um lote em uma das duas contas conectadas.</p>
        </div>
        <div class="wa6-progress-chip">{{ $wa6Percent }}% dos celulares consultados</div>
    </div>
    <div class="wa6-metrics" aria-label="Resumo de consultas Web aos celulares">
        <div><small>Celulares cadastrados</small><strong>{{ $fmt($wa6['total']) }}</strong><span>O total não diminui</span></div>
        <div><small>Já consultados</small><strong>{{ $fmt($wa6['consulted']) }}</strong><span>Consulta técnica registrada</span></div>
        <div class="wa6-highlight"><small>Não consultados</small><strong>{{ $fmt($wa6['never_consulted']) }}</strong><span>Este número diminui a cada lote</span></div>
        <div><small>Encontrados</small><strong>{{ $fmt($wa6['found']) }}</strong><span>Indício positivo, não confirmação</span></div>
        <div><small>Não localizados</small><strong>{{ $fmt($wa6['not_found']) }}</strong><span>Indício negativo, não definitivo</span></div>
    </div>
    <p class="wa6-eligibility">{{ $fmt($wa6['eligible_never']) }} celular(es) não consultado(s) e sem confirmação humana, elegíveis para os próximos lotes.</p>
    <div class="wa6-progress-bg" aria-label="Percentual de celulares consultados"><div style="width: {{ $wa6Percent }}%"></div></div>

    <div class="wa6-agent-head">
        <div>
            <h3>Contas conectadas e execução</h3>
            <p>Cada conta consulta somente sua metade da fila. Limite de até 10 números por lote.</p>
        </div>
        <label class="wa6-limit">Tamanho do lote
            <select wire:model.live="webBatchLimit" aria-label="Números por consulta">
                <option value="3">3 números</option>
                <option value="5">5 números</option>
                <option value="10">10 números</option>
            </select>
        </label>
    </div>
    <div class="wa6-agents">
        @foreach($agents as $agent)
            @php
                $running = $agent->batch && in_array($agent->batch->status, ['queued', 'running'], true);
                $batchStatus = $agent->batch?->status ?? 'none';
            @endphp
            <article class="wa6-agent" wire:key="wa6-agent-{{ $agent->session }}">
                <div class="wa6-agent-title">
                    <div class="wa6-agent-id"><span class="wa6-device-icon">{{ $agent->session }}</span><div><strong>WhatsApp {{ $agent->session }}</strong><small>{{ $agent->session === 1 ? 'Primeira conta · IDs pares' : 'Segunda conta · IDs ímpares' }}</small>
                        @if($agent->account_number)<small class="wa6-account-phone">Dispositivo: +{{ $agent->account_number }}</small>@endif</div></div>
                    <span class="wa6-state {{ $agent->online ? 'is-connected' : 'is-offline' }}"><span class="wa6-state-dot"></span>{{ $agent->online ? 'Conectado agora' : 'Agente desconectado' }}</span>
                </div>
                @if($running)
                    <div class="wa6-running"><strong>{{ $batchStatus === 'queued' ? 'Lote aguardando início' : 'Consultando números' }}</strong><span>{{ $agent->batch->processed }} de {{ $agent->batch->requested_limit }} concluídos</span>
                        @if($agent->online && $agent->current_e164)<small class="wa6-current">Consultando agora: {{ \App\Services\Contacts\BrazilPhoneNumber::display($agent->current_e164, $agent->current_digits) }}</small>@endif
                        <div class="wa6-batch-progress"><span style="width: {{ $agent->batch->requested_limit > 0 ? round(100 * $agent->batch->processed / $agent->batch->requested_limit) : 0 }}%"></span></div>
                    </div>
                    <button class="wa6-cancel" type="button" wire:click="cancelWebBatch({{ $agent->batch->id }})" wire:loading.attr="disabled" wire:target="cancelWebBatch">
                        {{ $agent->batch->cancel_requested_at ? 'Parada solicitada' : 'Parar após esta consulta' }}
                    </button>
                @elseif($agent->batch)
                    <div class="wa6-last-batch">
                        Último lote: <strong>{{ match($batchStatus) { 'completed' => 'Concluído', 'cancelled' => 'Cancelado', 'failed' => 'Interrompido', default => 'Aguardando' } }}</strong>
                        · {{ $agent->batch->processed }} consultado(s)
                        @if($agent->batch->finished_at) · {{ \Carbon\Carbon::parse($agent->batch->finished_at)->timezone('America/Sao_Paulo')->format('d/m H:i') }} @endif
                    </div>
                @else
                    <p class="wa6-idle">Nenhum lote iniciado pelo painel ainda.</p>
                @endif
                <button type="button" class="wa6-start" wire:click="requestWebBatch({{ $agent->session }})"
                    wire:loading.attr="disabled" wire:target="requestWebBatch" @disabled(!$agent->online || $running)>
                    {{ $running ? 'Lote em andamento' : ('Conferir próximos '.$webBatchLimit.' números') }}
                </button>
                @unless($agent->online)
                    <small class="wa6-agent-hint">Inicie o agente {{ $agent->session }} no Ubuntu. Sessão cadastrada não significa conexão ativa.</small>
                @endunless
                @if ($agent->batch?->error_message)
                    <small class="wa6-agent-error">{{ $agent->batch->error_message }}</small>
                @endif
            </article>
        @endforeach
    </div>
    @if(session('wa_web_v6_message')) <div class="wa6-notice" role="status">{{ session('wa_web_v6_message') }}</div> @endif
    @error('wa_scan') <div class="wa6-notice wa6-notice-error" role="alert">{{ $message }}</div> @enderror
    <p class="wa6-disclaimer">Sem envio de mensagens. A consulta usa biblioteca não oficial e pode sofrer falhas ou restrições. “Encontrado” e “não localizado” são indícios técnicos; a confirmação humana continua separada.</p>
</section>
