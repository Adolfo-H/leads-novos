<div class="ecv5" data-ec-followup-editor="v5" wire:poll.60s>
    <header class="ecv5-header">
        <div>
            <span class="ecv5-eyebrow">CONTINUIDADE COMERCIAL · AUTOMAÇÃO</span>
            <h2>Próximos e-mails, no automático</h2>
            @if($schedule)
                <p><strong>Acompanhamento:</strong> {{ $schedule['name'] }}</p>
            @endif
            <p>A campanha termina, o prazo é contado e o sistema prepara a próxima rodada sozinho. Veja ou altere a programação aqui.</p>
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
                <span>PRÓXIMO E-MAIL PREVISTO</span>
                <strong>{{ $schedule['remaining_followups'] > 0 ? ($schedule['due_date'] ?: 'Após conclusão') : 'Sequência encerrada' }}</strong>
                <b>{{ $schedule['next_round'] }}ª rodada · {{ $schedule['days'] }} dias após o último envio concluído</b>
                <div class="ecv5-next-info">
                    <div><span>Contatos potencialmente elegíveis</span><strong>{{ $schedule['eligible_count'] === null ? 'A calcular' : number_format((int)$schedule['eligible_count'], 0, ',', '.') }}</strong></div>
                    <div><span>Retornos iniciados</span><strong>{{ $schedule['sent_followups'] }} / {{ $schedule['max_followups'] }}</strong></div>
                </div>
                <small>Previsão, não horário garantido. A fila respeita a janela e as cotas SMTP.</small>
            </div>
            <div class="ecv5-mail-preview">
                <div class="ecv5-mail-heading"><strong>Mensagem que será utilizada</strong><span>Prévia real do plano</span></div>
                <div class="ecv5-mail-subject"><span>ASSUNTO</span><strong>{{ $schedule['subject'] }}</strong></div>
                <div class="ecv5-mail-body"><span>CORPO DO E-MAIL</span><p>{{ \Illuminate\Support\Str::limit($schedule['body'], 230) }}</p></div>
                <details><summary>Ver a mensagem inteira</summary><div>{{ $schedule['body'] }}</div></details>
                <small>Remetente: {{ $schedule['from_address'] ?: 'Não configurado' }} · A personalização é aplicada por contato no envio.</small>
            </div>
        </div>
        <div class="ecv5-state-line {{ in_array($schedule['state'], ['blocked','manual','paused','no_recipients'], true) ? 'is-attention' : '' }}">
            <span class="ecv5-state-icon" aria-hidden="true">{{ in_array($schedule['state'], ['scheduled','ready'], true) ? '✓' : 'i' }}</span>
            <div><strong>Estado atual</strong><p>{{ $schedule['state_reason'] }}</p></div>
        </div>
        @if($schedule['automatic'] && $schedule['remaining_followups'] > 0)
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
                <label><span>Assunto das próximas rodadas</span><input type="text" wire:model="subject" wire:blur="saveChanges" maxlength="180" minlength="3" required></label>
                <label><span>Mensagem das próximas rodadas</span><textarea rows="5" wire:model="body" wire:blur="saveChanges" maxlength="12000" minlength="10" required></textarea></label>
                <div class="ecv5-save-row">
                    <span>As alterações valem para próximas rodadas ainda não iniciadas. O e-mail atual de uma rodada em andamento não é modificado.</span>
                    <button type="submit" class="ecv5-save" wire:loading.attr="disabled" wire:target="saveChanges">Salvar agora</button>
                </div>
                @foreach(['name','delayDays','maxRounds','subject','body'] as $field)
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
