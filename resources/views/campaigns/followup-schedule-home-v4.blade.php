{{-- EC_FOLLOWUP_VISIBILITY_V4 — nao esconder plano manual nem prometer disparo. --}}
@php
    $ecFv4Home = app(\App\Services\Campaigns\CampaignFollowupScheduleViewV4::class)->home(5);
@endphp
<section class="ecfv3 ecfv4-home" data-ec-followup-home="v4" aria-labelledby="ecfv4-home-title">
    <header class="ecfv4-home-head">
        <div>
            <span class="ecfv4-overline"><span class="ecfv4-live-dot" aria-hidden="true"></span> ACOMPANHAMENTO DE E-MAILS</span>
            <h2 id="ecfv4-home-title">Próximos contatos comerciais</h2>
            <p>Veja a próxima rodada, o dia previsto e a mensagem salva. Sem entrar nas configurações.</p>
        </div>
        <span class="ecfv4-total">{{ count($ecFv4Home) }} {{ count($ecFv4Home) === 1 ? 'sequência encontrada' : 'sequências encontradas' }}</span>
    </header>

    @forelse ($ecFv4Home as $ecFv4Row)
        <article class="ecfv4-home-row" wire:key="ecfv4-home-{{ $ecFv4Row['id'] }}">
            <div class="ecfv4-home-date">
                <span>PREVISÃO DO PRÓXIMO CONTATO</span>
                <strong>{{ $ecFv4Row['due_date'] ?: 'A definir' }}</strong>
                <small>{{ $ecFv4Row['automatic'] ? 'Previsão da rodada automática' : 'Previsão de retorno (não agendado)' }}</small>
                <div class="ecfv4-home-round">{{ $ecFv4Row['next_round'] }}ª rodada · +{{ $ecFv4Row['days'] }} dias</div>
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
                <p class="ecfv4-home-reason">{{ $ecFv4Row['state_reason'] }}</p>
            </div>
            <button type="button" class="ecfv4-home-action" wire:click="openCampaign({{ $ecFv4Row['root_id'] }})" wire:loading.attr="disabled" wire:target="openCampaign">
                Ver mensagem e cronograma <span aria-hidden="true">→</span>
            </button>
        </article>
    @empty
        <div class="ecfv4-home-empty">
            <strong>Nenhuma continuidade comercial configurada</strong>
            <p>Para definir o próximo e-mail, abra uma campanha concluída e use “Configurar envios automáticos após a campanha”. O planejamento e a previsão aparecerão aqui.</p>
        </div>
    @endforelse
    <p class="ecfv4-home-footnote">As datas são previsões, não garantias de entrega. Um plano marcado “Apenas planejado” não realiza envios automáticos.</p>
</section>
