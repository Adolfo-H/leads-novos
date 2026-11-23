@php
    $histInfo = app(\App\Services\Proposals\HistoricalProposalReadModel::class)->details((int)$histProposal->id);
    $histDocs = \Illuminate\Support\Facades\DB::table('ec_proposal_documents')->where('proposal_id',$histProposal->id)->whereIn('kind',['historical_pdf','historical_original'])->orderBy('id')->get(['original_name','mime','bytes']);
    $histSvc = \App\Services\Proposals\HistoricalProposalReadModel::labels($histInfo?->services ?? $histProposal->service_codes);
    $histEvidence = json_decode($histInfo?->evidence ?? '{}',true) ?: [];
    $finance = $histEvidence['finance_v2'] ?? [];
    $bands = $finance['bands'] ?? [];
    $taxes = $finance['taxes'] ?? [];
    $conditions = $finance['conditions'] ?? [];
    $serviceNames = ['LICENCIAMENTO'=>'Licenciamento','RETROATIVAS'=>'Retroativas','ACOMPANHAMENTO'=>'Acompanhamento','DFE'=>'DF-e','NFSE'=>'NFS-e'];
    $servicesText = implode(', ', array_map(fn($code) => $serviceNames[$code] ?? $code, $histSvc));
    $title = $histInfo?->title ?: ($histDocs->first()?->original_name ?: 'Proposta histórica');
    $formal = $histProposal->status === 'accepted';
    $crmAccepted = $histInfo?->commercial_status === 'accepted_crm';
    $monthly = $histInfo?->monthly_amount;
    $once = $histInfo?->one_time_amount;
    $pointOnly = $histSvc === ['RETROATIVAS'] || ($finance['monthly_state'] ?? '') === 'not_applicable';
    $monthlyText = $monthly !== null ? \App\Services\Proposals\HistoricalProposalReadModel::money($monthly) : ($pointOnly ? 'Não se aplica' : 'Pendente');
    $onceText = $once !== null ? \App\Services\Proposals\HistoricalProposalReadModel::money($once) : 'Não identificado';
    $originalUrl = request()->fullUrlWithQuery(['visao_original'=>1]);
