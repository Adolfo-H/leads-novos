<section class="wa16-company" data-test="wa16-company-linker" aria-labelledby="wa16-title">
    <style>
        .wa16-company{background:#fff;border:1px solid #d5e7e5;border-radius:14px;padding:20px 22px;margin:18px 0;color:#113b4c}
        .wa16-company h3{font-size:17px;font-weight:750;margin:0 0 5px;color:#103348}
        .wa16-company p{font-size:12px;line-height:1.6;color:#688393;margin:0 0 15px}
        .wa16-toolbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:13px 0}
        .wa16-company input[type=search]{width:100%;min-width:180px;border:1px solid #ccdedf;background:#fff;border-radius:9px;padding:11px 13px;font-size:13px;color:#123c4c;outline-offset:3px}
        .wa16-toolbar input{flex:1}
        .wa16-selected-count{font-size:12px;font-weight:700;background:#e1f3ee;color:#087568;border-radius:40px;padding:8px 12px}
        .wa16-source{border:1px solid #e1ecef;border-radius:10px;margin:12px 0;overflow:hidden}
        .wa16-source-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#f6faf9;border-bottom:1px solid #e4edef;padding:11px 14px}
        .wa16-source-head strong{font-size:13px}.wa16-source-head small{display:block;color:#75909c;font-size:11px;margin-top:3px}
        .wa16-company button{border:1px solid #bdd9d6;background:#fff;color:#087c73;border-radius:8px;padding:8px 11px;font-size:12px;font-weight:700;cursor:pointer}
        .wa16-company button:hover{border-color:#06978b;background:#eaf8f4}.wa16-company button:focus-visible{outline:2px solid #098b82;outline-offset:3px}
        .wa16-phone-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:9px;padding:12px}
        .wa16-phone{display:flex;align-items:center;gap:10px;text-align:left;min-height:55px;width:100%;padding:9px 11px!important}
        .wa16-phone[aria-pressed=true]{background:#e4f5f0;border-color:#0b9686;color:#036a62}
        .wa16-phone-check{display:grid;place-items:center;flex:none;width:20px;height:20px;border-radius:5px;border:1px solid #a6bdc2;background:#fff;color:transparent}
        .wa16-phone[aria-pressed=true] .wa16-phone-check{border-color:#098c7d;background:#098c7d;color:#fff}
        .wa16-phone-wrap{display:grid;gap:5px}.wa16-source-link{padding:2px 5px;color:#087c73;font-size:11px;font-weight:700;text-decoration:none}.wa16-source-link:hover{text-decoration:underline}
        .wa16-phone strong{display:block;font-size:12px}.wa16-phone small{display:block;color:#76909b;font-size:10px;margin-top:3px}
        .wa16-assign{background:#f7fbfa;border:1px solid #cce5df;border-radius:11px;margin-top:17px;padding:17px}
        .wa16-assign-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:9px}
        .wa16-assign-head strong{font-size:14px}.wa16-assign-label{display:block;font-size:12px;font-weight:700;margin:11px 0 7px}
        .wa16-company-options{display:grid;gap:6px;max-height:200px;overflow:auto;margin-top:10px}.wa16-company-options button{text-align:left}
        .wa16-company-options button.wa16-selected{background:#e1f5ee;border-color:#1ca793;color:#036f64}
        .wa16-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap;margin-top:13px}
        .wa16-company button.wa16-save{background:#008e80;border-color:#008e80;color:white;padding:11px 15px}.wa16-company button.wa16-save:disabled{opacity:.5}
        .wa16-error{padding:10px 12px;margin-top:10px!important;background:#fff2ed;border:1px solid #f3c9b9;border-radius:7px;color:#a63c32!important}
        .wa16-company-success{padding:12px 14px;margin-top:11px!important;background:#e8f8ef;border-radius:9px;color:#056957!important}
        .wa16-muted{padding:15px;background:#f6faf9;border:1px dashed #cfe1df;border-radius:9px}
        @media(max-width:640px){.wa16-company{padding:15px}.wa16-phone-grid{grid-template-columns:1fr}.wa16-assign{padding:12px}}
    </style>

    <h3 id="wa16-title">Vincular WhatsApps às empresas</h3>
    <p>Canais públicos de WhatsApp das empresas: escolha vários números da <strong>mesma empresa de origem</strong> e vincule todos de uma vez à empresa correta. A publicação no site não comprova que a conta esteja ativa; nenhuma pessoa é criada automaticamente.</p>

    <div class="wa16-toolbar">
        <input type="search" wire:model.live.debounce.350ms="numberSearch"
               placeholder="Buscar por número, empresa ou domínio..." autocomplete="off"
               aria-label="Buscar números publicados para vincular">
        <span class="wa16-selected-count" aria-live="polite">{{ $selectedCount }} selecionado(s)</span>
    </div>

    @forelse($groups as $sourceId => $sourceChannels)
        @php $company = $sourceChannels->first(); @endphp
        <div class="wa16-source" wire:key="wa16-source-{{ $sourceId }}">
            <div class="wa16-source-head">
                <div>
                    <strong>{{ $company->company_name ?: 'Empresa de origem' }}</strong>
                    <small>{{ $company->domain ?: 'Domínio não informado' }} · {{ $sourceChannels->count() }} número(s) exibido(s)</small>
                </div>
                <button type="button" wire:click="selectGroup({{ (int) $sourceId }})"
                        wire:loading.attr="disabled" wire:target="selectGroup">
                    Selecionar todos desta empresa
                </button>
            </div>
            <div class="wa16-phone-grid" role="group" aria-label="Números de {{ $company->company_name }}">
                @foreach($sourceChannels as $item)
                    @php $isSelected = in_array((int) $item->id, $selectedChannels, true); @endphp
                    <div class="wa16-phone-wrap" wire:key="wa16-channel-wrap-{{ $item->id }}">
                    <button type="button" class="wa16-phone" wire:key="wa16-channel-{{ $item->id }}"
                            wire:click="toggleChannel({{ (int) $item->id }})"
                            wire:loading.attr="disabled" wire:target="toggleChannel"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            aria-label="{{ $isSelected ? 'Remover' : 'Selecionar' }} {{ \App\Services\Contacts\BrazilPhoneNumber::display($item->e164, $item->e164) }}">
                        <span class="wa16-phone-check" aria-hidden="true">✓</span>
                        <span><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($item->e164, $item->e164) }}</strong><small>{{ $isSelected ? 'Selecionado' : 'Clique para selecionar' }}</small></span>
                    </button>
                    @if(is_string($item->source_url) && str_starts_with($item->source_url, 'https://'))
                        <a class="wa16-source-link" href="{{ $item->source_url }}" target="_blank" rel="noopener noreferrer">Ver fonte ↗</a>
                    @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="wa16-muted">Nenhum canal pendente neste filtro. Tente outro número ou empresa.</p>
    @endforelse

    @if($selectedCount > 0)
        <div class="wa16-assign" data-test="wa16-batch-review">
            <div class="wa16-assign-head">
                <strong>{{ $selectedCount }} número(s) para vincular</strong>
                <button type="button" wire:click="clearSelection">Limpar seleção</button>
            </div>
            <span class="wa16-assign-label">Escolha a empresa que receberá todos estes números</span>
            <input type="search" wire:model.live.debounce.350ms="companySearch"
                   placeholder="Buscar empresa pelo nome ou CNPJ..." aria-label="Buscar empresa de destino">
            <div class="wa16-company-options" role="group" aria-label="Empresas encontradas">
                @foreach($matches as $item)
                    <button type="button" class="{{ $selectedCompany === $item['key'] ? 'wa16-selected' : '' }}"
                            wire:key="wa16-destination-{{ $item['key'] }}"
                            wire:click="chooseCompany(@js($item['key']))"
                            aria-pressed="{{ $selectedCompany === $item['key'] ? 'true' : 'false' }}">
                        {{ $item['name'] }} · {{ $item['source'] }}
                    </button>
                @endforeach
            </div>
            @if($companySearch !== '' && mb_strlen(trim($companySearch)) >= 2 && $matches->isEmpty())
                <p>Nenhuma empresa encontrada. Revise o nome ou CNPJ.</p>
            @endif
            <div class="wa16-actions">
                <span style="font-size:11px;color:#718b98">Operação única e auditável, sem criar contatos.</span>
                <button type="button" class="wa16-save" wire:click="saveLinks"
                        wire:loading.attr="disabled" wire:target="saveLinks"
                        @disabled($selectedCompany === '')>
                    Vincular {{ $selectedCount }} número(s) à empresa
                </button>
            </div>
        </div>
    @endif

    @error('wa16_link')<p class="wa16-error" role="alert">{{ $message }}</p>@enderror
    @if(session('wa16_linked'))<p class="wa16-company-success" role="status">{{ session('wa16_linked') }}</p>@endif
</section>
