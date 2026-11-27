@php
    $histInfo = app(\App\Services\Proposals\HistoricalProposalReadModel::class)->details((int)$histProposal->id);
    $histDocs = \Illuminate\Support\Facades\DB::table('ec_proposal_documents')->where('proposal_id',$histProposal->id)->whereIn('kind',['historical_pdf','historical_original'])->orderBy('id')->get(['id','original_name','mime','bytes']);
    $histSvc = \App\Services\Proposals\HistoricalProposalReadModel::labels($histInfo?->services ?? $histProposal->service_codes);
    $histEvidence = json_decode($histInfo?->evidence ?? '{}',true) ?: [];
    $reviewV24 = ($histEvidence['review_v24']['version'] ?? null) === 24 ? $histEvidence['review_v24'] : (($histEvidence['review_v23']['version'] ?? null) === 23 ? $histEvidence['review_v23'] : (($histEvidence['review_v22']['version'] ?? null) === 22 ? $histEvidence['review_v22'] : (($histEvidence['review_v21']['version'] ?? null) === 21 ? $histEvidence['review_v21'] : (($histEvidence['review_v20']['version'] ?? null) === 20 ? $histEvidence['review_v20'] : (($histEvidence['review_v19']['version'] ?? null) === 19 ? $histEvidence['review_v19'] : (($histEvidence['review_v18']['version'] ?? null) === 18 ? $histEvidence['review_v18'] : (($histEvidence['review_v17']['version'] ?? null) === 17 ? $histEvidence['review_v17'] : (($histEvidence['review_v16']['version'] ?? null) === 16 ? $histEvidence['review_v16'] : (($histEvidence['review_v15']['version'] ?? null) === 15 ? $histEvidence['review_v15'] : (($histEvidence['review_v14']['version'] ?? null) === 14 ? $histEvidence['review_v14'] : []))))))))));
    $finance = $histEvidence['finance_v2'] ?? [];
    $reviewV13 = $histEvidence['review_v13'] ?? [];
    $priceV13 = $reviewV13['price'] ?? [];
    $signatureV13 = $reviewV13['signature'] ?? [];
    $verifiedV13 = in_array($priceV13['state'] ?? '', ['identified','rounding'], true);
    $finance = array_replace($finance, $reviewV13['volumes'] ?? [], $reviewV13['extra_finance'] ?? [], $verifiedV13 ? ($priceV13['finance'] ?? []) : []);
    if ($verifiedV13 && !empty($priceV13['table']['bands'])) {
        $finance = array_replace($finance, array_intersect_key($priceV13['table'], array_flip(['bands','table_total','table_quantity'])));
        $finance['legacy_table'] = true;
        $finance['band_conflict'] = false;
    }
    if ($verifiedV13 && isset($priceV13['monthly'])) { $finance['monthly_state'] = 'identified'; }
    if ($verifiedV13 && isset($priceV13['one_time'])) { $finance['one_time_state'] = 'identified'; if (($priceV13['kind'] ?? '') === 'one_time_components') { $finance['monthly_state'] = 'not_applicable'; $finance['billing_mode'] = 'historical_bundle_one_time'; $finance['components'] = $priceV13['components']; } }

    $billingV12 = $histEvidence['billing_v12'] ?? [];
    $packageV12 = in_array($billingV12['kind'] ?? '', ['annual_package','recurring_with_extra'], true);
    $identity = $histEvidence['identity_v3'] ?? [];
    $termsV10 = $finance['pricing_terms_v10'] ?? [];
    $percentV10 = ($termsV10['kind'] ?? '') === 'percentage_addon';
    $numberV10 = $histEvidence['finance_v10'] ?? [];
    $conditionalDiscountV10 = !empty($termsV10['conditional_discount']);
    $implantation = $finance['implantation_amount'] ?? null;
    $conditionalImplantation = ($finance['implantation_mode'] ?? '') === 'conditional';
    $singleFee = ($finance['calculation_mode'] ?? '') === 'single_fee';
    $preFixed = ($finance['billing_mode'] ?? '') === 'pre_fixed_reference';
    $annualLabel = isset($finance['reference_year']) ? ('Documentos em '.$finance['reference_year']) : (($finance['annual_basis'] ?? '') === 'projected' ? 'Projeção anual da precificação' : 'Documentos em 12 meses');
    $bundle = ($finance['billing_mode'] ?? '') === 'historical_bundle_one_time';
    $conditionalTotal = $finance['conditional_total'] ?? null;
    $formatBandMoney = static fn($v) => is_string($v) && preg_match('/^\d[\d.]*,\d{2}$/D',$v) === 1 ? 'R$ '.$v : ($v ?: '—');
    $bands = $finance['bands'] ?? [];
    $taxes = $finance['taxes'] ?? [];
    $conditions = $finance['conditions'] ?? [];
    if ($packageV12 && ($billingV12['kind'] ?? '') === 'annual_package' && isset($billingV12['payment_days'])) { $conditions['pagamento'] = 'Pagamento inicial único após assinatura e emissão da NFS-e, em '.$billingV12['payment_days'].' dias da emissão.'; }
    if (empty($conditions['pagamento']) && !empty($histEvidence['conditions_v4']['payment'])) { $conditions['pagamento'] = $histEvidence['conditions_v4']['payment']; }
    $serviceNames = ['LICENCIAMENTO'=>'Licenciamento','RETROATIVAS'=>'Retroativas','ACOMPANHAMENTO'=>'Acompanhamento','DFE'=>'DF-e','NFSE'=>'NFS-e'];
    $servicesText = implode(', ', array_map(fn($code) => $bundle && $code === 'ACOMPANHAMENTO' ? 'Acompanhamento retroativo' : ($serviceNames[$code] ?? $code), $histSvc));
    $title = $histInfo?->title ?: ($histDocs->first()?->original_name ?: 'Proposta histórica');
    $formal = $histProposal->status === 'accepted';
    // EC_ATTACHMENT_V29
    $crmAccepted = in_array($histProposal->status,['imported','issued','sent','partially_accepted'],true) && \App\Services\Proposals\ProposalAttachmentAcceptanceV29::verified((int) $histProposal->id);
    $priorCrmV29 = !$crmAccepted && $histInfo?->commercial_status === 'accepted_crm';
    $monthly = $histInfo?->monthly_amount;
    $auditV11 = $histEvidence['audit_v11'] ?? [];
    $monthlyAuditV11 = $auditV11['monthly'] ?? [];
    $issueAuditV11 = $auditV11['issue'] ?? [];
    $issueAuditV11 = $reviewV13['issue'] ?? $issueAuditV11;
    if ($verifiedV13) { $monthlyAuditV11 = []; }
    $useV13Price = !$packageV12 && !$percentV10 && (isset($priceV13['monthly']) || isset($priceV13['one_time']) || !empty($priceV13['components']) || (!empty($priceV13['evidence']) && $monthly === null && $histInfo?->one_time_amount === null));
    $issueDisplayV13 = $histInfo?->issued_on ?? $histProposal->issued_on ?? ($issueAuditV11['date'] ?? null);
    $issueTextV13 = $issueDisplayV13 ? \App\Services\Proposals\HistoricalProposalReadModel::date($issueDisplayV13) : (($issueAuditV11['state'] ?? '') === 'conflict' ? 'A confirmar: '.implode(' / ', array_map(fn($date) => \App\Services\Proposals\HistoricalProposalReadModel::date($date), $issueAuditV11['candidates'] ?? [])) : 'Não identificada');
    $quoteConflictV11 = in_array($monthlyAuditV11['state'] ?? '', ['declared_table_conflict','conflict','conditional_review','previous_review'], true);
    $declaredV11 = $monthlyAuditV11['declared_values'] ?? [];
    $displayDeclaredV11 = $quoteConflictV11 && count($declaredV11) === 1;
    $tableAuditV11 = $monthlyAuditV11['table'] ?? [];
    if ($quoteConflictV11 && !empty($tableAuditV11['bands'])) { $bands = $tableAuditV11['bands']; }
    $displayTableTotalV11 = $quoteConflictV11 && isset($tableAuditV11['table_total']) ? $tableAuditV11['table_total'] : ($finance['table_total'] ?? null);
    $displayTableQuantityV11 = $quoteConflictV11 && isset($tableAuditV11['table_quantity']) ? $tableAuditV11['table_quantity'] : ($finance['table_quantity'] ?? null);
    $preFixed = $preFixed || !empty($monthlyAuditV11['prefixed']);
    $once = $histInfo?->one_time_amount;
    $pointOnly = $bundle || $histSvc === ['RETROATIVAS'] || ($finance['monthly_state'] ?? '') === 'not_applicable';
    $monthlyText = $percentV10 ? ('+'.str_replace('.', ',', (string)($termsV10['percent'] ?? '')).'%') : ($monthly !== null ? \App\Services\Proposals\HistoricalProposalReadModel::money($monthly) : ($pointOnly ? 'Não se aplica' : 'Pendente'));
    if (!$pointOnly && !$percentV10 && $displayDeclaredV11) { $monthlyText = \App\Services\Proposals\HistoricalProposalReadModel::money($declaredV11[0]); }
    $onceText = $once !== null ? \App\Services\Proposals\HistoricalProposalReadModel::money($once) : 'Não identificado';
    if ($useV13Price && isset($priceV13['monthly'])) { $monthlyText = \App\Services\Proposals\HistoricalProposalReadModel::money($priceV13['monthly']); }
    if ($useV13Price && isset($priceV13['one_time'])) { $onceText = \App\Services\Proposals\HistoricalProposalReadModel::money($priceV13['one_time']); }

