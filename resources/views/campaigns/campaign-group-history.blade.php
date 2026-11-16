{{-- EC_CAMPAIGN_GROUPED_V1: historico real sempre ligado a campanha original. Somente consulta. --}}
@php
    $ec81Root = $selected->followup_root_campaign_id
        ? \App\Models\EmailCampaign::query()->find((int) $selected->followup_root_campaign_id)
        : $selected;
    $ec81Rounds = $ec81Root
        ? \App\Models\EmailCampaign::query()
            ->where('followup_root_campaign_id', $ec81Root->id)
            ->withCount('recipients')
            ->withCount(['recipients as accepted_smtp_count' => static fn ($q) => $q->where('status', 'sent')])
            ->orderBy('followup_round')->orderBy('id')->limit(20)->get()
        : collect();
@endphp
@if ($ec81Root && $ec81Rounds->isNotEmpty())
    <section class="ec81-group-history" data-ec81-group-history="v1" aria-label="Histórico das rodadas da campanha">
        <div class="ec81-group-heading">
            <div>
                <span class="ec81-kicker">CAMPANHA AGRUPADA</span>
                <h2>Histórico da campanha e dos retornos</h2>
                <p>As mensagens e os retornos são registrados em rodadas distintas, mas pertencem à mesma campanha.</p>
            </div>
            <span class="ec81-counter">{{ $ec81Rounds->count() + 1 }} rodadas</span>
        </div>
        <div class="ec81-group-list">
            <div class="ec81-group-item">
                <span class="ec81-bubble">1</span>
                <div class="ec81-copy">
                    <strong>1ª rodada · {{ $ec81Root->name }}</strong>
                    <small>Campanha inicial · {{ $ec81Root->created_at?->format('d/m/Y') ?? 'Data não informada' }}</small>
                </div>
                <a href="{{ route('campaigns.index', ['campanha' => $ec81Root->id]) }}">Ver 1ª rodada</a>
            </div>
            @foreach ($ec81Rounds as $ec81Round)
                <div class="ec81-group-item" wire:key="ec81-{{ $ec81Round->id }}">
                    <span class="ec81-bubble">{{ (int) $ec81Round->followup_round }}</span>
                    <div class="ec81-copy">
                        <strong>{{ (int) $ec81Round->followup_round }}ª rodada · {{ $ec81Round->name }}</strong>
                        <small>{{ (int) $ec81Round->accepted_smtp_count }} de {{ (int) $ec81Round->recipients_count }} aceitos pelo SMTP · Status: {{ $labels[$ec81Round->status] ?? $ec81Round->status }}</small>
                    </div>
                    <a href="{{ route('campaigns.index', ['campanha' => $ec81Round->id]) }}">Ver resultados</a>
                </div>
            @endforeach
        </div>
        <p class="ec81-disclaimer">Aceite SMTP não é entrega confirmada. Respostas e devoluções podem surgir após o envio; consulte a rodada para os detalhes.</p>
    </section>
    <style>
        [data-ec81-group-history="v1"] {border:1px solid #cae6de;border-radius:14px;background:#fff;padding:20px 22px;margin:15px 0;}
        [data-ec81-group-history="v1"] .ec81-group-heading {display:flex;align-items:start;justify-content:space-between;gap:15px;margin-bottom:14px;}
        [data-ec81-group-history="v1"] .ec81-kicker {color:#087b70;font-size:10px;font-weight:700;letter-spacing:.08em;}
        [data-ec81-group-history="v1"] h2 {color:#0d354e;font-size:19px;margin:6px 0;}
        [data-ec81-group-history="v1"] p {font-size:12px;color:#68808d;margin:0;}
        [data-ec81-group-history="v1"] .ec81-counter {white-space:nowrap;padding:6px 10px;background:#e5f5f0;color:#0b675f;border-radius:99px;font-size:11px;font-weight:700;}
        [data-ec81-group-history="v1"] .ec81-group-list {display:grid;gap:9px;}
        [data-ec81-group-history="v1"] .ec81-group-item {display:flex;align-items:center;gap:12px;border:1px solid #deebe8;border-radius:10px;padding:13px;}
        [data-ec81-group-history="v1"] .ec81-bubble {width:32px;height:32px;display:grid;place-items:center;background:#e9f8f3;color:#087365;border-radius:50%;font-size:13px;font-weight:700;flex-shrink:0;}
        [data-ec81-group-history="v1"] .ec81-copy {flex:1;min-width:0;}
        [data-ec81-group-history="v1"] strong {color:#173e51;display:block;font-size:13px;}
        [data-ec81-group-history="v1"] small {font-size:11px;color:#627985;display:block;margin-top:4px;}
        /* EC_CAMPAIGN_DIRECT_LINK_V905 */
        [data-ec81-group-history="v1"] :is(button,a) {text-decoration:none;display:inline-flex;align-items:center;background:#eaf7f3;border:1px solid #cce7de;border-radius:9px;color:#057569;padding:9px 13px;cursor:pointer;font-weight:700;font-size:11px;}
        [data-ec81-group-history="v1"] .ec81-disclaimer {margin-top:12px;}
        @media(max-width:680px){[data-ec81-group-history="v1"] .ec81-group-item{flex-wrap:wrap;}[data-ec81-group-history="v1"] button{margin-left:44px}}
    </style>
@endif
