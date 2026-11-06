{{-- EC_WA_VISUAL_V12: atalhos do histórico retornam à lista. --}}
@php
    $wa7Agents = $this->webAgents;
    $wa7Daily = $this->webDaily;
    $wa7Budget = $wa7Daily['budget'];
    $wa7Settings = $wa7Daily['settings'];
    $wa7Connected = $wa7Agents->filter(fn ($agent) => $agent->online)->count();
    $wa7Active = $wa7Agents->contains(fn ($agent) => $agent->batch && in_array($agent->batch->status, ['queued', 'running'], true));
@endphp
<div class="wa7-dashboard wa8-operation-block" data-test="whatsapp-v8-operations">
    <section class="wa7-operation" id="wa8-operacao" aria-label="Operar consultas do WhatsApp">
        <div class="wa7-operation-header">
            <div><span class="wa7-eyebrow">EXECUÇÃO</span><h2>Verificar números</h2><p>Escolha quais tipos de número deseja conferir. Celulares e fixos válidos podem entrar na fila; formatos incompletos não.</p></div>
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

                {{-- EC_WA_PAIR_V13_1: ligação por QR direto na Central; não aciona consultas. --}}
                @include('contacts.whatsapp-pairing-v13-1-styles')
                @include('contacts.whatsapp-pairing-v13-1')
                <label class="wa7-input-label wa8-scope-choice">Quais números consultar
                    <select wire:model.live="webScanScope" aria-label="Tipo de telefones para consultas">
                        <option value="all">Celulares e fixos</option>
                        <option value="mobile">Somente celulares</option>
                        <option value="landline">Somente fixos</option>
                    </select>
                </label>
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
                            <option value="10">10 números</option><option value="20">20 números</option><option value="30">30 números</option><option value="50">50 números</option><option value="100">100 números no total · 50 por conta</option><option value="200">200 números no total · 100 por conta</option><!-- EC_WA_200_DUAS_CONTAS_V9 -->
                        </select>
                    </label>
                    <label class="wa7-input-label">Começar às
                        <input type="time" wire:model="webDailyTime" aria-label="Horário da verificação diária">
                    </label>
                    <label class="wa7-input-label">Encerrar às
                        <input type="time" wire:model="webDailyEndTime" aria-label="Horário final da verificação diária">
                    </label>
                </div>
                <label class="wa7-toggle"><input type="checkbox" wire:model="webDailyEnabled"><span><strong>Agendar todos os dias</strong><small>Executa apenas entre os horários escolhidos, com os agentes conectados.</small></span></label>
                <button type="button" class="wa7-secondary" wire:click="saveWebDailyPlan" wire:loading.attr="disabled" wire:target="saveWebDailyPlan">Salvar programação</button>
                <div class="wa7-small-help" data-test="wa-v9-budget">Por conta: <strong>{{ $fmt($wa7Budget['per_account_limit']) }}</strong> consultas/dia. Conta 1: <strong>{{ $fmt($wa7Budget['sessions'][1]['checked']) }}</strong> feitas, <strong>{{ $fmt($wa7Budget['sessions'][1]['remaining']) }}</strong> disponíveis. Conta 2: <strong>{{ $fmt($wa7Budget['sessions'][2]['checked']) }}</strong> feitas, <strong>{{ $fmt($wa7Budget['sessions'][2]['remaining']) }}</strong> disponíveis.</div>
                <small class="wa7-small-help">Programação dividida entre 08h e 18h, quando configurada assim. Sem garantias de segurança: integração não oficial pode causar restrições ou bloqueio das contas comerciais.</small>
                @error('wa_plan')<p class="wa7-error" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>
        @if(session('wa_web_v7_message'))<div class="wa7-success" role="status">{{ session('wa_web_v7_message') }}</div>@endif
        @if(session('wa_web_v6_message'))<div class="wa7-success" role="status">{{ session('wa_web_v6_message') }}</div>@endif
        @error('wa_scan')<p class="wa7-error" role="alert">{{ $message }}</p>@enderror
    </section>

    {{-- EC_WA_PUBLIC_DIRECTORY_V14_REVIEW: não consultar números inválidos no WhatsApp. --}}
    <div style="padding:13px 17px;margin:12px 0;background:#f0f9f7;border:1px solid #d2eae5;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div><strong style="font-size:13px;color:#0b4151">Também precisa revisar telefones incompletos?</strong>
        <p style="font-size:11px;color:#698694;margin:3px 0">A lista completa inclui números com DDD ou dígitos inconsistentes. Corrija o cadastro antes de consultar pelo WhatsApp.</p></div>
        <button type="button" wire:click="quickFilter('review','all')" x-on:click="$dispatch('wa12-show-numbers')" style="background:#fff;border:1px solid #a4d8d1;color:#087d72;border-radius:8px;padding:10px 14px;font-size:12px;font-weight:700">Ver formatos a revisar</button>
    </div>
    <section class="wa7-recent" data-test="whatsapp-web-v6-recent" aria-label="Últimas verificações realizadas">
        <div class="wa7-recent-head"><div><span class="wa7-eyebrow">HISTÓRICO</span><h2>Histórico recente de consultas</h2><p>O resultado já está associado aos contatos que usam o mesmo telefone.</p></div><button type="button" wire:click="quickWebFilter('consulted')" x-on:click="$dispatch('wa12-show-numbers')">Filtrar lista pelos consultados ›</button></div>
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
                    <button type="button" wire:key="wa7-recent-{{ $recent->id }}" class="wa7-result" wire:click="openWebRecent({{ $recent->id }})" x-on:click="$dispatch('wa12-show-numbers')">
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
