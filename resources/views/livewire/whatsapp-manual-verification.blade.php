<div class="wa16-manual" data-test="wa16-manual-queue">
    <style>
        .wa16-manual{display:grid;gap:16px;margin:18px 0}.wa16-card{background:#fff;border:1px solid #d8e7e9;border-radius:12px;padding:19px 21px;color:#0a3444}
        .wa16-card h3{font-weight:750;font-size:16px;margin:0 0 5px}.wa16-card p{font-size:12px;color:#698594;margin:2px 0 13px}
        .wa16-row{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.wa16-grow{flex:1;min-width:240px}.wa16-manual label{display:grid;gap:7px;font-size:12px;font-weight:650;color:#184c5e}
        .wa16-manual textarea,.wa16-manual input{border:1px solid #cfdee4;background:#fff;color:#0b3040;border-radius:8px;padding:10px 12px;width:100%;font-size:13px}
        .wa16-manual button{border:1px solid #0b9485;background:#008e80;color:#fff;padding:10px 14px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700}
        .wa16-manual button.wa16-secondary{background:#fff;color:#087f77;border-color:#aacfc9}.wa16-manual button.wa16-danger{color:#a04235;border-color:#e6b5ae;background:#fff}
        .wa16-review-list{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0}.wa16-review-list button,.wa16-review-list a{background:#f3f8f9;color:#184b59;border:1px solid #deeaec;padding:9px 12px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none}.wa16-review-list a:hover{background:#e4f4f0;border-color:#20a596}
        .wa16-info{font-size:12px;color:#1e665e;padding:10px;background:#e9f8f1;border:1px solid #bee8d6;border-radius:8px}.wa16-error{color:#ab423a;font-size:12px}
    </style>
    {{-- EC_WA_OPERATIONS_HTTP_V1: componente funciona sem JavaScript. --}}
    <div class="wa16-card" id="wa16-manual-queue">
        <h3>Verificar números escolhidos por você</h3>
        <p>Cole até 10 telefones (um por linha). Aceita +55, DDD, espaços e hífens. Eles vão para as contas conectadas, sem envio de mensagens. Já confirmados ou consultados nas últimas 24 horas não serão repetidos.</p>
        <form method="POST" action="{{ route('contacts.whatsapp.operations-http-v1') }}" class="wa16-row">
            @csrf
            <input type="hidden" name="mode" value="specific">
            <label class="wa16-grow">Telefones para conferir
                <textarea rows="4" name="numbers" placeholder="(45) 99999-1234&#10;+55 11 98888-7777" autocomplete="off">{{ old('numbers', '') }}</textarea>
            </label>
            <button type="submit">Consultar números informados</button>
        </form>
        @error('wa16_numbers')<p class="wa16-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="wa16-card" id="wa16-review">
        <h3>Revisar números com formato incorreto</h3>
        <p>Escolha um dos números do banco, informe uma correção comprovada ou marque o cadastro como inválido. O sistema não tenta adivinhar DDD ou completar o nono dígito.</p>
        <form method="GET" action="{{ route('contacts.whatsapp') }}" class="wa16-native-search">
            <input type="hidden" name="aba" value="operation">
            <label>Encontrar número para revisar
                <input type="search" name="revisarBusca" value="{{ $reviewSearch }}" placeholder="Pesquise pelos dígitos do telefone..." autocomplete="off">
            </label>
            <button type="submit" class="wa16-secondary">Buscar número</button>
        </form>
        <div class="wa16-review-list">
            @forelse($reviews as $review)
                <a class="wa16-secondary" href="{{ route('contacts.whatsapp', ['aba' => 'operation', 'revisarBusca' => $reviewSearch, 'revisarNumero' => $review->id]) }}#wa16-review">{{ $review->digits }} · {{ (int) $review->contacts_count }} vínculo(s)</a>
            @empty
                <p>Nenhum formato pendente nessa pesquisa.</p>
            @endforelse
        </div>
        @if($reviewPhoneId > 0)
            <form method="POST" action="{{ route('contacts.whatsapp.operations-http-v1') }}" class="wa16-native-review">
                @csrf
                <input type="hidden" name="phone_id" value="{{ $reviewPhoneId }}">
                <input type="hidden" name="review_search" value="{{ $reviewSearch }}">
                <div class="wa16-row">
                    <label class="wa16-grow">Número correto, com DDD (confira na fonte)
                        <input name="corrected_phone" value="{{ old('corrected_phone', '') }}" placeholder="Ex.: +55 45 99999-1234">
                    </label>
                    <label class="wa16-grow">Observação (opcional)
                        <input name="review_note" value="{{ old('review_note', '') }}" placeholder="Ex.: corrigido após contato com a empresa">
                    </label>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:12px">
                    <input type="checkbox" name="review_confirm" value="1" style="width:auto" required>
                    Confirmo que conferi o telefone na fonte. A decisão será registrada para auditoria.
                </label>
                <div class="wa16-row" style="margin-top:12px">
                    <button type="submit" name="mode" value="review:corrected">Salvar correção</button>
                    <button type="submit" name="mode" value="review:invalid" class="wa16-danger">Registrar como inválido</button>
                </div>
            </form>
        @endif
        @error('wa16_format')<p class="wa16-error" role="alert">{{ $message }}</p>@enderror
    </div>
    @if(session('wa16_ok'))<div class="wa16-info" role="status">{{ session('wa16_ok') }}</div>@endif
</div>
