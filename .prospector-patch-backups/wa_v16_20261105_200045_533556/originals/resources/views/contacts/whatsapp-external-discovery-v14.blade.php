{{-- EC_WA_EXTERNAL_SOURCES_V14: dados públicos adicionais, sujeitos a revisão. --}}
@php
    $wa14Discoverer = app(\App\Services\Contacts\WhatsappExternalCompanyDiscoveryV14::class);
    $wa14HasProvider = $wa14Discoverer->configured();
    $wa14Findings = $wa14Discoverer->findings((int) $wa11Company['company']->id);
    $wa14Run = $wa14Discoverer->latestRun((int) $wa11Company['company']->id);
@endphp
<div class="wa14-external" data-test="wa14-external-search" style="border:1px solid #d9e9e8;border-radius:12px;margin-top:17px;padding:15px;background:#f8fcfb">
    <div style="display:flex;justify-content:space-between;align-items:start;gap:15px;flex-wrap:wrap">
        <div><strong>Pesquisar outras fontes públicas</strong>
            <p style="font-size:12px;color:#718b99;margin:4px 0">Busca indexada na web, incluindo páginas públicas de LinkedIn e Google Maps quando indexadas pelo provedor. Não acessa diretamente essas plataformas nem envia WhatsApp.</p>
        </div>
        @if($wa14HasProvider)
            <button class="wa11-btn" type="button" wire:click="searchExternalCompanyV14" wire:loading.attr="disabled" wire:target="searchExternalCompanyV14">Pesquisar fontes extras</button>
        @else
            <span style="font-size:11px;color:#647f8b;background:#eef4f5;padding:8px;border-radius:8px">Conecte Brave Search para habilitar</span>
        @endif
    </div>
    @if($wa14Run)<p style="font-size:11px;color:#72909c">Última pesquisa externa: {{ $wa14Run->status === 'completed' ? 'concluída' : ($wa14Run->status === 'queued' || $wa14Run->status === 'running' ? 'em andamento' : 'indisponível') }}</p>@endif
    @forelse($wa14Findings as $item)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #e2edec;flex-wrap:wrap">
            <div><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($item->phone_e164, $item->phone_e164) }}</strong>
                <small style="display:block;color:#688592;font-size:11px">{{ $item->provider === 'google_places' ? 'Google Maps · Telefone listado, não confirma WhatsApp' : 'Busca web · Link explícito de WhatsApp' }} · {{ $item->title }}</small>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <a href="{{ $item->source_url }}" target="_blank" rel="noopener noreferrer" class="wa11-link">Ver fonte ↗</a>
                @if($item->review_status === 'pending' && $item->finding_type === 'explicit_whatsapp_link')
                    <button type="button" class="wa11-btn wa11-btn-outline" wire:click="acceptExternalCompanyV14({{ $item->id }})">Salvar canal na empresa</button>
                @elseif($item->review_status === 'accepted')
                    <span style="color:#078478;font-size:11px;font-weight:700">Canal cadastrado</span>
                @else
                    <span style="color:#8497a3;font-size:11px">Revisar antes de cadastrar</span>
                @endif
            </div>
        </div>
    @empty
        <p style="font-size:12px;color:#708a96;margin:10px 0 0">Nenhum número adicional encontrado por fontes externas até agora.</p>
    @endforelse
    <p style="font-size:11px;color:#8496a1;margin-top:9px">A pesquisa direta no Google Maps exige integração e termos próprios, não incluídos neste pacote. Resultados externos não são adicionados automaticamente aos contatos nem marcados como WhatsApp confirmado; aprovados explicitamente podem virar canais públicos da empresa.</p>
</div>
