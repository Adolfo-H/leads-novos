<div class="ecv5" data-ec-followup-editor="v5" wire:poll.60s>
<style>
    [data-ec-followup-editor="v5"] .ecv6-detail-result { margin: 14px 0; padding: 18px 20px; border: 1px solid #9cdbcb; border-radius: 13px; background: #eaf8f3; }
    [data-ec-followup-editor="v5"] .ecv6-detail-result.is-warning { border-color: #edc58a; background: #fff8e9; }
    [data-ec-followup-editor="v5"] .ecv6-detail-result-header { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
    [data-ec-followup-editor="v5"] .ecv6-detail-result-header span { font-size: 10px; letter-spacing: .075em; color: #08766b; font-weight: 700; }
    [data-ec-followup-editor="v5"] .ecv6-detail-result-header h3 { margin-top: 5px; font-weight: 700; font-size: 16px; color: #0b3347; }
    [data-ec-followup-editor="v5"] .ecv6-detail-result-header > strong { color: #027b6f; font-size: 16px; }
    [data-ec-followup-editor="v5"] .ecv6-detail-stats { display: grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap: 8px; margin: 14px 0; }
    [data-ec-followup-editor="v5"] .ecv6-detail-stats > div { background: white; border: 1px solid #cce3dd; border-radius: 9px; padding: 12px 11px; min-width: 0; }
    [data-ec-followup-editor="v5"] .ecv6-detail-stats span { display: block; color: #687e8a; font-size: 11px; }
    [data-ec-followup-editor="v5"] .ecv6-detail-stats strong { display: block; color: #0b3347; font-size: 22px; margin-top: 5px; }
    [data-ec-followup-editor="v5"] .ecv6-detail-result p, [data-ec-followup-editor="v5"] .ecv6-detail-result small { color: #536e78; font-size: 12px; }
    @media (max-width: 680px) { [data-ec-followup-editor="v5"] .ecv6-detail-stats { grid-template-columns: repeat(2,minmax(0,1fr)); } }
</style>

    <header class="ecv5-header">
        <div>
            <span class="ecv5-eyebrow">CONTINUIDADE COMERCIAL · AUTOMAÇÃO</span>
            <h2>{{ $schedule && (int) $schedule['remaining_followups'] === 0 && (int) $schedule['sent_followups'] > 0 ? 'Retomada comercial — resultados' : 'Próximos e-mails, no automático' }}</h2>
            @if($schedule)
                <p><strong>Acompanhamento:</strong> {{ $schedule['name'] }}</p>
            @endif
            <p>{{ $schedule && (int) $schedule['remaining_followups'] === 0 ? 'Todas as rodadas previstas foram iniciadas. Confira abaixo os resultados SMTP reais e o acompanhamento das devoluções.' : 'A campanha termina, o prazo é contado e o sistema prepara a próxima rodada sozinho. Veja ou altere a programação aqui.' }}</p>
        </div>
        @if($schedule && $schedule['automatic'])
            <span class="ecv5-status ecv5-status--{{ $schedule['state'] }}">{{ $schedule['state_label'] }}</span>
        @else
            <span class="ecv5-status ecv5-status--pending">Ainda não programado</span>
        @endif
    </header>

    @if($schedule)
        <div class="ecv5-showcase">
            <div class="ecv5-next">
                <span>{{ (int) $schedule['remaining_followups'] === 0 ? 'ÚLTIMA RODADA' : 'PRÓXIMO E-MAIL PREVISTO' }}</span>
                <strong>{{ (int) $schedule['remaining_followups'] === 0 ? $schedule['last_round_number'].'ª rodada — resultado SMTP' : ($schedule['due_datetime'] ?: 'Após conclusão') }}</strong>
                <b>{{ (int) $schedule['remaining_followups'] === 0 ? $schedule['sent_followups'].' de '.$schedule['max_followups'].' follow-up(s) iniciados' : ($schedule['next_round'].'ª rodada · '.$schedule['days'].' dias '.($schedule['send_time_local'] ? 'de calendário, às '.$schedule['send_time_local'] : 'exatos, após o último envio concluído (legado)')) }}</b>
                <div class="ecv5-next-info">
                    @if ((int) $schedule['remaining_followups'] === 0 && (int) $schedule['sent_followups'] > 0)
                        <div><span>Aceitos pelo SMTP</span><strong>{{ $schedule['accepted_smtp_count'] }} de {{ $schedule['latest_total_count'] }}</strong></div>
                    @else
                        <div><span>Contatos potencialmente elegíveis</span><strong>{{ $schedule['eligible_count'] === null ? 'A calcular' : number_format((int) $schedule['eligible_count'], 0, ',', '.') }}</strong></div>
                    @endif
                    <div><span>Retornos iniciados</span><strong>{{ $schedule['sent_followups'] }} / {{ $schedule['max_followups'] }}</strong></div>
                </div>
                <small>{{ (int) $schedule['remaining_followups'] === 0 ? 'O processamento da última rodada já começou. A aceitação SMTP não garante entrega na caixa de entrada.' : 'Este é o horário previsto para INICIAR os envios, não para entregar todas as mensagens. A fila respeita janela e cotas SMTP.' }}</small>
            </div>
            <div class="ecv5-mail-preview">
                <div class="ecv5-mail-heading"><strong>Mensagem que será utilizada</strong><span>Prévia real do plano</span></div>
                <div class="ecv5-mail-subject"><span>ASSUNTO</span><strong>{{ $schedule['subject'] }}</strong></div>
                <div class="ecv5-mail-body"><span>CORPO DO E-MAIL</span><p>{{ \Illuminate\Support\Str::limit($schedule['body'], 230) }}</p></div>
                <details><summary>Ver a mensagem inteira</summary><div>{{ $schedule['body'] }}</div></details>
                <small>Remetente: {{ $schedule['from_address'] ?: 'Não configurado' }} · A personalização é aplicada por contato no envio.</small>
            </div>
        </div>
        @if ((int) $schedule['sent_followups'] > 0)
            <section class="ecv6-detail-result {{ $schedule['bounces_count'] > 0 || $schedule['uncertain_count'] > 0 ? 'is-warning' : '' }}" aria-label="Resultado real da última rodada" role="status">
                <div class="ecv6-detail-result-header">
                    <div><span>RESULTADO REAL DO FOLLOW-UP</span><h3>{{ $schedule['last_round_number'] }}ª rodada — {{ $schedule['latest_campaign_name'] }}</h3></div>
                    <strong>{{ $schedule['accepted_smtp_count'] }}/{{ $schedule['latest_total_count'] }} aceitos pelo SMTP</strong>
                </div>
                <div class="ecv6-detail-stats">
                    <div><span>Aceitos SMTP</span><strong>{{ $schedule['accepted_smtp_count'] }}</strong></div>
                    <div><span>Devoluções</span><strong>{{ $schedule['bounces_count'] }}</strong></div>
                    <div><span>Resultados incertos</span><strong>{{ $schedule['uncertain_count'] }}</strong></div>
                    <div><span>Respostas</span><strong>{{ $schedule['replies_count'] }}</strong></div>
                    <div><span>Pendentes</span><strong>{{ $schedule['pending_count'] }}</strong></div>
                </div>
                <p>Campanha #{{ $schedule['latest_campaign_id'] }} ·
                    @if ($schedule['latest_campaign_status'] === 'running' && $schedule['pending_count'] === 0 && $schedule['uncertain_count'] === 0 && $schedule['latest_total_count'] === $schedule['accepted_smtp_count'])
                        Todos os aceites SMTP registrados; o status técnico ainda aguarda atualização do agendador.
                    @else
                        Situação técnica: {{ $schedule['latest_campaign_status'] }}.
                    @endif
                    @if ($schedule['latest_smtp_at']) Última aceitação: {{ $schedule['latest_smtp_at'] }} (Brasília). @endif
                </p>
                <small>Registros SMTP, não confirmação de entrega ou abertura. Confira a campanha [Rodada {{ $schedule['last_round_number'] }}] em Minhas campanhas para ver os destinatários.</small>
            </section>
        @endif
        <div class="ecv5-state-line {{ in_array($schedule['state'], ['blocked','manual','paused','no_recipients'], true) ? 'is-attention' : '' }}">
            <span class="ecv5-state-icon" aria-hidden="true">{{ in_array($schedule['state'], ['scheduled','ready'], true) ? '✓' : 'i' }}</span>
            <div><strong>Estado atual</strong><p>{{ $schedule['state_reason'] }}</p></div>
        </div>
        @if($schedule['automatic'] && ($schedule['remaining_followups'] > 0 || $schedule['sent_followups'] > 0))
            <section class="ecv5-timeline" aria-label="Rodadas programadas">
                <div class="ecv5-section-head"><h3>Sequência das rodadas</h3><span>O histórico é registrado em campanhas individuais</span></div>
                <div class="ecv5-steps">
                    <div class="ecv5-step is-started"><b>1</b><span><strong>Rodada inicial</strong><small>Concluída</small></span></div>
                    @foreach($schedule['rounds'] as $round)
                        <div class="ecv5-step is-{{ $round['stage'] }}"><b>{{ $round['number'] }}</b><span><strong>{{ $round['label'] }}</strong><small>{{ $round['date'] ? $round['date'].' · previsto' : ($round['stage'] === 'started' ? 'Já iniciada' : 'Após a anterior') }}</small></span></div>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <div class="ecv5-no-plan">Nenhuma sequência cadastrada para esta campanha. Configure o intervalo e a mensagem abaixo; o plano será ativado ao salvar.</div>
    @endif

    @if($canEdit && $root->status === 'completed' && ($schedule === null || $schedule['remaining_followups'] > 0))
        <section class="ecv5-settings" aria-labelledby="ecv5-settings-title">
            <div class="ecv5-section-head">
                <div><h3 id="ecv5-settings-title">Ajustar os próximos envios</h3><p>Sem botão separado de ativação. Alterou um campo? O sistema salva quando você sai dele.</p></div>
                @if($schedule && $schedule['automatic'] && in_array($schedule['status'], ['active','paused'], true))
                    <button type="button" class="ecv5-pause" wire:click="togglePause" wire:loading.attr="disabled" wire:target="togglePause">
                        {{ $schedule['status'] === 'paused' ? 'Retomar próximas rodadas' : 'Pausar próximas rodadas' }}
                    </button>
                @endif
            </div>
            <form wire:submit="saveChanges" class="ecv5-form">
                <label><span>Nome do acompanhamento</span><input type="text" wire:model="name" wire:blur="saveChanges" maxlength="150" minlength="3" required></label>
                <div class="ecv5-form-cols">
                    <label><span>Intervalo entre rodadas</span>
                        <select wire:model="delayDays" wire:change="saveChanges">
                            @foreach([3,4,5,7,10,14] as $days)<option value="{{ $days }}">{{ $days }} dias</option>@endforeach
                            @if(! in_array($delayDays, [3,4,5,7,10,14], true))<option value="{{ $delayDays }}">{{ $delayDays }} dias</option>@endif
                        </select>
                    </label>
                    <label><span>Quantidade de e-mails adicionais</span>
                        <select wire:model="maxRounds" wire:change="saveChanges">
                            <option value="1">1 · Segunda rodada</option>
                            <option value="2">2 · Até terceira rodada</option>
                            <option value="3">3 · Até quarta rodada</option>
                            <option value="4">4 · Até quinta rodada</option>
                        </select>
                    </label>
                </div>
                <div class="ecv5-form-cols">
                    <label><span>Hora de início de cada rodada (Brasília)</span>
                        <input type="time" wire:model="sendTimeLocal" step="60">
                        <small>Deixe vazio para manter a programação antiga (dias exatos a partir da hora do último envio). Informe 08:00 para iniciar às oito horas na data calculada.</small>
                    </label>
                    <label><span>Data exata do próximo disparo (opcional)</span>
                        <input type="date" wire:model="firstSendDate">
                        <small>Vale somente para a 2ª rodada. Em branco: data do último envio + intervalo em dias de calendário.</small>
                    </label>
                </div>
                <p class="ecv5-read-only">Após salvar uma programação já vencida, o agendador poderá iniciar os envios na próxima execução. Não é possível voltar ao passado para enviar às 08h de hoje.</p>
                <label><span>Assunto das próximas rodadas</span><input type="text" wire:model="subject" wire:blur="saveChanges" maxlength="180" minlength="3" required></label>
                <label><span>Mensagem das próximas rodadas</span><textarea rows="5" wire:model="body" wire:blur="saveChanges" maxlength="12000" minlength="10" required></textarea></label>
                <div class="ecv5-save-row">
                    <span>As alterações valem para próximas rodadas ainda não iniciadas. O e-mail atual de uma rodada em andamento não é modificado.</span>
                    <button type="submit" class="ecv5-save" wire:loading.attr="disabled" wire:target="saveChanges">Salvar agora</button>
                </div>
                @foreach(['name','delayDays','maxRounds','sendTimeLocal','firstSendDate','subject','body'] as $field)
                    @error($field)<p class="ecv5-error" role="alert">{{ $message }}</p>@enderror
                @endforeach
                <p class="ecv5-feedback {{ $saved ? 'is-success' : '' }}" role="status">{{ $feedback }}</p>
            </form>
        </section>
    @elseif(! $canEdit)
        <p class="ecv5-read-only">Somente gestores podem editar a programação. A visualização está disponível para consulta.</p>
    @endif
    <p class="ecv5-footer">A execução é automática quando autorizada no ambiente, o IMAP estiver atualizado e houver destinatários elegíveis. Respostas, descadastros, devoluções e envios incertos impedem novas tentativas para os contatos afetados.</p>
</div>
