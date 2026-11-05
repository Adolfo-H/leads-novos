<div class="wa16-company" data-test="wa16-company-linker">
    <style>
    .wa16-company{border:1px solid #d5e7e5;border-radius:12px;padding:18px 20px;margin-top:20px;background:#f8fcfb;color:#123c4c}
    .wa16-company h3{font-size:16px;font-weight:700;margin:0 0 5px}.wa16-company p{font-size:12px;color:#73909b;margin-bottom:14px}
    .wa16-company input{display:block;width:100%;border:1px solid #cedeE5;border-radius:8px;background:white;padding:10px 13px;font-size:12px;margin:5px 0 12px;color:#133e4b}
    .wa16-company-row{display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e1edef;padding:10px 0;gap:8px;flex-wrap:wrap}
    .wa16-company-row small{color:#78939f;display:block;font-size:11px}.wa16-company button{background:white;color:#087d76;border:1px solid #b4d9d4;border-radius:7px;padding:7px 10px;font-size:12px;font-weight:700;cursor:pointer}
    .wa16-company button.wa16-selected{background:#e1f5ee;border-color:#1ca793}.wa16-company button.wa16-save{background:#008e80;color:white;border-color:#008e80;margin-top:9px}
    .wa16-company-options{max-height:180px;overflow:auto;display:grid;gap:6px}.wa16-company-options button{text-align:left}.wa16-company-success{padding:10px;border-radius:8px;background:#e8f8ef;color:#056957;font-size:12px}
    </style>
    <h3>Vincular WhatsApp publicado a uma empresa conhecida</h3>
    <p>Se o site exibiu um telefone de uma empresa que você conhece, selecione o canal e a empresa correta. A ligação fica registrada como uma decisão sua — sem criar pessoas nem vínculos fiscais automáticos.</p>
    <input type="search" wire:model.live.debounce.400ms="numberSearch" placeholder="Buscar telefone publicado ou empresa de origem..." autocomplete="off" aria-label="Buscar canal público para vincular">
    @foreach($channels as $item)
        <div class="wa16-company-row" wire:key="wa16-channel-{{ $item->id }}">
            <span><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($item->e164, $item->e164) }}</strong><small>{{ $item->company_name }} · {{ $item->domain }}</small></span>
            <button type="button" class="{{ $selectedChannel === $item->id ? 'wa16-selected' : '' }}" wire:click="chooseChannel({{ $item->id }})">{{ $selectedChannel === $item->id ? 'Selecionado' : 'Vincular empresa' }}</button>
        </div>
    @endforeach
    @if($channels->isEmpty())<p>Nenhum canal pendente para este filtro.</p>@endif
    @if($selectedChannel > 0)
        <div style="margin-top:15px;border-top:1px solid #d5e9e7;padding-top:15px">
            <strong>Escolha a empresa de destino</strong>
            <input type="search" wire:model.live.debounce.400ms="companySearch" placeholder="Nome da empresa ou CNPJ..." aria-label="Buscar empresa para vínculo">
            <div class="wa16-company-options">
            @foreach($matches as $item)
                <button type="button" class="{{ $selectedCompany === $item['key'] ? 'wa16-selected' : '' }}" wire:click="chooseCompany(@js($item['key']))">{{ $item['name'] }} · {{ $item['source'] }}</button>
            @endforeach
            </div>
            @if($selectedCompany !== '')<button type="button" class="wa16-save" wire:click="saveLink" wire:loading.attr="disabled" wire:target="saveLink">Confirmar vínculo com a empresa</button>@endif
            @error('wa16_link')<p role="alert" style="color:#b04436">{{ $message }}</p>@enderror
        </div>
    @endif
    @if(session('wa16_linked'))<p class="wa16-company-success" role="status">{{ session('wa16_linked') }}</p>@endif
</div>
