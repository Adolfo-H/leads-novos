{{-- V86: apenas agrupamento visual. Identidades fiscais e negócios mantidos separados. --}}
@if(($wa85Business['grouped'] ?? false) === true)
    <style>
        .wa86-summary{border:1px solid #c8e6df;border-radius:10px;background:#f2fbf8;padding:13px 15px;margin:13px 0;color:#184a43;font-size:12px}
        .wa86-summary strong{display:block;margin-bottom:5px;color:#076b60;font-size:13px}
        .wa86-summary p{line-height:1.55;color:#38695f;margin:0}
        .wa86-records{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:10px 0}
        .wa86-record{border:1px solid #deeaee;border-radius:8px;background:white;padding:10px 12px;min-width:0;display:flex;gap:5px;flex-direction:column}
        .wa86-record strong{font-size:12px;color:#163d52;overflow-wrap:anywhere}
        .wa86-record small{font-size:10px;color:#597d8f}
        @media(max-width:700px){.wa86-records{grid-template-columns:1fr}}
    </style>
    <div class="wa86-summary" data-test="wa86-grouped-context">
        <strong>Empresa com registros comerciais agrupados</strong>
        <p>{{ count($wa85Business['records'] ?? []) }} cadastro(s) e negócio(s) apresentados juntos para facilitar a consulta. O agrupamento é pelo nome, não comprova que todos tenham o mesmo CNPJ. Os telefones são buscados apenas nos vínculos originais de cada fonte.</p>
    </div>
    <div class="wa86-records" aria-label="Empresas e negócios do grupo">
        @foreach(($wa85Business['records'] ?? []) as $wa86Index => $wa86Record)
            <div class="wa86-record" wire:key="wa86-record-{{ $wa86Index }}">
                <strong>{{ $wa86Record['name'] }}</strong>
                <small>{{ $wa86Record['origin'] }}</small>
            </div>
        @endforeach
    </div>
@endif
