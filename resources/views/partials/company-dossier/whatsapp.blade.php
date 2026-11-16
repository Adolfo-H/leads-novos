{{-- EC_WA_DOSSIER_V14: usa a empresa local já autorizada no dossiê. --}}
@php $wa14CompanyPhones = app(\App\Services\Contacts\WhatsappPublicContacts::class)->dossier($company); @endphp
<article class="e53-panel" style="padding:20px;margin:14px 0 23px" data-test="wa14-dossier-phones">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:9px">
        <h2 style="font-size:17px;font-weight:750;margin:0">Telefones e WhatsApp da empresa</h2>
        <span style="font-size:12px;color:#718998">{{ $wa14CompanyPhones->count() }} número(s) exibido(s)</span>
    </div>
    <p style="font-size:12px;color:#6f8896;margin-bottom:13px">Dados do cadastro fiscal, contatos vinculados e WhatsApps publicados. Publicação em site é evidência, não prova de conta ativa.</p>
    @forelse($wa14CompanyPhones as $wa14Number)
        @php $wa14Status = $wa14Number['status']; @endphp
        <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e2eaee;gap:10px;padding:12px 0;flex-wrap:wrap">
            <div style="display:grid;gap:3px">
                <strong style="font-size:14px">{{ $wa14Number['display'] }}</strong>
                <small style="font-size:11px;color:#738c98">{{ $wa14Number['sources'] }}</small>
            </div>
            <div style="display:flex;align-items:center;gap:11px;font-size:12px;flex-wrap:wrap">
                <span style="padding:5px 10px;border-radius:99px;font-weight:700;background:{{ $wa14Status === 'yes' ? '#e3f5eb' : ($wa14Status === 'no' ? '#fff0ed' : '#eef4f6') }};color:{{ $wa14Status === 'yes' ? '#087c62' : ($wa14Status === 'no' ? '#aa4c43' : '#607988') }}">
                    {{-- EC_WA_DOSSIER_PROBE_V91: último resultado técnico não altera a confirmação manual. --}}
                    {{ match (true) {
                        $wa14Status === 'yes' => 'Com WhatsApp (confirmado)',
                        $wa14Status === 'no' => 'Sem WhatsApp (conferido manualmente)',
                        $wa14Number['probe_result'] === 'not_found' => 'WhatsApp não localizado na consulta',
                        $wa14Number['probe_result'] === 'inconclusive' => 'Consulta inconclusiva — WhatsApp não confirmado',
                        $wa14Number['probe_result'] === 'error' => 'Consulta com erro — verificar novamente',
                        $wa14Number['probe_result'] === 'found' => 'Indício de WhatsApp — conferir',
                        default => $wa14Number['published'] ? 'Divulgado como WhatsApp' : 'Ainda não consultado',
                    } }}
                </span>
                @if ($wa14Number['status'] === 'unknown' && $wa14Number['probe_checked_at'])
                    <small style="color:#637e90" title="Consulta técnica não oficial; não substitui uma confirmação manual">
                        Consulta {{ \Carbon\Carbon::parse($wa14Number['probe_checked_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
                    </small>
                @endif
                @if($wa14Number['source_url'])<a href="{{ $wa14Number['source_url'] }}" rel="noopener noreferrer" target="_blank">Fonte ↗</a>@endif
                <a href="{{ route('contacts.index', ['q' => $wa14Number['e164']]) }}">Ver em Contatos ↗</a>
            </div>
        </div>
    @empty
        <p style="font-size:13px;color:#708a96">Nenhum telefone conhecido ou divulgado para esta empresa.</p>
    @endforelse
    @if($wa14CompanyPhones->count() >= 60)<small style="color:#8498a1">Exibindo os primeiros 60; utilize Contatos para pesquisar outros números.</small>@endif
</article>