@endphp
@include('proposals.attachment-evidence-v29',['attachmentProposalId'=>$histProposal->id])
@include('proposals.historical.styles-v2')
<div class="ecp-history">
    <div class="ecp-detail-top">
        <div>
            <a class="ecp-link" href="{{ url('/propostas') }}">← Todas as propostas</a>
            <div class="ecp-detail-title">{{ str_replace('_',' ',(string)$title) }}</div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span class="ecp-chip {{ $formal || $crmAccepted ? 'ecp-chip-accepted' : 'ecp-chip-sent' }}">{{ $formal ? 'Aceita formalmente' : ($crmAccepted ? 'Aceita por anexo' : ($priorCrmV29 ? 'Aceite anterior · conferir anexo' : 'Emitida / enviada')) }}</span><span class="ecp-sub">{{ $reviewV24['client'] ?? $identity['client_legal_name'] ?? $histProposal->client_name }} @if($reviewV24) · {{ $reviewV24['cover_number'] === 'Não informado' ? 'Sem numeração na capa' : 'EC '.$reviewV24['cover_number'] }} @elseif(!empty($numberV10['document_number'])) · EC {{ $numberV10['document_number'] }} @elseif($histInfo?->historic_number) · EC {{ $histInfo->historic_year }} / {{ $histInfo->historic_number }} @endif</span></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if($histDocs->first())<a class="ecp-btn ecp-btn-primary" href="{{ route('proposals.document',[$histProposal,$histDocs->first()->id]) }}">Baixar documento original</a>@endif
            <form method="POST" action="{{ route('proposals.duplicate',$histProposal) }}">@csrf<button type="submit" class="ecp-btn">Duplicar como novo</button></form>
        </div>
    </div>
    @if($reviewV24)
        @include('proposals.historical.review-detail-v24')
    @else
    @if($packageV12)
        @include('proposals.historical.billing-summary-v12')
    @elseif($useV13Price)
        @include('proposals.historical.review-summary-v13')
    @else
    <div class="ecp-summary">
        <div class="ecp-value ecp-value-main"><span class="ecp-label">{{ $percentV10 ? 'Adicional sobre mensalidade vigente' : ($pointOnly ? ($bundle ? 'Valor total dos serviços · pagamento único' : ($conditionalDiscountV10 ? 'Serviço · com desconto condicional' : 'Valor do serviço · pagamento único')) : ($displayDeclaredV11 ? 'Investimento mensal declarado' : ($preFixed ? 'Mensalidade pré-fixada' : 'Mensalidade final'))) }}</span><strong>{{ $pointOnly ? $onceText : $monthlyText }}@if(!$pointOnly && !$percentV10 && ($monthly !== null || $displayDeclaredV11))<span class="ecp-sub" style="display:inline">/mês</span>@endif</strong><span class="ecp-sub">{{ $displayDeclaredV11 ? 'Conforme texto do PDF · requer conferência' : ($percentV10 ? 'Percentual condicional, sem valor fixo em reais' : (($finance['monthly_state'] ?? '') === 'previous_review' || ($pointOnly && ($finance['one_time_state'] ?? '') === 'previous_review') ? 'Valor anterior — conferir original' : ($bundle && !empty($finance['bundle_estimated']) ? 'Valor estimado no documento' : 'Valor histórico do documento'))) }}</span></div>
        @if($percentV10)<div class="ecp-value"><span class="ecp-label">Desenvolvimento · pagamento único</span><strong>{{ $onceText }}</strong><span class="ecp-sub">Valor apresentado para entrega do projeto</span></div>
        @else<div class="ecp-value"><span class="ecp-label">{{ $conditionalImplantation ? 'Implantação condicional' : 'Implantação' }}</span><strong>{{ $implantation === null ? (($finance['implantation_state'] ?? '') === 'conflict' ? 'Valores divergentes' : 'Não informado') : \App\Services\Proposals\HistoricalProposalReadModel::money($implantation) }}</strong><span class="ecp-sub">{{ $conditionalImplantation ? 'Cobrada somente na condição prevista no documento' : ($implantation !== null ? 'Conforme documento original' : 'Sem valor de implantação identificado no documento') }}</span></div>@endif
        <div class="ecp-value"><span class="ecp-label">{{ $percentV10 ? 'Base da cobrança percentual' : ($pointOnly ? ($bundle ? 'Base das consultas retroativas' : 'Base total de documentos') : ($finance['base_label'] ?? 'Base mensal de referência')) }}</span><strong>{{ $percentV10 ? 'Mensalidade vigente' : (isset($finance['base_quantity']) && is_numeric($finance['base_quantity']) ? number_format((float)$finance['base_quantity'],0,',','.') : 'Não identificada') }}</strong><span class="ecp-sub">{{ $percentV10 ? 'Base em reais não informada no documento' : 'Documentos fiscais · conforme proposta' }}</span></div>
    </div>
    @if($pointOnly && $once === null)
        <div class="ecp-note ecp-warning">{{ ($finance['one_time_state'] ?? '') === 'conflict' ? 'Valores do serviço divergentes. Confira o original.' : 'Valor único ainda não identificado. Nenhuma mensalidade ou tarifa foi presumida.' }}</div>
    @endif
    @if(!$pointOnly && !$percentV10 && !$quoteConflictV11 && ($monthly === null || ($finance['monthly_state'] ?? '') === 'previous_review'))
        <div class="ecp-note ecp-warning">{{ ($finance['monthly_state'] ?? '') === 'conflict' ? 'Foram identificados valores mensais divergentes. Consulte as evidências e o documento original.' : (($finance['monthly_state'] ?? '') === 'previous_review' ? 'A mensalidade anterior foi preservada porque aparece no documento, mas a associação ao rótulo não pôde ser confirmada pela nova leitura.' : 'Mensalidade ainda não identificada com segurança. Consulte o documento original; nenhum preço foi estimado.') }}</div>
    @endif
    @if($quoteConflictV11 && !$pointOnly && !$percentV10)
        @include('proposals.historical.price-audit-v11')
    @endif
    @endif
    @if($useV13Price) @include('proposals.historical.review-evidence-v13') @endif
    @if(($issueAuditV11['state'] ?? '') === 'explicit_with_other_dates')
        <div class="ecp-note">Emissão explicitamente indicada: {{ \App\Services\Proposals\HistoricalProposalReadModel::date($issueAuditV11['date']) }}. Outras datas no original: {{ implode(' / ',array_map(fn($date) => \App\Services\Proposals\HistoricalProposalReadModel::date($date),$issueAuditV11['candidates'] ?? [])) }}. Confira se houve revisão.</div>
    @endif
    @if(!empty($issueAuditV11['year_mismatch']))
        <div class="ecp-note">Ano da numeração: {{ $issueAuditV11['number_year'] }} · emissão indicada no documento: {{ \App\Services\Proposals\HistoricalProposalReadModel::date($issueAuditV11['date']) }}. Número histórico preservado.</div>
    @elseif(($issueAuditV11['state'] ?? '') === 'conflict')
        <div class="ecp-note ecp-warning">Datas de emissão diferentes no documento: {{ implode(' / ',array_map(fn($date) => \App\Services\Proposals\HistoricalProposalReadModel::date($date),$issueAuditV11['candidates'] ?? [])) }}. Confira o original.</div>
    @endif
    @if(!empty($finance['annual_reference_conflict']))
        <div class="ecp-note ecp-warning">O documento apresenta volumes anuais diferentes: {{ implode(' / ', array_map(fn($q) => number_format((float)$q,0,',','.'), $finance['annual_reference_conflict'])) }}. A projeção da seção de investimento é a base exibida, coerente com a tabela mensal. Conferir o original antes de usar em contrato.</div>
    @endif
    @if(!empty($numberV10['number_warning']))<div class="ecp-note ecp-warning">Número da capa: EC {{ $numberV10['number_warning']['document_number'] }} · número no arquivo: EC {{ $numberV10['number_warning']['filename_number'] }}. Original preservado, sem renumerar ou mesclar propostas.</div>@endif
    @if(!empty($reviewV13['warnings']))<details class="ecp-disclosure ecp-spaced"><summary>Conferência documental · {{ count($reviewV13['warnings']) }} observação(ões)</summary><div style="padding:8px 16px">@foreach($reviewV13['warnings'] as $warning)<p class="ecp-sub">{{ $warning }}</p>@endforeach</div></details>@endif
    <section class="ecp-panel">
        <div class="ecp-head"><div><h2>Informações da proposta</h2><p class="ecp-sub">Identificação comercial e emissão</p></div></div>
        <dl class="ecp-facts">
            @foreach([
              'Razão social'=>$identity['client_legal_name'] ?? $histProposal->client_name,
              'Nome no cadastro'=>$histProposal->client_name,
              'CNPJ'=>$histProposal->client_cnpj ?: 'Não identificado',
              'Contato do cliente'=>$identity['recipient_name'] ?? $histProposal->client_contact ?? 'Não identificado',
              'Número histórico'=>!empty($numberV10['document_number']) ? ('EC '.$numberV10['document_number'].' · conforme capa') : ($histInfo?->historic_number ? ('EC '.$histInfo->historic_year.' / '.$histInfo->historic_number) : 'Não identificado'),
              'Emissão'=>$issueTextV13,
              'Responsável pela proposta'=>$signatureV13['name'] ?? (!empty($reviewV13['suspect_customer_name']) ? 'A confirmar (nome anterior coincide com cliente)' : ($histInfo?->responsible_name ?: ($identity['responsible_name'] ?? ($histProposal->sales_responsible ?: 'Não identificado')))),
              'E-mail do responsável'=>$signatureV13['email'] ?? ($histInfo?->responsible_email ?: ($identity['responsible_email'] ?? 'Não identificado')),
              'Serviços'=>$servicesText ?: 'Não identificado',
              'E-mail do destinatário indicado'=>$histInfo?->recipient_email ?: ($identity['recipient_email'] ?? ($histProposal->client_email ?: 'Não identificado')),
              'Valor pontual adicional'=>$packageV12 ? \App\Services\Proposals\HistoricalProposalReadModel::money($billingV12['retroactive'] ?? $billingV12['historical_import'] ?? null) : (!$pointOnly && $once !== null && ($implantation === null || $once != $implantation) ? $onceText : 'Não identificado / não se aplica'),
              ($packageV12 ? 'Documentos em 12 meses' : ($pointOnly ? 'Documentos do período' : $annualLabel))=>$packageV12 ? (isset($billingV12['annual_quantity']) ? number_format((float)$billingV12['annual_quantity'],0,',','.') : 'Não identificado') : ($pointOnly ? (isset($finance['base_quantity']) ? number_format((float)$finance['base_quantity'],0,',','.') : 'Não identificado') : (isset($finance['annual_quantity']) ? number_format((float)$finance['annual_quantity'],0,',','.') : 'Não identificado')),
            ] as $label=>$value)
                <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
        <p class="ecp-note ecp-spaced">Contato e destinatário: capa da proposta. Responsável comercial: assinatura ou bloco comercial final. Volumes: base informada no documento. O destinatário indicado não comprova envio efetivo.</p>
    </section>
    <section class="ecp-panel">
        <div class="ecp-head"><div><h2>Memória de cálculo</h2><p class="ecp-sub">Valores extraídos do documento original. A tabela atual não foi aplicada.</p></div></div>
        @if($packageV12)
            @include('proposals.historical.billing-memory-v12')
        @elseif($useV13Price && !empty($priceV13['components']))
            @include('proposals.historical.review-components-v13')
        @else
        @if($preFixed)
            <div class="ecp-note" style="margin-bottom:12px"><strong>Modalidade pré-fixada:</strong> estimativa baseada no volume de referência do documento. A proposta também oferece a modalidade pós-fixada, com valor conforme utilização. Nenhuma modalidade foi presumida como contratada.</div>
        @endif
        @if($percentV10)
            @include('proposals.historical.commercial-terms-v10')
        @elseif($bundle && !empty($finance['components']))
            @include('proposals.historical.service-components-v9')
        @elseif(count($bands)>0)
            <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Faixa</th><th>Mínimo</th><th>Máximo</th><th class="ecp-number">{{ (!empty($finance['legacy_table']) || ($quoteConflictV11 && !empty($tableAuditV11))) ? 'Investimento fixo' : 'Fixo líquido' }}</th><th class="ecp-number">{{ (!empty($finance['legacy_table']) || ($quoteConflictV11 && !empty($tableAuditV11))) ? 'Valor por NF excedente' : 'Líquido / NF' }}</th><th class="ecp-number">NFs aplicadas</th><th class="ecp-number">Subtotal</th></tr></thead><tbody>
                @foreach($bands as $band)
                    <tr><td>{{ $band['faixa'] ?? '—' }}</td><td>{{ $band['min'] ?? '—' }}</td><td>{{ $band['max'] ?? '—' }}</td><td class="ecp-number">{{ $formatBandMoney($band['fixo'] ?? null) }}</td><td class="ecp-number">{{ $formatBandMoney($band['unitario'] ?? null) }}</td><td class="ecp-number">{{ $band['quantidade'] ?? '—' }}</td><td class="ecp-number">{{ $formatBandMoney($band['subtotal'] ?? null) }}</td></tr>
                @endforeach
                @if($displayTableTotalV11 !== null)<tr><td colspan="5"><strong>{{ $preFixed ? 'Total da tabela-base pré-fixada' : 'Total indicado no documento' }}</strong></td><td class="ecp-number"><strong>{{ $displayTableQuantityV11 ?? '—' }}</strong></td><td class="ecp-number"><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($displayTableTotalV11) }}</strong></td></tr>@endif
            </tbody></table></div>
        @elseif($pointOnly && $singleFee && empty($termsV10))
            <div class="ecp-note">Modelo histórico de prestação de serviço com preço único. Não há tabela progressiva de faixas prevista no documento.</div>
            <div class="ecp-scroll ecp-spaced"><table class="ecp-table"><thead><tr><th>Componente</th><th>Condição</th><th class="ecp-number">Valor</th></tr></thead><tbody>
                <tr><td>Consultas retroativas</td><td>Pagamento único</td><td class="ecp-number"><strong>{{ $onceText }}</strong></td></tr>
                @if($implantation !== null)<tr><td>Implantação</td><td>{{ $conditionalImplantation ? 'Condicional · conforme proposta' : 'Conforme proposta' }}</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($implantation) }}</td></tr>@endif
                @if($conditionalImplantation && $conditionalTotal !== null)<tr><td><strong>Total com implantação condicional</strong></td><td>Somente se a condição ocorrer</td><td class="ecp-number"><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($conditionalTotal) }}</strong></td></tr>@endif
            </tbody></table></div>
        @elseif(empty($termsV10))
            <p class="ecp-note">{{ !empty($finance['band_conflict']) ? 'O documento contém tabelas diferentes. Consulte o original para não misturar cenários.' : 'Faixas não estruturadas automaticamente neste documento. Os valores identificados permanecem no resumo e o original está disponível para conferência.' }}</p>
        @endif
        @if(!$percentV10 && ($termsV10['kind'] ?? '') === 'one_time')@include('proposals.historical.commercial-terms-v10')@endif
        @if(!empty($finance['legacy_table']))<p class="ecp-note ecp-spaced">Tabela histórica: os valores são os apresentados no documento; a composição tributária não foi presumida.</p>@endif
        @unless($percentV10 || !empty($termsV10) || $bundle || ($pointOnly && $singleFee))
        <div class="ecp-two ecp-spaced">
            <div class="ecp-value"><span class="ecp-label">{{ !empty($finance['legacy_table']) ? 'Implantação' : 'Valor líquido identificado' }}</span><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money(!empty($finance['legacy_table']) ? $implantation : ($finance['net_amount'] ?? null)) }}</strong></div>
            <div class="ecp-value ecp-value-main"><span class="ecp-label">{{ $pointOnly ? 'Valor final do serviço' : ($preFixed ? 'Estimativa pré-fixada' : 'Mensalidade final') }}</span><strong>{{ $pointOnly ? $onceText : $monthlyText }}</strong></div>
        </div>
        @endunless
        @if($preFixed)
            <details class="ecp-disclosure ecp-spaced"><summary>Modalidade pós-fixada e exemplos (não somam ao valor principal)</summary>
                <div style="padding:12px 16px"><p class="ecp-sub">{{ $finance['post_fixed_terms'] ?? 'Valor conforme quantidade de documentos.' }}</p>
                    @foreach(($finance['illustrative_tables'] ?? []) as $scenario)
                        @if(isset($scenario['table_quantity'],$scenario['table_total']))
                            <div class="ecp-note ecp-spaced">{{ number_format((float)$scenario['table_quantity'],0,',','.') }} documentos · {{ \App\Services\Proposals\HistoricalProposalReadModel::money($scenario['table_total']) }} (exemplo, não valor contratado)</div>
                        @endif
                    @endforeach
                    <p class="ecp-sub ecp-spaced">As simulações completas permanecem no documento original.</p>
                </div>
            </details>
        @endif
        @if(count($taxes)>0)
            <div class="ecp-spaced"><h3 style="margin-bottom:10px">Composição tributária original</h3><div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Item</th><th class="ecp-number">Alíquota</th><th class="ecp-number">Valor</th></tr></thead><tbody>@foreach($taxes as $tax)<tr><td>{{ $tax['item'] ?? '—' }}</td><td class="ecp-number">{{ $tax['aliquota'] ?? '—' }}</td><td class="ecp-number">{{ $tax['valor'] ?? '—' }}</td></tr>@endforeach</tbody></table></div></div>
        @elseif(!empty($finance['tax_conflict']))<p class="ecp-note ecp-spaced">Composições tributárias divergentes. Consulte o original.</p>@endif
        @endif
    </section>
    <section class="ecp-panel">
        <div class="ecp-head"><h2>Condições comerciais</h2></div>
        <dl class="ecp-facts">
            @foreach(['validade'=>'Validade','teste'=>'Período de teste','pagamento'=>'Pagamento','faturamento'=>'Faturamento','prazo'=>'Prazo de execução','periodo_consultado'=>'Período consultado','implantacao_condicional'=>'Condição da implantação','dependencia'=>'Dependência','condicao'=>'Condição essencial'] as $key=>$label)
                @if(!empty($conditions[$key]))<div><dt>{{ $label }}</dt><dd>{{ $conditions[$key] }}</dd></div>@endif
            @endforeach
        </dl>
        @if(empty($conditions))<p class="ecp-note">Condições não identificadas de forma estruturada. Consulte o original.</p>@endif
        @if(!empty($conditions['observacoes']))<div class="ecp-note ecp-spaced"><h3 style="margin-bottom:5px">Observações comerciais</h3>{{ $conditions['observacoes'] }}</div>@endif
    </section>
    @endif {{-- revisão documental V16; demais propostas usam o fluxo V13 --}}
    @include('proposals.historical.crm-v4')
    <section class="ecp-panel">
        <div class="ecp-head"><h2>Documentos originais</h2></div>
        @forelse($histDocs as $doc)
            <div class="ecp-doc"><div class="ecp-doc-name">{{ $doc->original_name }}<span class="ecp-sub">{{ str_ends_with(strtolower($doc->original_name),'.pdf') ? 'PDF' : 'Word' }} · {{ number_format((float)$doc->bytes / 1024,0,',','.') }} KB</span></div><a class="ecp-btn" href="{{ route('proposals.document',[$histProposal,$doc->id]) }}">Baixar documento</a></div>
        @empty<p class="ecp-note">Nenhum documento original localizado.</p>@endforelse
    </section>
    <details class="ecp-disclosure"><summary>Histórico auditável do cadastro</summary><div style="padding:0 16px 16px">
        @foreach($histProposal->events as $event)<p class="ecp-sub">{{ $event->event }} · {{ $event->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} @if($event->actor_id)· Usuário #{{ $event->actor_id }}@endif</p>@endforeach
    </div></details>
    <details class="ecp-disclosure">
        <summary>Evidências e texto extraído · conferência técnica</summary>
        <div style="padding:0 16px 16px;display:grid;gap:12px">
            @if(!empty($identity['previous_projection']))<div><h3>Identificação anterior substituída ou pendente</h3>@foreach(['responsible_name'=>'Responsável','responsible_email'=>'E-mail comercial','recipient_email'=>'Destinatário'] as $previousKey=>$previousLabel)@if(isset($identity['previous_projection'][$previousKey]))<p class="ecp-sub">{{ $previousLabel }}: {{ $identity['previous_projection'][$previousKey] }} (não confirmado pela nova leitura)</p>@endif @endforeach</div>@endif
            @if(!empty($histEvidence['leitura']))<p class="ecp-note ecp-warning">{{ $histEvidence['leitura'] }}</p>@endif
            @foreach(['monthly_evidence'=>'Mensalidade','one_time_evidence'=>'Implantação / valor pontual'] as $key=>$label)
                @php $ev = $finance[$key] ?? ($key === 'monthly_evidence' ? ($histEvidence['valores_mensais'] ?? []) : ($histEvidence['valores_pontuais'] ?? [])); @endphp
                @if(count($ev)>0)<div><h3>{{ $label }}</h3>@foreach($ev as $candidate)<p class="ecp-sub">{{ $candidate['trecho'] ?? '' }}</p>@endforeach</div>@endif
            @endforeach
            @if($histInfo?->calculation)<pre class="ecp-raw">{{ $histInfo->calculation }}</pre>@else<p class="ecp-sub">Sem trecho extraído.</p>@endif
        </div>
    </details>
</div>
