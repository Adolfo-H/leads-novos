{{-- EC_INBOUND_V22_CAMPAIGN: mesma identidade visual na campanha individual. --}}
@include('campaigns.inbound-notices-style')
@php
    $ecInboundAvailable = \Illuminate\Support\Facades\Schema::hasTable('email_campaign_inbound_notices');
    $ecInboundQuery = $ecInboundAvailable
        ? \App\Services\Campaigns\CampaignInboundNoticeFilter::query()
            ->where('email_campaign_id', (int) $selected->id)
        : null;
    $ecInboundCount = $ecInboundQuery !== null ? (clone $ecInboundQuery)->count() : 0;
    $ecInboundRows = $ecInboundQuery !== null
        ? (clone $ecInboundQuery)->orderByDesc('received_at')->limit(100)->get()
        : collect();
    $ecInboundLabels = [
        'automatic' => 'Confirmação automática',
        'auto_reply' => 'Resposta automática',
        'absence' => 'Férias ou ausência',
        'address_change' => 'E-mail alterado',
        'routing' => 'Redirecionamento',
        'reply_needs_review' => 'Possível resposta humana',
    ];
    $ecInboundMatches = [
        'reference_exact' => 'Vínculo por identificador e remetente',
        'reference_mismatch' => 'Remetente diferente — conferir',
        'sender_only' => 'Mesmo remetente — revisar no Outlook',
    ];
@endphp
<section class="ec-v22" aria-label="Retornos automáticos e para revisão">
    <header class="ec-v22-head">
        <div><p class="ec-v22-overline">Campanha selecionada · respostas</p><h3 class="ec-v22-title">Retornos úteis e mensagens para revisão</h3><p class="ec-v22-description">Avisos de férias, mudança de contato e possíveis respostas humanas. As falhas de entrega continuam em Devoluções.</p></div>
        <div class="ec-v22-count"><strong>{{ number_format((int) $ecInboundCount, 0, ',', '.') }}</strong> retornos</div>
    </header>
    <div class="ec-v22-hint"><span class="ec-v22-hint-mark" aria-hidden="true">i</span><span>Para transformar uma possível resposta em resposta comercial, confira a mensagem no Outlook e utilize <strong>Campanhas → Respostas → Retornos úteis</strong>.</span></div>
    @if ($ecInboundRows->isEmpty())
        <div class="ec-v22-empty"><strong>Nenhum aviso útil nesta campanha</strong><p>Os e-mails de devolução e os retornos sem vínculo seguro não entram nesta contagem.</p></div>
    @else
    <div class="ec-v22-feed">
        @foreach ($ecInboundRows as $ecNotice)
            @php
                $ecV22Type = match ($ecNotice->notice_type) {
                    'reply_needs_review' => 'review',
                    'address_change' => 'address',
                    default => 'auto',
                };
            @endphp
            <article class="ec-v22-item" wire:key="ec-v22-campaign-{{ $ecNotice->id }}">
                <div class="ec-v22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $ecNotice->from_email, 0, 1)) }}</div>
                <div class="ec-v22-body">
                    <div class="ec-v22-meta"><span class="ec-v22-pill ec-v22-pill--{{ $ecV22Type }}">{{ $ecInboundLabels[$ecNotice->notice_type] ?? 'Retorno para revisão' }}</span><span class="ec-v22-timestamp">{{ \App\Support\CampaignLocalTime::format($ecNotice->received_at, 'Data indisponível') }}</span></div>
                    <h4 class="ec-v22-subject">{{ $ecNotice->subject ?: 'Sem assunto' }}</h4>
                    <p class="ec-v22-email">De: {{ $ecNotice->from_email }}</p>{{-- EC_REPLY_MAILBOX_V2_3 --}}<p class="ec-v22-email">Recebido em: {{ $ecNotice->mailbox ?: 'Não registrada' }}</p>
                    <div class="ec-v22-foot"><span>{{ $ecInboundMatches[$ecNotice->link_method] ?? 'Conferir vínculo' }}</span></div>
                    @if ($ecNotice->notice_type === 'reply_needs_review')
                        <div class="ec-v22-actions"><a class="ec-v22-button" href="{{ route('campaigns.replies') }}" wire:navigate>Revisar na caixa geral</a></div>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    @if ($ecInboundCount > 100) <p class="ec-v22-more">Exibindo os 100 avisos mais recentes.</p> @endif
    @endif
</section>

@php
    $ecV22Unlinked = \Illuminate\Support\Facades\Schema::hasTable('email_campaign_unlinked_notices')
        ? \Illuminate\Support\Facades\DB::table('email_campaign_unlinked_notices')
            ->where('candidate_campaign_id', (int) $selected->id)
            ->orderByDesc('received_at')->limit(15)->get()
        : collect();
@endphp
@if ($ecV22Unlinked->isNotEmpty())
<section class="ec-v22 ec-v22--unlinked" id="ec-v21-campaign-unlinked" aria-label="E-mails alterados — associação não confirmada">
    <header class="ec-v22-head"><div><p class="ec-v22-overline">Vínculo a conferir</p><h3 class="ec-v22-title">Possíveis mudanças de e-mail</h3><p class="ec-v22-description">O assunto menciona esta campanha, mas não há prova de qual destinatário respondeu. Confira no Outlook.</p></div><div class="ec-v22-count"><strong>{{ $ecV22Unlinked->count() }}</strong> aviso(s)</div></header>
    <div class="ec-v22-feed">
        @foreach ($ecV22Unlinked as $ecV22Notice)
        <article class="ec-v22-item" wire:key="ec-v22-campaign-unlinked-{{ $ecV22Notice->id }}">
            <div class="ec-v22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $ecV22Notice->from_email, 0, 1)) }}</div>
            <div class="ec-v22-body"><div class="ec-v22-meta"><span class="ec-v22-pill ec-v22-pill--address">E-mail alterado · conferir</span><span class="ec-v22-timestamp">{{ \App\Support\CampaignLocalTime::format($ecV22Notice->received_at, 'Data indisponível') }}</span></div><h4 class="ec-v22-subject">{{ $ecV22Notice->subject }}</h4><p class="ec-v22-email">De: {{ $ecV22Notice->from_email }}</p><p class="ec-v22-email">Recebido em: {{ $ecV22Notice->mailbox ?: 'Não registrada' }}</p></div>
        </article>
        @endforeach
    </div>
</section>
@endif
