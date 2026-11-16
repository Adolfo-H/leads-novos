@php
    $wa7 = $this->webMetrics;
    $wa7Agents = $this->webAgents;
    $wa7Daily = $this->webDaily;
    $wa7Budget = $wa7Daily['budget'];
    $wa7Settings = $wa7Daily['settings'];
    $wa7Percent = $wa7['total'] > 0 ? min(100, (int) round(100 * $wa7['confirmed'] / $wa7['total'])) : 0;
    $wa7Connected = $wa7Agents->filter(fn ($agent) => $agent->online)->count();
    $wa7Active = $wa7Agents->contains(fn ($agent) => $agent->batch && in_array($agent->batch->status, ['queued', 'running'], true));
@endphp
<div class="wa7-dashboard" data-test="whatsapp-web-v6-dashboard" data-test-v7="whatsapp-unified-v7" wire:poll.visible.25s>
    <section class="wa7-overview" aria-label="Resumo único da verificação WhatsApp">
        <div class="wa7-intro">
            <div>
                <span class="wa7-eyebrow">VISÃO GERAL · VERIFICAÇÃO WHATSAPP</span>
                <h2>Acompanhe tudo em um só lugar</h2>
                <p>Os números encontrados pelo WhatsApp Web entram como <strong>confirmados via Web</strong> e aparecem também em Contatos. Nenhuma mensagem é enviada.</p>
            </div>
            <div class="wa7-index-note">{{ $fmt($summary['total']) }} números únicos no índice<br><small>{{ $fmt($summary['landline']) }} fixos · {{ $fmt($summary['review']) }} para revisar</small></div>
        </div>
        <div class="wa7-metrics">
            <button type="button" wire:click="quickFilter('mobile','all')" class="wa7-metric" aria-label="Ver todos os celulares">
                <span>Celulares cadastrados</span><strong>{{ $fmt($wa7['total']) }}</strong><small>Total de celulares, não diminui</small>
            </button>
            <button type="button" wire:click="quickFilter('mobile','yes')" class="wa7-metric wa7-metric-good" aria-label="Mostrar celulares com WhatsApp confirmado">
                <span>Com WhatsApp</span><strong>{{ $fmt($wa7['confirmed']) }}</strong><small>Manual ou encontrado via Web</small>
            </button>
            <button type="button" wire:click="quickFilter('mobile','unknown')" class="wa7-metric wa7-metric-accent" aria-label="Mostrar celulares aguardando confirmação">
                <span>A verificar</span><strong>{{ $fmt($wa7['pending']) }}</strong><small>Ainda sem confirmação positiva</small>
            </button>
            <button type="button" wire:click="quickWebFilter('consulted')" class="wa7-metric" aria-label="Mostrar números já consultados pelo WhatsApp Web">
                <span>Consultados via Web</span><strong>{{ $fmt($wa7['consulted']) }}</strong><small>{{ $fmt($wa7['never_consulted']) }} nunca consultados</small>
            </button>
        </div>
        <div class="wa7-completion">
            <div class="wa7-progress-label"><span><strong>{{ $wa7Percent }}%</strong> dos celulares com WhatsApp confirmado</span><span>Comprovantes técnicos identificados como via Web</span></div>
            <div class="wa7-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $wa7Percent }}" aria-label="Porcentagem de celulares confirmados"><span style="width:{{ $wa7Percent }}%"></span></div>
        </div>
    </section>

    <section class="wa7-operation" aria-label="Operar consultas do WhatsApp">
        <div class="wa7-operation-header">
            <div><span class="wa7-eyebrow">EXECUÇÃO</span><h2>Verificar números</h2><p>As duas contas trabalham em filas separadas. Escolha o lote ou programe uma rotina diária.</p></div>
            <span class="wa7-online-count">{{ $wa7Connected }}/2 contas conectadas</span>
        </div>
        <div class="wa7-operation-grid">
            <div class="wa7-operation-card">
                <div class="wa7-card-title"><h3>Verificar agora</h3><span class="wa7-muted">Consulta sem envio de mensagens</span></div>
                <div class="wa7-agent-list">
                    @foreach ($wa7Agents as $agent)
                        @php $inProgress = $agent->batch && in_array($agent->batch->status, ['queued', 'running'], true); @endphp
                        <div class="wa7-agent" wire:key="wa7-agent-{{ $agent->session }}">
                            <div class="wa7-agent-row">
                                <div class="wa7-agent-identity"><span class="wa7-agent-symbol">{{ $agent->session }}</span><span><strong>WhatsApp {{ $agent->session }}</strong><small>{{ $agent->account_number ? 'Dispositivo ••••'.substr((string) $agent->account_number, -4) : 'Dispositivo não identificado' }}</small></span></div>
                                <span class="wa7-presence {{ $agent->online ? 'wa7-on' : 'wa7-off' }}">{{ $agent->online ? 'Conectado' : 'Desconectado' }}</span>
                            </div>
                            @if($inProgress)
                                <div class="wa7-batch-progress">{{ $agent->batch->status === 'queued' ? 'Lote aguardando' : 'Consultando' }} · {{ $agent->batch->processed }}/{{ $agent->batch->requested_limit }}
                                    @if ($agent->current_e164 && $agent->online)<small>Agora: {{ \App\Services\Contacts\BrazilPhoneNumber::display($agent->current_e164, $agent->current_digits) }}</small>@endif
                                </div>
                                <button class="wa7-stop" type="button" wire:click="cancelWebBatch({{ $agent->batch->id }})" wire:loading.attr="disabled" wire:target="cancelWebBatch">{{ $agent->batch->cancel_requested_at ? 'Pausa solicitada' : 'Interromper após o número atual' }}</button>
                            @elseif($agent->batch)
                                <small class="wa7-last-run">Último lote: {{ $agent->batch->processed }} números · {{ match ($agent->batch->status) { 'completed' => 'Concluído', 'cancelled' => 'Interrompido', 'failed' => 'Falha', default => 'Aguardando' } }}</small>
                            @else
                                <small class="wa7-last-run">Pronto para iniciar um lote</small>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="wa7-now-row">
                    <label class="wa7-input-label">Lote por conta
                        <select wire:model.live="webBatchLimit" aria-label="Quantidade de números por conta">
                            <option value="3">3 números</option><option value="5">5 números</option><option value="10">10 números</option>
                        </select>
                    </label>
                    <button type="button" class="wa7-primary" wire:click="requestWebBoth" wire:loading.attr="disabled" wire:target="requestWebBoth" @disabled($wa7Connected === 0 || $wa7Budget['remaining'] === 0)>
                        {{ $wa7Active ? 'Verificar nas contas livres' : 'Verificar agora com as 2 contas' }}
                    </button>
                </div>
                <p class="wa7-small-help">Restam <strong>{{ $wa7Budget['remaining'] }}</strong> consultas dentro do limite de hoje. Conta offline não recebe lote. Os agentes precisam estar ligados no Ubuntu.</p>
                <div class="wa7-account-shortcuts"><span>Usar só uma conta:</span>
                    @foreach($wa7Agents as $agent)
                        @php $busy = $agent->batch && in_array($agent->batch->status, ['queued', 'running'], true); @endphp
                        <button type="button" wire:click="requestWebBatch({{ $agent->session }})" wire:loading.attr="disabled" wire:target="requestWebBatch" @disabled(!$agent->online || $busy || $wa7Budget['remaining'] === 0)>Conta {{ $agent->session }}</button>
                    @endforeach
                </div>
            </div>
            <div class="wa7-operation-card wa7-daily-card">
                <div class="wa7-card-title"><h3>Rotina diária</h3><span class="wa7-plan-state {{ $wa7Settings->auto_enabled ? 'is-on' : '' }}">{{ $wa7Settings->auto_enabled ? 'Agendamento ativo' : 'Manual' }}</span></div>
                <div class="wa7-daily-figures">
                    <div><small>Consultas hoje</small><strong>{{ $fmt($wa7Budget['checked']) }}</strong></div>
                    <div><small>Limite definido</small><strong>{{ $fmt($wa7Budget['limit']) }}</strong></div>
                    <div><small>Restam hoje</small><strong>{{ $fmt($wa7Budget['remaining']) }}</strong></div>
                </div>
                <div class="wa7-daily-fields">
                    <label class="wa7-input-label">Máximo por dia
                        <select wire:model="webDailyLimit" aria-label="Máximo de consultas por dia">
                            <option value="10">10 números</option><option value="20">20 números</option><option value="30">30 números</option>
                        </select>
                    </label>
                    <label class="wa7-input-label">Começar às
                        <input type="time" wire:model="webDailyTime" aria-label="Horário da verificação diária">
                    </label>
                </div>
                <label class="wa7-toggle"><input type="checkbox" wire:model="webDailyEnabled"><span><strong>Agendar todos os dias</strong><small>Opcional. Só executa na janela de 1 hora após o horário escolhido, com os agentes conectados.</small></span></label>
                <button type="button" class="wa7-secondary" wire:click="saveWebDailyPlan" wire:loading.attr="disabled" wire:target="saveWebDailyPlan">Salvar programação</button>
                <small class="wa7-small-help">Para reduzir riscos, prefira 10 por dia. O limite vale para as duas contas juntas; consultas anteriores do mesmo dia são contabilizadas.</small>
                @error('wa_plan')<p class="wa7-error" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>
        @if(session('wa_web_v7_message'))<div class="wa7-success" role="status">{{ session('wa_web_v7_message') }}</div>@endif
        @if(session('wa_web_v6_message'))<div class="wa7-success" role="status">{{ session('wa_web_v6_message') }}</div>@endif
        @error('wa_scan')<p class="wa7-error" role="alert">{{ $message }}</p>@enderror
    </section>

    <section class="wa7-recent" data-test="whatsapp-web-v6-recent" aria-label="Últimas verificações realizadas">
        <div class="wa7-recent-head"><div><span class="wa7-eyebrow">HISTÓRICO</span><h2>Últimos números verificados</h2><p>O resultado já está associado aos contatos que usam o mesmo telefone.</p></div><button type="button" wire:click="quickWebFilter('consulted')">Ver todos os consultados ›</button></div>
        @if($this->webRecent->isEmpty())
            <p class="wa7-empty">Nenhuma consulta realizada. Ao consultar o primeiro lote, os números aparecerão aqui.</p>
        @else
            <div class="wa7-result-list">
                @foreach($this->webRecent->take(8) as $recent)
                    @php
                        $wa7Result = match (true) {
                            $recent->result === 'found' && $recent->status === 'yes' => 'Com WhatsApp',
                            $recent->result === 'found' => 'Encontrado · revisar',
                            $recent->result === 'not_found' => 'Não localizado',
                            $recent->result === 'inconclusive' => 'Inconclusivo',
                            default => 'Falha',
                        };
                        $wa7Class = $recent->result === 'found' && $recent->status === 'yes' ? 'confirmed' : $recent->result;
                    @endphp
                    <button type="button" wire:key="wa7-recent-{{ $recent->id }}" class="wa7-result" wire:click="openWebRecent({{ $recent->id }})">
                        <span class="wa7-result-phone"><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($recent->e164, $recent->digits) }}</strong><small>{{ $recent->contacts_count }} contato(s) vinculado(s)</small></span>
                        <span class="wa7-result-pill wa7-pill-{{ $wa7Class }}">{{ $wa7Result }}{{ $recent->result === 'found' && $recent->status === 'yes' ? ' · via Web' : '' }}</span>
                        <span class="wa7-result-time">{{ $recent->session ? 'Conta '.$recent->session : 'Consulta anterior' }}<small>{{ \Carbon\Carbon::parse($recent->checked_at)->timezone('America/Sao_Paulo')->format('d/m H:i') }}</small></span>
                        <span class="wa7-chevron">›</span>
                    </button>
                @endforeach
            </div>
        @endif
    </section>
</div>
