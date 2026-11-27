{{-- EC_PROPOSALS_V27 --}}
<x-proposals.shell title="Importar propostas antigas" subtitle="Organize os PDFs históricos preservando número, data, valor, cliente e documento original.">
    <div class="grid two"><section class="card"><h2>Registrar PDF original</h2><p class="sub">A importação não recalcula preços nem emite novos números. O original fica em armazenamento privado e o sistema bloqueia documentos repetidos pelo hash.</p>
        <form action="{{ route('proposals.import.store') }}" method="POST" enctype="multipart/form-data" class="formgrid" style="margin-top:18px">@csrf
            <label class="full">PDF da proposta anterior *<input required type="file" name="document" accept="application/pdf"></label>
            <label class="full">Cliente / razão social *<input required name="client_name" maxlength="220" value="{{ old('client_name') }}"></label>
            <label class="full">Identificador original (único) *<input required name="legacy_code" maxlength="180" placeholder="Ex.: PROPOSTA_COMERCIAL_EC_2026_1023_LICENCIAMENTO" value="{{ old('legacy_code') }}"></label>
            <label>Serviço<select name="service">@foreach(\App\Services\Proposals\ProposalPricing::ALL as $service)<option value="{{ $service }}">{{ $service }}</option>@endforeach</select></label>
            <label>Número original (se confirmado)<input name="number" type="number" min="1000" placeholder="Em branco se desconhecido"></label>
            <label>Data original de emissão<input name="issued_on" type="date"></label>
            <label>Mensalidade histórica (R$)<input name="monthly_final" type="number" min="0" step="0.01" placeholder="Não inventar"></label>
            <label>Implantação / avulso histórico (R$)<input name="one_time_final" type="number" min="0" step="0.01" placeholder="Não inventar"></label>
            <label class="choice full"><input type="checkbox" name="reviewed" value="1" required>Confirmei que os dados digitados correspondem ao PDF original.</label>
            <div class="actions full"><button class="btn primary" type="submit">Guardar proposta no histórico</button><a class="btn" href="{{ route('proposals.index') }}">Cancelar</a></div>
        </form>
    </section><aside class="grid" style="align-content:start"><div class="card help"><h2>Como fazer a migração histórica</h2><p style="margin-top:8px">1. Separe os PDFs finais dos rascunhos antigos.</p><p>2. Confira identificadores e revisões.</p><p>3. Importe cada PDF com cliente, número e valores conhecidos.</p><p>4. Revise possíveis conflitos. @unless($numbering?->reconciled_at) Depois, libere a sequência global em Preços e reajustes. @endunless</p><p style="margin-top:8px">Os valores e condições dos PDFs originais prevalecem sobre a tabela atual.</p></div>@unless($numbering?->reconciled_at)<div class="card warning"><h2>Não conflitar numeração</h2><p>Confirme o último número emitido antes de liberar a sequência global.</p></div>@endunless</aside></div>
</x-proposals.shell>
