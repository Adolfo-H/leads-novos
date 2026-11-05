{{-- EC_FOLLOWUP_VISIBILITY_V4 — exibe previsao e email para planos manuais e automaticos. --}}
@php
    $ecFv4Plan = app(\App\Services\Campaigns\CampaignFollowupScheduleViewV4::class)->forCampaign($selected);
@endphp
@if ($ecFv4Plan !== null)
<section class="ecfv3 ecfv4-detail" data-ec-followup-detail="v4" aria-labelledby="ecfv4-detail-title">
    <header class="ecfv4-detail-header">
        <div>
            <span class="ecfv4-overline"><span class="ecfv4-live-dot" aria-hidden="true"></span> CONTINUIDADE COMERCIAL · PRÓXIMO E-MAIL</span>
            <h2 id="ecfv4-detail-title">{{ $ecFv4Plan['remaining_followups'] > 0 ? $ecFv4Plan['next_round'].'ª rodada — '.$ecFv4Plan['name'] : 'Sequência concluída' }}</h2>
            <p>Intervalo: <strong>{{ $ecFv4Plan['days'] }} {{ $ecFv4Plan['days'] === 1 ? 'dia' : 'dias' }}</strong> após a rodada anterior · {{ $ecFv4Plan['sent_followups'] }} de {{ $ecFv4Plan['max_followups'] }} retornos iniciados</p>
        </div>
        <span class="ecfv4-pill ecfv4-pill--{{ $ecFv4Plan['state'] }}">{{ $ecFv4Plan['state_label'] }}</span>
    </header>

    @unless ($ecFv4Plan['automatic'])
        <div class="ecfv4-not-automatic" role="status">
            <strong>Este e-mail ainda NÃO está agendado automaticamente.</strong>
            <span>Você salvou assunto, mensagem e prazo como planejamento. Para o sistema enviar sozinho, é necessário configurar e autorizar uma sequência automática na área “Configurar envios automáticos após a campanha” abaixo.</span>
        </div>
    @endunless

    <div class="ecfv4-detail-grid">
        <div class="ecfv4-date-panel">
            <span>PRÓXIMO CONTATO · DATA PREVISTA</span>
            <strong>{{ $ecFv4Plan['remaining_followups'] > 0 ? ($ecFv4Plan['due_date'] ?: 'A definir') : 'Concluído' }}</strong>
            <div class="ecfv4-date-separator"></div>
            <div><b>{{ $ecFv4Plan['next_round'] }}ª rodada</b><small>Intervalo de {{ $ecFv4Plan['days'] }} dias</small></div>
            @if ($ecFv4Plan['eligible_count'] !== null && $ecFv4Plan['remaining_followups'] > 0)
                <div><b>{{ number_format($ecFv4Plan['eligible_count'], 0, ',', '.') }} contatos</b><small>Potencialmente elegíveis agora</small></div>
            @endif
            <p>{{ $ecFv4Plan['due_date'] ? 'Previsão calculada após a conclusão e o último aceite SMTP da rodada anterior.' : 'A data só poderá ser calculada quando a rodada anterior terminar.' }}</p>
        </div>
        <div class="ecfv4-message-panel">
            <div class="ecfv4-message-top"><strong>{{ $ecFv4Plan['automatic'] ? 'E-mail configurado para a próxima rodada' : 'E-mail planejado para a próxima rodada' }}</strong><span>Mensagem salva</span></div>
            <div class="ecfv4-message-field"><span>ASSUNTO DO E-MAIL</span><strong>{{ $ecFv4Plan['subject'] }}</strong></div>
            <div class="ecfv4-message-field"><span>TEXTO DA MENSAGEM</span><p>{{ \Illuminate\Support\Str::limit($ecFv4Plan['body'], 320) }}</p></div>
            <details class="ecfv4-message-expand">
                <summary>Ver mensagem completa</summary>
                <div>{{ $ecFv4Plan['body'] }}</div>
            </details>
            <small>Remetente: {{ $ecFv4Plan['from_address'] ?: 'Não configurado' }}. Variáveis serão preenchidas por destinatário no envio.</small>
        </div>
    </div>
    <div class="ecfv4-detail-reason" role="status"><strong>SITUAÇÃO DA PRÓXIMA RODADA</strong><span>{{ $ecFv4Plan['state_reason'] }}</span></div>
    <div class="ecfv4-rounds-section">
        <strong>Histórico e próximas rodadas</strong>
        <ol class="ecfv4-rounds" aria-label="Histórico de rodadas da sequência">
            <li class="ecfv4-round is-started"><b>1</b><div><strong>1ª rodada</strong><small>Campanha inicial</small></div></li>
            @foreach ($ecFv4Plan['rounds'] as $ecFv4Round)
                <li class="ecfv4-round is-{{ $ecFv4Round['stage'] }}"><b>{{ $ecFv4Round['number'] }}</b><div><strong>{{ $ecFv4Round['label'] }}</strong><small>{{ $ecFv4Round['stage'] === 'started' ? 'Envio iniciado' : ($ecFv4Round['date'] ? $ecFv4Round['date'].' · previsto' : '+'.$ecFv4Plan['days'].' dias após a anterior') }}</small></div></li>
            @endforeach
        </ol>
    </div>
    <p class="ecfv4-detail-footnote">O envio real depende da autorização automática, IMAP saudável, descadastro, agendador, cotas SMTP e da elegibilidade de cada endereço. O painel não envia mensagens.</p>
</section>
@else
<section class="ecfv3 ecfv4-detail ecfv4-detail--empty" data-ec-followup-detail="v4">
    <span class="ecfv4-overline">PRÓXIMA RODADA</span>
    <h2>Esta campanha ainda não possui um e-mail de continuidade configurado.</h2>
    <p>Abra “Configurar envios automáticos após a campanha” no histórico abaixo para definir o prazo, o assunto e o texto.</p>
</section>
@endif
