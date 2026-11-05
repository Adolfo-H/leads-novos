{{-- EC_WA_PUBLIC_CHANNELS_SEARCH_V14: canais empresariais NÃO são pessoas fictícias. --}}
@if(mb_strlen(trim($searchTerm)) === 0 || mb_strlen(trim($searchTerm)) >= 3)
    @php
        $wa14PublicChannels = app(\App\Services\Contacts\WhatsappPublicContactsV14::class)->search($searchTerm, trim($searchTerm) === '' ? 8 : 30);
    @endphp
    @if($wa14PublicChannels->isNotEmpty())
        <section class="wa14-contact-results" data-test="wa14-business-channels-in-contacts" aria-label="Canais públicos empresariais">
            <style>
                .wa14-contact-results{background:#fff;border:1px solid #d8e8e7;border-radius:12px;padding:17px 20px;margin:16px 0;max-width:100%}
                .wa14-contact-results h2{font-size:16px;font-weight:750;color:#0b3042;margin:0 0 4px}
                .wa14-contact-results p{font-size:12px;color:#718a98;margin:0 0 12px}
                .wa14-contact-row{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:11px 2px;border-top:1px solid #e7eeef;flex-wrap:wrap}
                .wa14-contact-row strong{font-size:14px;color:#07394a}
                .wa14-contact-row small{display:block;color:#72909c;font-size:11px;margin-top:2px}
                .wa14-contact-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;font-size:12px}
                .wa14-contact-status{border-radius:99px;padding:5px 10px;background:#edf5f4;color:#087a72;font-weight:700}
                .wa14-contact-status[data-status=no]{background:#fff0ed;color:#ba5545}
                .wa14-contact-status[data-status=unknown]{background:#eef3f6;color:#617987}
                .wa14-contact-actions a{color:#008c83;text-decoration:none;font-weight:700}
            </style>
            <h2>Canais públicos de WhatsApp das empresas <span style="font-size:12px;color:#66818f">({{ $wa14PublicChannels->count() }} exibidos)</span></h2>
            <p>Esses telefones estão cadastrados como canais empresariais do Prospector. Não são pessoas criadas automaticamente. Um link publicado não comprova conta ativa.</p>
            @foreach($wa14PublicChannels as $wa14Channel)
                <div class="wa14-contact-row" wire:key="wa14-public-result-{{ $wa14Channel->id }}">
                    <div><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($wa14Channel->e164, $wa14Channel->e164) }}</strong>
                    <small>{{ $wa14Channel->linked_company_name ?: $wa14Channel->company_name ?: 'Empresa HubSpot' }} · {{ $wa14Channel->domain }} · Fonte: site publicado</small></div>
                    <div class="wa14-contact-actions">
                        <span class="wa14-contact-status" data-status="{{ $wa14Channel->status ?? 'unknown' }}">{{ match($wa14Channel->status) {'yes' => 'Tem WhatsApp', 'no' => 'Não possui (verificado)', default => 'Divulgado como WhatsApp'} }}</span>
                        <a href="{{ $wa14Channel->source_url }}" target="_blank" rel="noopener noreferrer">Ver fonte ↗</a>
                        <a href="{{ route('contacts.whatsapp', ['busca' => $wa14Channel->e164]) }}">Verificar no Prospector ↗</a>
                    </div>
                </div>
            @endforeach
            {{-- EC_WA_V16_LINK_UI: associação explícita sem alterar o HubSpot --}}
            @if(auth()->user()?->isCommercialManager())
                <livewire:whatsapp-company-links-v16 />
            @endif
        </section>
    @endif
@endif
