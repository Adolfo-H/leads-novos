{{-- EC_FOLLOWUP_VISIBILITY_V3 — a programação aparece antes das métricas técnicas. --}}
@php
    $ecFv3Plan = app(\App\Services\Campaigns\CampaignFollowupScheduleView::class)->forCampaign($selected);
@endphp
@if ($ecFv3Plan !== null)
<section class="ecfv3 ecfv3-detail" aria-labelledby="ecfv3-detail-title" data-ec-followup-detail="v3">
    <header class="ecfv3-detail__head">
        <div>
            <span class="ecfv3-kicker"><span class="ecfv3-kicker-dot" aria-hidden="true"></span> PRÓXIMO CONTATO COMERCIAL</span>
            <h2 id="ecfv3-detail-title">
                @if ($ecFv3Plan['remaining_followups'] > 0)
                    {{ $ecFv3Plan['next_round'] }}ª rodada — e-mail de continuidade
                @else
                    Sequência de e-mails finalizada
                @endif
            </h2>
            <p>Configuração: <strong>{{ $ecFv3Plan['name'] }}</strong> · {{ $ecFv3Plan['days'] }} {{ $ecFv3Plan['days'] === 1 ? 'dia' : 'dias' }} entre rodadas.</p>
        </div>
        <span class="ecfv3-state ecfv3-state--{{ $ecFv3Plan['state'] }}">{{ $ecFv3Plan['state_label'] }}</span>
    </header>

    <div class="ecfv3-detail__main">
        <div class="ecfv3-detail__date">
            <span class="ecfv3-field-label">DATA PREVISTA DA PRÓXIMA RODADA</span>
            <strong>{{ $ecFv3Plan['remaining_followups'] > 0 ? ($ecFv3Plan['due_date'] ?? 'Aguardando') : 'Concluída' }}</strong>
            <p>
                @if ($ecFv3Plan['remaining_followups'] <= 0)
                    Não há outra rodada pendente nesta sequência.
                @elseif ($ecFv3Plan['due_date'])
                    A partir desta data. O horário exato depende do agendador e dos limites SMTP.
                @else
                    A contagem começa quando a rodada atual for concluída e seus envios forem aceitos pelo SMTP.
                @endif
            </p>
            @if ($ecFv3Plan['eligible_count'] !== null && $ecFv3Plan['remaining_followups'] > 0)
                <div class="ecfv3-detail__audience">
                    <strong>{{ number_format($ecFv3Plan['eligible_count'], 0, ',', '.') }}</strong>
                    <span>{{ $ecFv3Plan['eligible_count'] === 1 ? 'destinatário elegível agora' : 'destinatários elegíveis agora' }}</span>
                </div>
            @endif
        </div>
        <div class="ecfv3-detail__message">
            <div class="ecfv3-detail__message-head">
                <strong>Este é o e-mail configurado</strong>
                <span>Enviado individualmente a cada destinatário elegível</span>
            </div>
            <div class="ecfv3-detail__subject"><span>ASSUNTO</span><strong>{{ $ecFv3Plan['subject'] }}</strong></div>
            <div class="ecfv3-detail__preview">{{ \Illuminate\Support\Str::limit($ecFv3Plan['body'], 220) }}</div>
            <details class="ecfv3-detail__full">
                <summary>Ver texto completo do e-mail <span aria-hidden="true">⌄</span></summary>
                <div class="ecfv3-detail__full-body">{{ $ecFv3Plan['body'] }}</div>
            </details>
            <small>Remetente: {{ $ecFv3Plan['from_address'] ?: 'Não configurado' }}. Variáveis de personalização são preenchidas no envio.</small>
        </div>
    </div>

    <div class="ecfv3-detail__status" role="status"><span aria-hidden="true">ⓘ</span><span>{{ $ecFv3Plan['state_reason'] }}</span></div>

    <div class="ecfv3-detail__bottom">
        <div class="ecfv3-detail__round-label"><strong>Seu cronograma de contatos</strong><small>{{ $ecFv3Plan['sent_followups'] }} de {{ $ecFv3Plan['max_followups'] }} follow-ups iniciados</small></div>
        <ol class="ecfv3-detail__rounds" aria-label="Rodadas previstas e anteriores">
            <li class="ecfv3-round is-started"><span class="ecfv3-round__index">1</span><div><strong>1ª rodada</strong><small>Campanha inicial</small></div></li>
            @foreach ($ecFv3Plan['rounds'] as $ecFv3Round)
                <li class="ecfv3-round is-{{ $ecFv3Round['stage'] }}">
                    <span class="ecfv3-round__index">{{ $ecFv3Round['number'] }}</span>
                    <div><strong>{{ $ecFv3Round['label'] }}</strong><small>{{ $ecFv3Round['stage'] === 'started' ? 'Envio iniciado' : ($ecFv3Round['date'] ? $ecFv3Round['date'].' · previsão' : '+'.$ecFv3Plan['days'].' dias após a anterior') }}</small></div>
                </li>
            @endforeach
        </ol>
    </div>
    <p class="ecfv3-detail__footnote">Se houver resposta, devolução vinculada, descadastro ou resultado incerto, o contato deixa de ser elegível para novos envios. A elegibilidade exibida é uma estimativa atual, não uma reserva.</p>
</section>
@endif
