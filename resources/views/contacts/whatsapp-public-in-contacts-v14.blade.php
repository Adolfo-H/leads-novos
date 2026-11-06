{{-- Gestor: um único painel de vínculo em lote; sem repetir a lista V14 acima. --}}
@if(auth()->user()?->isCommercialManager())
    <livewire:whatsapp-company-links-v16 />
@elseif(mb_strlen(trim($searchTerm)) === 0 || mb_strlen(trim($searchTerm)) >= 3)
    {{-- Preserva a consulta pública de canais para vendedores sem permissão de vincular. --}}
    @php
        $wa14PublicChannels = app(\App\Services\Contacts\WhatsappPublicContactsV14::class)
            ->search($searchTerm, trim($searchTerm) === '' ? 8 : 30);
    @endphp
    @if($wa14PublicChannels->isNotEmpty())
        <section class="wa14-contact-results" data-test="wa14-business-channels-in-contacts" aria-label="Canais públicos empresariais">
            <style>
                .wa14-contact-results{background:#fff;border:1px solid #d8e8e7;border-radius:12px;padding:17px 20px;margin:16px 0}
                .wa14-contact-results h2{font-size:16px;font-weight:750;color:#0b3042;margin:0 0 4px}
                .wa14-contact-results p{font-size:12px;color:#718a98;margin:0 0 12px}
                .wa14-contact-row{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:11px 2px;border-top:1px solid #e7eeef;flex-wrap:wrap}
                .wa14-contact-row strong{font-size:14px;color:#07394a}.wa14-contact-row small{display:block;color:#72909c;font-size:11px;margin-top:2px}
                .wa14-contact-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;font-size:12px}
                .wa14-contact-actions a{color:#008c83;text-decoration:none;font-weight:700}
            </style>
            <h2>Canais públicos de WhatsApp das empresas ({{ $wa14PublicChannels->count() }} exibidos)</h2>
            <p>Lista somente para consulta. Um link publicado não comprova conta ativa.</p>
            @foreach($wa14PublicChannels as $wa14Channel)
                <div class="wa14-contact-row" wire:key="wa14-public-result-{{ $wa14Channel->id }}">
                    <div>
                        <strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($wa14Channel->e164, $wa14Channel->e164) }}</strong>
                        <small>{{ $wa14Channel->linked_company_name ?: $wa14Channel->company_name ?: 'Empresa HubSpot' }} · {{ $wa14Channel->domain }}</small>
                    </div>
                    <div class="wa14-contact-actions">
                        <a href="{{ $wa14Channel->source_url }}" target="_blank" rel="noopener noreferrer">Ver fonte ↗</a>
                        <a href="{{ route('contacts.whatsapp', ['busca' => $wa14Channel->e164]) }}">Verificar no Prospector ↗</a>
                    </div>
                </div>
            @endforeach
        </section>
    @endif
@endif
