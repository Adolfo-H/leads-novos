{{-- EC_PROPOSALS_V28 --}}
{{-- EC_PROPOSALS_V27 --}}
<x-proposals.shell title="Modelos de propostas" subtitle="Biblioteca de modelos de propostas comerciais.">
    @php $manager=auth()->user()?->isCommercialManager(); @endphp
    <div class="grid two"><div class="grid">
    @foreach($templates as $template)
        <article class="card"><div class="cardhead"><div><span class="eyebrow">{{ $template->service }}</span><h2>{{ $template->name }}</h2></div><span class="tag {{ $template->ready_to_issue ? 'issued' : 'draft' }}">{{ $template->ready_to_issue ? 'Modelo oficial' : 'Aguardando aprovação' }}</span></div>
            <p class="sub">{{ $template->description }}</p>
            <p style="margin-top:10px">{{ $template->commercial_notes ?: 'Sem notas internas adicionais.' }}</p>
            @if($template->ready_to_issue)
                <div class="actions" style="margin-top:12px"><a class="btn primary" target="_blank" rel="noopener noreferrer" href="{{ route('proposals.model-pdf-v28',['service'=>$template->service]) }}">Ver PDF do modelo</a></div>
                <p class="sub" style="margin-top:6px">Modelo em branco, gerado da fonte utilizada na emissão. Sem cliente, número, valores de negociação ou validade comercial.</p>
            @endif
            @if($manager)<details style="margin-top:15px"><summary class="link">Editar notas internas deste modelo</summary>
                <form method="POST" action="{{ route('proposals.template-update') }}" class="formgrid" style="margin-top:12px">@csrf
                    <input type="hidden" name="service" value="{{ $template->service }}">
                    <label class="full">Descrição<input name="description" value="{{ $template->description }}" maxlength="1200"></label>
                    <label class="full">Notas internas de uso<textarea name="commercial_notes" maxlength="4000">{{ $template->commercial_notes }}</textarea></label>
                    <button type="submit" class="btn primary">Salvar notas do modelo</button>
                </form>
            </details>@endif
        </article>
    @endforeach
    </div><aside class="grid" style="align-content:start"><section class="card"><h2>Como os modelos funcionam</h2><p style="margin-top:9px">Os PDFs seguem as fontes HTML oficiais fornecidas no gerador ExportControl, com capa CLEAN TEAL, logotipos e tabelas marginais. Não usamos o estilo do painel no documento do cliente.</p><div class="divider"></div><p class="sub">A edição nesta etapa permite observações internas. Alterações na estrutura jurídica e no layout oficial exigem uma revisão homologada do modelo, sem sobrescrever os arquivos históricos.</p></section>
</aside></div>
</x-proposals.shell>
