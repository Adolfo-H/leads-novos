{{-- EC_FOLLOWUP_VISIBILITY_V3 — indicadores reais, nenhuma ação de envio. --}}
@php
    $ecFv3Home = app(\App\Services\Campaigns\CampaignFollowupScheduleView::class)->home(4);
@endphp
@if ($ecFv3Home !== [])
<section class="ecfv3 ecfv3-home" aria-labelledby="ecfv3-home-title" data-ec-followup-home="v3">
    <header class="ecfv3-home__head">
        <div>
            <span class="ecfv3-kicker"><span class="ecfv3-kicker-dot" aria-hidden="true"></span> CONTINUIDADE COMERCIAL</span>
            <h2 id="ecfv3-home-title">Próximos e-mails programados</h2>
            <p>Veja quando o próximo contato está previsto e qual mensagem será enviada, sem procurar na configuração.</p>
        </div>
        <span class="ecfv3-count">{{ count($ecFv3Home) }} {{ count($ecFv3Home) === 1 ? 'sequência' : 'sequências' }}</span>
    </header>
    <div class="ecfv3-home__list">
        @foreach ($ecFv3Home as $ecFv3Plan)
            <article class="ecfv3-home__item" wire:key="ecfv3-home-{{ $ecFv3Plan['id'] }}">
                <div class="ecfv3-home__date">
                    <small>PRÓXIMO CONTATO</small>
                    <strong>{{ $ecFv3Plan['due_date'] ?? 'A definir' }}</strong>
                    <span>{{ $ecFv3Plan['due_date'] ? 'Data prevista' : 'Após concluir o envio atual' }}</span>
                </div>
                <div class="ecfv3-home__info">
                    <div class="ecfv3-home__title">
                        <strong>{{ $ecFv3Plan['next_round'] }}ª rodada · {{ $ecFv3Plan['root_name'] }}</strong>
                        <span class="ecfv3-state ecfv3-state--{{ $ecFv3Plan['state'] }}">{{ $ecFv3Plan['state_label'] }}</span>
                    </div>
                    <p><span>Assunto:</span> {{ $ecFv3Plan['subject'] }}</p>
                    <small>A cada {{ $ecFv3Plan['days'] }} {{ $ecFv3Plan['days'] === 1 ? 'dia' : 'dias' }} após o término da rodada anterior
                        · {{ $ecFv3Plan['sent_followups'] }} de {{ $ecFv3Plan['max_followups'] }} retornos iniciados
                        @if ($ecFv3Plan['eligible_count'] !== null)
                            · {{ $ecFv3Plan['eligible_count'] }} {{ $ecFv3Plan['eligible_count'] === 1 ? 'contato elegível' : 'contatos elegíveis' }} no momento
                        @endif
                    </small>
                </div>
                <button type="button" class="ecfv3-home__action" wire:click="openCampaign({{ $ecFv3Plan['root_id'] }})" wire:loading.attr="disabled" wire:target="openCampaign">Ver programação e mensagem <span aria-hidden="true">→</span></button>
            </article>
        @endforeach
    </div>
    <p class="ecfv3-home__note">As datas são previsões. O envio real depende de SMTP, sincronização IMAP, descadastro, agendador e limites de envio. O painel não dispara mensagens.</p>
</section>
@endif