@endphp
@include('proposals.historical.styles-v2')
<div class="ecp-history">
    <div class="ecp-detail-top">
        <div>
            <a class="ecp-link" href="{{ url('/propostas') }}">← Todas as propostas</a>
            <div class="ecp-detail-title">{{ str_replace('_',' ',(string)$title) }}</div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span class="ecp-chip {{ $formal || $crmAccepted ? 'ecp-chip-accepted' : 'ecp-chip-sent' }}">{{ $formal ? 'Aceita formalmente' : ($crmAccepted ? 'Aceita no CRM' : 'Emitida / enviada') }}</span><span class="ecp-sub">{{ $histProposal->client_name }} @if($histInfo?->historic_number) · EC {{ $histInfo->historic_year }} / {{ $histInfo->historic_number }} @endif</span></div>
        </div>
        <a class="ecp-btn ecp-btn-primary" href="{{ $originalUrl }}">Documento original e ações</a>
    </div>
    <div class="ecp-summary">
        <div class="ecp-value ecp-value-main"><span class="ecp-label">{{ $pointOnly ? 'Valor do serviço' : 'Mensalidade final' }}</span><strong>{{ $pointOnly ? $onceText : $monthlyText }}@if(!$pointOnly && $monthly !== null)<span class="ecp-sub" style="display:inline">/mês</span>@endif</strong><span class="ecp-sub">{{ ($finance['monthly_state'] ?? '') === 'previous_review' || ($pointOnly && ($finance['one_time_state'] ?? '') === 'previous_review') ? 'Valor anterior — conferir original' : 'Valor histórico do documento' }}</span></div>
        <div class="ecp-value"><span class="ecp-label">{{ $pointOnly ? 'Valor líquido' : 'Valor pontual / implantação' }}</span><strong>{{ $pointOnly ? \App\Services\Proposals\HistoricalProposalReadModel::money($finance['net_amount'] ?? null) : $onceText }}</strong><span class="ecp-sub">{{ $pointOnly ? 'Antes dos tributos, se identificado' : 'Não confundir com mensalidade' }}</span></div>
        <div class="ecp-value"><span class="ecp-label">{{ $pointOnly ? 'Base total de documentos' : 'Base de referência' }}</span><strong>{{ isset($finance['base_quantity']) && is_numeric($finance['base_quantity']) ? number_format((float)$finance['base_quantity'],0,',','.') : 'Não identificada' }}</strong><span class="ecp-sub">Documentos fiscais · conforme proposta</span></div>
    </div>
    @if(!$pointOnly && ($monthly === null || ($finance['monthly_state'] ?? '') === 'previous_review'))
        <div class="ecp-note ecp-warning">{{ ($finance['monthly_state'] ?? '') === 'conflict' ? 'Foram identificados valores mensais divergentes. Consulte as evidências e o documento original.' : (($finance['monthly_state'] ?? '') === 'previous_review' ? 'A mensalidade anterior foi preservada porque aparece no documento, mas a associação ao rótulo não pôde ser confirmada pela nova leitura.' : 'Mensalidade ainda não identificada com segurança. Consulte o documento original; nenhum preço foi estimado.') }}</div>
    @endif
    <section class="ecp-panel">
        <div class="ecp-head"><div><h2>Informações da proposta</h2><p class="ecp-sub">Identificação comercial e emissão</p></div></div>
        <dl class="ecp-facts">
            @foreach([
              'Cliente'=>$histProposal->client_name,
              'Número histórico'=>$histInfo?->historic_number ? ('EC '.$histInfo->historic_year.' / '.$histInfo->historic_number) : 'Não identificado',
              'Emissão'=>\App\Services\Proposals\HistoricalProposalReadModel::date($histInfo?->issued_on ?? $histProposal->issued_on),
              'Responsável pela proposta'=>$histInfo?->responsible_name ?: 'Não identificado',
              'E-mail do responsável'=>$histInfo?->responsible_email ?: 'Não identificado',
              'Serviços'=>$servicesText ?: 'Não identificado',
              'Destinatário indicado no documento'=>$histInfo?->recipient_email ?: 'Não comprovado no documento',
            ] as $label=>$value)
                <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>
    <section class="ecp-panel">
        <div class="ecp-head"><div><h2>Memória de cálculo</h2><p class="ecp-sub">Valores extraídos do documento original. A tabela atual não foi aplicada.</p></div></div>
        @if(count($bands)>0)
            <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Faixa</th><th>Mínimo</th><th>Máximo</th><th class="ecp-number">Fixo líquido</th><th class="ecp-number">Líquido / NF</th><th class="ecp-number">NFs aplicadas</th><th class="ecp-number">Subtotal</th></tr></thead><tbody>
                @foreach($bands as $band)
                    <tr><td>{{ $band['faixa'] ?? '—' }}</td><td>{{ $band['min'] ?? '—' }}</td><td>{{ $band['max'] ?? '—' }}</td><td class="ecp-number">{{ $band['fixo'] ?? '—' }}</td><td class="ecp-number">{{ $band['unitario'] ?? '—' }}</td><td class="ecp-number">{{ $band['quantidade'] ?? '—' }}</td><td class="ecp-number">{{ $band['subtotal'] ?? '—' }}</td></tr>
                @endforeach
            </tbody></table></div>
        @else
            <p class="ecp-note">{{ !empty($finance['band_conflict']) ? 'O documento contém tabelas diferentes. Consulte o original para não misturar cenários.' : 'Faixas não estruturadas automaticamente neste documento. Os valores identificados permanecem no resumo e o original está disponível para conferência.' }}</p>
        @endif
        <div class="ecp-two ecp-spaced">
            <div class="ecp-value"><span class="ecp-label">Valor líquido identificado</span><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($finance['net_amount'] ?? null) }}</strong></div>
            <div class="ecp-value ecp-value-main"><span class="ecp-label">{{ $pointOnly ? 'Valor final do serviço' : 'Mensalidade final' }}</span><strong>{{ $pointOnly ? $onceText : $monthlyText }}</strong></div>
        </div>
        @if(count($taxes)>0)
            <div class="ecp-spaced"><h3 style="margin-bottom:10px">Composição tributária original</h3><div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Item</th><th class="ecp-number">Alíquota</th><th class="ecp-number">Valor</th></tr></thead><tbody>@foreach($taxes as $tax)<tr><td>{{ $tax['item'] ?? '—' }}</td><td class="ecp-number">{{ $tax['aliquota'] ?? '—' }}</td><td class="ecp-number">{{ $tax['valor'] ?? '—' }}</td></tr>@endforeach</tbody></table></div></div>
        @elseif(!empty($finance['tax_conflict']))<p class="ecp-note ecp-spaced">Composições tributárias divergentes. Consulte o original.</p>@endif
    </section>
    <section class="ecp-panel">
        <div class="ecp-head"><h2>Condições comerciais</h2></div>
        <dl class="ecp-facts">
            @foreach(['validade'=>'Validade','pagamento'=>'Pagamento','faturamento'=>'Faturamento','prazo'=>'Prazo','dependencia'=>'Dependência','condicao'=>'Condição essencial'] as $key=>$label)
                @if(!empty($conditions[$key]))<div><dt>{{ $label }}</dt><dd>{{ $conditions[$key] }}</dd></div>@endif
            @endforeach
        </dl>
        @if(empty($conditions))<p class="ecp-note">Condições não identificadas de forma estruturada. Consulte o original.</p>@endif
        @if(!empty($conditions['observacoes']))<div class="ecp-note ecp-spaced"><h3 style="margin-bottom:5px">Observações comerciais</h3>{{ $conditions['observacoes'] }}</div>@endif
    </section>
    <section class="ecp-panel">
        <div class="ecp-head"><div><h2>Situação comercial · HubSpot</h2><p class="ecp-sub">Conciliação comercial preservada</p></div></div>
        <dl class="ecp-facts">
            <div><dt>Empresa conciliada</dt><dd>{{ $histInfo?->company_name ?: 'Pendente' }}</dd></div>
            <div><dt>Negócio conciliado</dt><dd>{{ $histInfo?->deal_name ?: 'Pendente' }}</dd></div>
            <div><dt>Etapa na conciliação</dt><dd>{{ $histInfo?->crm_stage ?: 'Não identificada' }}</dd></div>
            <div><dt>Etapa atual no espelho</dt><dd>{{ $histInfo?->current_stage ?: 'Não identificada' }}</dd></div>
        </dl>
        <p class="ecp-note ecp-spaced">Aceita no CRM indica a etapa comercial conciliada, não um registro formal de aceite. @if(!$histInfo?->crm_deal_id) O vínculo com um negócio específico ainda está pendente. @endif</p>
    </section>
    <section class="ecp-panel">
        <div class="ecp-head"><h2>Documentos originais</h2></div>
        @forelse($histDocs as $doc)
            <div class="ecp-doc"><div class="ecp-doc-name">{{ $doc->original_name }}<span class="ecp-sub">{{ str_ends_with(strtolower($doc->original_name),'.pdf') ? 'PDF' : 'Word' }} · {{ number_format((float)$doc->bytes / 1024,0,',','.') }} KB</span></div><a class="ecp-btn" href="{{ $originalUrl }}">Abrir documentos</a></div>
        @empty<p class="ecp-note">Nenhum documento original localizado.</p>@endforelse
    </section>
    <details class="ecp-disclosure">
        <summary>Evidências e texto extraído · conferência técnica</summary>
        <div style="padding:0 16px 16px;display:grid;gap:12px">
            @if(!empty($histEvidence['leitura']))<p class="ecp-note ecp-warning">{{ $histEvidence['leitura'] }}</p>@endif
            @foreach(['monthly_evidence'=>'Mensalidade','one_time_evidence'=>'Valor pontual'] as $key=>$label)
                @php $ev = $finance[$key] ?? ($key === 'monthly_evidence' ? ($histEvidence['valores_mensais'] ?? []) : ($histEvidence['valores_pontuais'] ?? [])); @endphp
                @if(count($ev)>0)<div><h3>{{ $label }}</h3>@foreach($ev as $candidate)<p class="ecp-sub">{{ $candidate['trecho'] ?? '' }}</p>@endforeach</div>@endif
            @endforeach
            @if($histInfo?->calculation)<pre class="ecp-raw">{{ $histInfo->calculation }}</pre>@else<p class="ecp-sub">Sem trecho extraído.</p>@endif
        </div>
    </details>
</div>
