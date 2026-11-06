{{-- EC_FOLLOWUP_VISIBILITY_V4 — nao esconder plano manual nem prometer disparo. --}}
@php
    $ec80AllPlans = app(\App\Services\Campaigns\CampaignFollowupScheduleViewV4::class)->home(8);
    $ecFv4Home = array_values(array_filter($ec80AllPlans,
        static fn (array $row): bool => (int) ($row['remaining_followups'] ?? 0) > 0));
@endphp
<style>
    [data-ec-followup-home="v4"] .ecv6-home-result { margin-top: 12px; padding: 13px 15px; border: 1px solid #cadfdb; border-radius: 12px; background: #f8fbfa; color: #163d4c; }
    [data-ec-followup-home="v4"] .ecv6-home-result.is-complete { border-color: #9cdbcb; background: #eaf8f3; }
    [data-ec-followup-home="v4"] .ecv6-home-result > strong { display: block; font-size: 13px; margin-bottom: 8px; color: #075d58; }
    [data-ec-followup-home="v4"] .ecv6-result-line { display: flex; flex-wrap: wrap; gap: 6px 13px; font-size: 12px; line-height: 1.65; }
    [data-ec-followup-home="v4"] .ecv6-result-line b { font-size: 15px; color: #075d58; }
    [data-ec-followup-home="v4"] .ecv6-home-result > small { display: block; margin-top: 5px; font-size: 11px; color: #536e78; }
</style>
<section class="ecfv3 ecfv4-home" data-ec-followup-home="v4" aria-labelledby="ecfv4-home-title" wire:poll.60s>
    <header class="ecfv4-home-head">
        <div>
            <span class="ecfv4-overline"><span class="ecfv4-live-dot" aria-hidden="true"></span> ACOMPANHAMENTO DE E-MAILS</span>
            <h2 id="ecfv4-home-title">Próximas rodadas programadas</h2>
            <p>Somente retornos que ainda serão executados. As rodadas já realizadas ficam agrupadas em Minhas campanhas, acima.</p>
        </div>
        <span class="ecfv4-total">{{ count($ecFv4Home) }} {{ count($ecFv4Home) === 1 ? 'sequência encontrada' : 'sequências encontradas' }}</span>
    </header>

    @forelse ($ecFv4Home as $ecFv4Row)
        @php
            $ecV6Finished = (int) $ecFv4Row['remaining_followups'] === 0;
            $ecV6Accepted = (int) $ecFv4Row['accepted_smtp_count'];
            $ecV6Total = (int) $ecFv4Row['latest_total_count'];
            $ecV6Pending = (int) $ecFv4Row['pending_count'];
            $ecV6Uncertain = (int) $ecFv4Row['uncertain_count'];
            $ecV6Bounces = (int) $ecFv4Row['bounces_count'];
            $ecV6FullyAccepted = $ecV6Finished && $ecV6Total > 0
                && $ecV6Accepted === $ecV6Total
                && $ecV6Pending === 0 && $ecV6Uncertain === 0 && $ecV6Bounces === 0;
        @endphp
        <article class="ecfv4-home-row" wire:key="ecfv4-home-{{ $ecFv4Row['id'] }}">
            <div class="ecfv4-home-date">
                <span>{{ $ecV6Finished ? 'ÚLTIMA RODADA' : 'PREVISÃO DO PRÓXIMO CONTATO' }}</span>
                <strong>{{ $ecV6Finished ? $ecFv4Row['last_round_number'].'ª rodada '.($ecV6Pending > 0 ? 'em envio' : 'realizada') : ($ecFv4Row['due_datetime'] ?: 'A definir') }}</strong>
                <small>{{ $ecV6Finished ? $ecV6Accepted.' de '.$ecV6Total.' aceitos pelo SMTP' : ($ecFv4Row['automatic'] ? 'Previsão da rodada automática' : 'Previsão de retorno (não agendado)') }}</small>
                <div class="ecfv4-home-round">
                    @if ($ecV6Finished)
                        {{ $ecFv4Row['sent_followups'] }}/{{ $ecFv4Row['max_followups'] }} follow-up(s) iniciados
                    @else
                        {{ $ecFv4Row['next_round'] }}ª rodada · +{{ $ecFv4Row['days'] }} dias
                    @endif
                </div>
            </div>
            <div class="ecfv4-home-content">
                <div class="ecfv4-home-titlerow">
                    <strong>{{ $ecFv4Row['root_name'] }} · {{ $ecFv4Row['name'] }}</strong>
                    <span class="ecfv4-pill ecfv4-pill--{{ $ecFv4Row['state'] }}">{{ $ecFv4Row['state_label'] }}</span>
                </div>
                <div class="ecfv4-home-subject"><span>ASSUNTO</span><strong>{{ $ecFv4Row['subject'] }}</strong></div>
                <div class="ecfv4-home-excerpt"><span>MENSAGEM</span><p>{{ \Illuminate\Support\Str::limit($ecFv4Row['body'], 165) }}</p></div>
                <p class="ecfv4-home-meta">
                    {{ $ecFv4Row['sent_followups'] }} de {{ $ecFv4Row['max_followups'] }} retorno(s) iniciado(s)
                    @if ($ecFv4Row['eligible_count'] !== null) · {{ $ecFv4Row['eligible_count'] }} destinatário(s) potencialmente elegível(is) @endif
                </p>
                <div class="ecv6-home-result {{ $ecV6FullyAccepted ? 'is-complete' : '' }}" role="status" aria-label="Resultado real da última rodada">
                    <strong>{{ $ecV6Finished ? $ecFv4Row['last_round_number'].'ª rodada — resultado real' : 'Última rodada registrada' }}</strong>
                    <div class="ecv6-result-line">
                        <span><b>{{ $ecV6Accepted }}/{{ $ecV6Total }}</b> aceitos SMTP</span>
                        <span>{{ $ecFv4Row['bounces_count'] }} devoluções</span>
                        <span>{{ $ecV6Uncertain }} incertos</span>
                        <span>{{ $ecFv4Row['replies_count'] }} respostas</span>
                        <span>{{ $ecV6Pending }} pendentes</span>
                    </div>
                    @if ($ecFv4Row['latest_smtp_at'])
                        <small>Última aceitação SMTP: {{ $ecFv4Row['latest_smtp_at'] }} (Brasília).</small>
                    @endif
                    <small>Aceitação SMTP não comprova entrega na caixa de entrada.</small>
                </div>
                <p class="ecfv4-home-reason">{{ $ecFv4Row['state_reason'] }}</p>
            </div>
            <button type="button" class="ecfv4-home-action" wire:click="openCampaign({{ $ecV6Finished && (int) $ecFv4Row['sent_followups'] > 0 ? (int) $ecFv4Row['latest_campaign_id'] : (int) $ecFv4Row['root_id'] }})" wire:loading.attr="disabled" wire:target="openCampaign">
                {{ $ecV6Finished && (int) $ecFv4Row['sent_followups'] > 0 ? 'Abrir '.$ecFv4Row['last_round_number'].'ª rodada' : 'Ver mensagem e cronograma' }} <span aria-hidden="true">→</span>
            </button>
        </article>
    @empty
        <div class="ecfv4-home-empty">
            <strong>Nenhuma rodada aguardando execução</strong>
            <p>As rodadas anteriores aparecem dentro da campanha original. Para agendar outra, abra a campanha e ajuste a continuidade comercial.</p>
        </div>
    @endforelse
    <p class="ecfv4-home-footnote">As datas são previsões, não garantias de entrega. Um plano marcado “Apenas planejado” não realiza envios automáticos.</p>
</section>
