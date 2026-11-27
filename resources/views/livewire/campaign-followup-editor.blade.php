<div class="ecv5" id="ecv5-cronograma" data-ec-followup-editor="v5" wire:poll.60s>
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
            <p>{{ $schedule && (int) $schedule['remaining_followups'] === 0 ? 'Todas as rodadas previstas foram iniciadas. Confira abaixo os resultados SMTP reais e o acompanhamento das devoluções.' : ($root->status === 'draft' ? 'Defina agora a quantidade, o intervalo, o horário e o texto dos próximos e-mails. Nada será enviado enquanto a campanha inicial estiver em rascunho.' : 'Após a conclusão da rodada anterior, o sistema calcula os prazos e prepara a próxima, respeitando as proteções comerciais.') }}</p>
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
                <b>{{ (int) $schedule['remaining_followups'] === 0 ? $schedule['sent_followups'] + 1 .' rodadas no total' : ($schedule['calendar_mode'] ? ($schedule['next_round'].'ª rodada · data definida pelo gestor, 08:00') : ($schedule['next_round'].'ª rodada · '.$schedule['days'].' dias')) }}</b>
                <div class="ecv5-next-info">
                    @if ((int) $schedule['remaining_followups'] === 0 && (int) $schedule['sent_followups'] > 0)
                        <div><span>Aceitos pelo SMTP</span><strong>{{ $schedule['accepted_smtp_count'] }} de {{ $schedule['latest_total_count'] }}</strong></div>
                    @else
                        <div><span>Contatos potencialmente elegíveis</span><strong>{{ $schedule['eligible_count'] === null ? 'A calcular' : number_format((int) $schedule['eligible_count'], 0, ',', '.') }}</strong></div>
                    @endif
                    <div><span>Rodadas realizadas / previstas</span><strong>{{ $schedule['sent_followups'] + 1 }} / {{ $schedule['max_followups'] + 1 }}</strong></div>
                </div>
                <small>{{ (int) $schedule['remaining_followups'] === 0 ? 'O processamento da última rodada já começou. A aceitação SMTP não garante entrega na caixa de entrada.' : 'Este é o horário previsto para INICIAR os envios, não para entregar todas as mensagens. A fila respeita janela e cotas SMTP.' }}</small>
            </div>
            <div class="ecv5-mail-preview">
                <div class="ecv5-mail-heading"><strong>Mensagem que será utilizada</strong><span>Prévia real do plano</span></div>
                <div class="ecv5-mail-subject"><span>ASSUNTO</span><strong>{{ $schedule['subject'] }}</strong></div>
                <div class="ecv5-mail-body"><span>CORPO DO E-MAIL</span><p>{{ \Illuminate\Support\Str::limit($schedule['body'], 230) }}</p></div>
                <details><summary>Ver a mensagem inteira</summary><div>{{ $schedule['body'] }}</div></details>
                <small>Remetente: @if (\App\Services\Campaigns\CampaignInstitutionalSignature::usesDedicatedPreview($root)){{ \App\Services\Campaigns\CampaignInstitutionalSignature::previewFrom($root) }} — a mesma caixa do envio original, quando atribuída.@else{{ $schedule['from_address'] ?: 'Não configurado' }}@endif · A personalização é aplicada por contato no envio.</small>
                @include('campaigns.institutional-signature-preview', ['signatureCampaign' => $root])
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
                    <div class="ecv5-step {{ $root->status === 'completed' ? 'is-started' : 'is-future' }}"><b>1</b><span><strong>Rodada inicial</strong><small>{{ $root->status === 'completed' ? 'Concluída' : ($root->status === 'draft' ? 'Rascunho — ainda não enviado' : 'Aguardando conclusão') }}</small></span></div>
                    @foreach($schedule['rounds'] as $round)
                        <div class="ecv5-step is-{{ $round['stage'] }}"><b>{{ $round['number'] }}</b><span><strong>{{ $round['label'] }}</strong><small>{{ $round['date'] ? $round['date'].' · previsto' : ($round['stage'] === 'started' ? 'Já iniciada' : 'Após a anterior') }}</small></span></div>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <div class="ecv5-no-plan">Nenhuma sequência cadastrada para esta campanha. Você pode planejar os próximos envios antes de disparar a primeira mensagem.</div>
    @endif

    @if($canEdit && in_array($root->status, ['draft', 'completed'], true) && ($schedule === null || $schedule['remaining_followups'] > 0))
        <section class="ecv5-settings" aria-labelledby="ecv5-settings-title">
            <div class="ecv5-section-head">
                <div><h3 id="ecv5-settings-title">{{ $root->status === 'draft' ? 'Planejar próximos e-mails antes do envio' : 'Ajustar os próximos envios' }}</h3><p>Revise todas as opções e clique em salvar para autorizar as futuras rodadas. Alterar um campo não dispara e-mails.</p></div>
                @if($schedule && $schedule['automatic'] && in_array($schedule['status'], ['active','paused'], true))
                    <form method="POST" action="{{ route('campaigns.followup.pause', ['campaign' => $root->id]) }}">
                        @csrf
                        <input type="hidden" name="paused" value="{{ $schedule['status'] === 'paused' ? '0' : '1' }}">
                        <button type="submit" class="ecv5-pause">{{ $schedule['status'] === 'paused' ? 'Retomar próximas rodadas' : 'Pausar próximas rodadas' }}</button>
                    </form>
                @endif
            </div>
            {{-- EC_CALENDAR_FOLLOWUP_V1: formulario HTTP real; nao depende do Livewire. --}}
            @if(session('followup_calendar_ok'))
                <p class="ecv5-feedback is-success" role="status">{{ session('followup_calendar_ok') }}</p>
            @endif
            <form method="POST" action="{{ route('campaigns.followup.calendar', ['campaign' => $root->id]) }}" class="ecv5-form">
                @csrf
                <label><span>Nome do acompanhamento</span><input type="text" name="name" value="{{ old('name', $name) }}" maxlength="150" minlength="3" required></label>
                <div class="ecv5-form-cols">
                    <label><span>Total de rodadas (incluindo a primeira)</span>
                        <select name="total_rounds" required>
                            @foreach([2,3,4,5] as $total)
                                @if($total >= (int) (($schedule['sent_followups'] ?? 0) + 1))
                                    <option value="{{ $total }}" @selected((int) old('total_rounds', $schedule !== null ? (int) $schedule['max_followups'] + 1 : 2) === $total)>{{ $total }} rodadas no total · {{ $total - 1 }} retorno(s)</option>
                                @endif
                            @endforeach
                        </select>
                        <small>Ex.: 2 rodadas = primeiro e-mail + somente 1 retorno. Para terminar na segunda, selecione 2.</small>
                    </label>
                    <div class="ecv5-read-only" style="padding:12px 15px;border:1px solid #b9e0d7;border-radius:8px;background:#eef9f5">
                        <strong>Horário fixo: 08:00 (Brasília)</strong>
                        <p>O sistema inicia no dia escolhido, somente entre 08:00 e 08:15. Se perder a janela, não dispara atrasado: exige nova data.</p>
                    </div>
                </div>
                <div class="ecv5-form-cols">
                    @foreach([2,3,4,5] as $round)
                        <label><span>Data da {{ $round }}ª rodada {{ (int) ($schedule['sent_followups'] ?? 0) >= $round - 1 ? '· já iniciada' : '' }}</span>
                            <input type="date" name="round_dates[{{ $round }}]" value="{{ old('round_dates.'.$round, $roundDates[$round] ?? '') }}" {{ (int) ($schedule['sent_followups'] ?? 0) >= $round - 1 ? 'readonly' : '' }}>
                            <small>{{ (int) ($schedule['sent_followups'] ?? 0) >= $round - 1 ? 'Histórico preservado. Não será reenviada.' : 'Preencha se incluir esta rodada no total acima.' }}</small>
                            @error('round_dates.'.$round)<small class="ecv5-error">{{ $message }}</small>@enderror
                        </label>
                    @endforeach
                </div>
                <p class="ecv5-read-only">A campanha inicial conta como 1ª rodada. Cada retorno precisa de uma data selecionada. Se o notebook ou IMAP estiver offline às 08h, não haverá disparo automático fora da janela; será necessário reagendar.</p>
                <label><span>Assunto dos próximos retornos</span><input type="text" name="subject" value="{{ old('subject', $subject) }}" maxlength="180" minlength="3" required onfocus="this.form.dataset.ecV25Field='subject'"></label>
                <label><span>Mensagem dos próximos retornos</span><textarea rows="5" name="body" maxlength="12000" minlength="10" required onfocus="this.form.dataset.ecV25Field='body'">{{ old('body', $body) }}</textarea></label>
                @include('campaigns.message-variables-v25')
                <div class="ecv5-save-row">
                    <span>Salvar não envia e-mails. Respostas comerciais, descadastros, devoluções e SMTP incerto continuam bloqueando novos contatos.</span>
                    <button type="submit" class="ecv5-save">Salvar datas e quantidade de rodadas</button>
                </div>
                @foreach(['name','total_rounds','round_dates','subject','body','flow'] as $field)
                    @error($field)<p class="ecv5-error" role="alert">{{ $message }}</p>@enderror
                @endforeach
                {{-- EC_CALENDAR_FOLLOWUP_V1_2: preserva feedback do saveChanges() legado
                     chamado por Livewire e dos testes anteriores. O POST HTTP
                     independente continua mostrando seu proprio flash acima. --}}
                @if ($feedback !== '')
                    <p class="ecv5-feedback {{ $saved ? 'is-success' : '' }}" role="status">{{ $feedback }}</p>
                @endif
            </form>
        </section>
    @elseif(! $canEdit)
        <p class="ecv5-read-only">Somente gestores podem editar a programação. A visualização está disponível para consulta.</p>
    @endif
    {{-- EC_FOLLOWUP_EXTEND_V2: prolongar uma sequência concluída requer nova autorização. --}}
    {{-- EC_FOLLOWUP_CANCEL_NEXT_V3: cancelamento por POST sem mexer nos envios iniciados. --}}
    @include('campaigns.followup-extend')
    @if(session('followup_cancel_ok'))
        <p class="ecv5-feedback is-success" role="status">{{ session('followup_cancel_ok') }}</p>
    @endif
    @include('campaigns.followup-cancel-next')
    <p class="ecv5-footer">O envio automático exige data explícita e ocorre às 08:00 na janela autorizada, com IMAP saudável e destinatários elegíveis. Rodadas anteriores permanecem no histórico. Tentativas incertas não são reenviadas.</p>
</div>
