<div class="wa16-manual" data-test="wa16-manual-queue">
    <style>
        .wa16-manual{display:grid;gap:16px;margin:18px 0}.wa16-card{background:#fff;border:1px solid #d8e7e9;border-radius:12px;padding:19px 21px;color:#0a3444}
        .wa16-card h3{font-weight:750;font-size:16px;margin:0 0 5px}.wa16-card p{font-size:12px;color:#698594;margin:2px 0 13px}
        .wa16-row{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.wa16-grow{flex:1;min-width:240px}.wa16-manual label{display:grid;gap:7px;font-size:12px;font-weight:650;color:#184c5e}
        .wa16-manual textarea,.wa16-manual input{border:1px solid #cfdee4;background:#fff;color:#0b3040;border-radius:8px;padding:10px 12px;width:100%;font-size:13px}
        .wa16-manual button{border:1px solid #0b9485;background:#008e80;color:#fff;padding:10px 14px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700}
        .wa16-manual button.wa16-secondary{background:#fff;color:#087f77;border-color:#aacfc9}.wa16-manual button.wa16-danger{color:#a04235;border-color:#e6b5ae;background:#fff}
        .wa16-review-list{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0}.wa16-review-list button{background:#f3f8f9;color:#184b59;border:1px solid #deeaec}
        .wa16-info{font-size:12px;color:#1e665e;padding:10px;background:#e9f8f1;border:1px solid #bee8d6;border-radius:8px}.wa16-error{color:#ab423a;font-size:12px}
    </style>
    <div class="wa16-card">
        <h3>Verificar números escolhidos por você</h3>
        <p>Cole até 10 telefones (um por linha). Aceita +55, DDD, espaços e hífens. Eles vão para as contas conectadas, sem envio de mensagens. Já confirmados ou consultados nas últimas 24 horas não serão repetidos.</p>
        <div class="wa16-row">
            <label class="wa16-grow">Telefones para conferir
                <textarea rows="4" wire:model="numbers" placeholder="(45) 99999-1234&#10;+55 11 98888-7777" autocomplete="off"></textarea>
            </label>
            <button type="button" wire:click="requestSpecific" wire:loading.attr="disabled" wire:target="requestSpecific">Consultar números informados</button>
        </div>
        @error('wa16_numbers')<p class="wa16-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="wa16-card" id="wa16-review">
        <h3>Revisar números com formato incorreto</h3>
        <p>Escolha um dos números do banco, informe uma correção comprovada ou marque o cadastro como inválido. O sistema não tenta adivinhar DDD ou completar o nono dígito.</p>
        <label>Encontrar número para revisar
            <input type="search" wire:model.live.debounce.400ms="reviewSearch" placeholder="Pesquise pelos dígitos do telefone..." autocomplete="off">
        </label>
        <div class="wa16-review-list">
            @forelse($reviews as $review)
                <button type="button" class="wa16-secondary" wire:click="chooseReview({{ (int) $review->id }})">{{ $review->digits }} · {{ (int) $review->contacts_count }} vínculo(s)</button>
            @empty
                <p>Nenhum formato pendente nessa pesquisa.</p>
            @endforelse
        </div>
        @if($reviewPhoneId > 0)
            <div class="wa16-row">
                <label class="wa16-grow">Número correto, com DDD (confira na fonte)
                    <input wire:model="correctedPhone" placeholder="Ex.: +55 45 99999-1234">
                </label>
                <label class="wa16-grow">Observação (opcional)
                    <input wire:model="reviewNote" placeholder="Ex.: corrigido após contato com a empresa">
                </label>
            </div>
            <div class="wa16-row" style="margin-top:12px">
                <button type="button" wire:click="saveReview('corrected')" wire:loading.attr="disabled">Salvar correção</button>
                <button type="button" class="wa16-danger" wire:click="saveReview('invalid')" wire:loading.attr="disabled">Registrar como inválido</button>
            </div>
        @endif
        @error('wa16_format')<p class="wa16-error" role="alert">{{ $message }}</p>@enderror
    </div>
    @if(session('wa16_ok'))<div class="wa16-info" role="status">{{ session('wa16_ok') }}</div>@endif
</div>
