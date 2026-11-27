{{-- EC_PROPOSALS_V28 --}}
@php
    $histData = app(\App\Services\Proposals\HistoricalProposalReadModel::class)->listing(request());
    $histPage = $histData['page'];
    $histFinanceRows = \Illuminate\Support\Facades\DB::table('ec_proposal_history_metadata')
        ->whereIn('proposal_id', $histPage->getCollection()->pluck('id')->all())
        ->get(['proposal_id', 'evidence', 'crm_company_id', 'crm_deal_id'])->keyBy('proposal_id');
    $crmCompanyNames = \Illuminate\Support\Facades\DB::table('hubspot_companies')->whereIn('id',$histFinanceRows->pluck('crm_company_id')->filter()->unique()->values()->all())->pluck('name','id');
    $crmDealNames = \Illuminate\Support\Facades\DB::table('hubspot_deals')->whereIn('id',$histFinanceRows->pluck('crm_deal_id')->filter()->unique()->values()->all())->get(['id','name','stage_label'])->keyBy('id');
    $serviceNames = ['LICENCIAMENTO'=>'Licenciamento','RETROATIVAS'=>'Retroativas','ACOMPANHAMENTO'=>'Acompanhamento','DFE'=>'DF-e','NFSE'=>'NFS-e'];
@endphp
@include('proposals.historical.styles-v2')
<div class="ecp-history">
    <div class="ecp-kpis" aria-label="Indicadores de propostas">
        @foreach (['total'=>['Propostas cadastradas','Inclui o histórico importado'],'draft'=>['Rascunhos','Em preparação'],'sent'=>['Emitidas / enviadas','Sem aceite identificado'],'accepted'=>['Aceitas','Aceite formal ou anexo idêntico']] as $histKey=>$histCard)
            <a class="ecp-kpi {{ $histKey === 'accepted' ? 'ecp-kpi-accepted' : '' }} {{ ($histData['status'] === $histKey || ($histKey === 'total' && $histData['status'] === '')) ? 'ecp-kpi-active' : '' }}" href="{{ url('/propostas').($histKey === 'total' ? '' : '?hist_status='.$histKey) }}">
                <span class="ecp-label">{{ $histCard[0] }}</span>
                <strong>{{ number_format($histData['counts'][$histKey],0,',','.') }}</strong>
                <span class="ecp-sub">{{ $histCard[1] }}</span>
            </a>
        @endforeach
    </div>
    @include('proposals.crm-summary-v28')
    <section class="ecp-panel">
        <div class="ecp-head">
            <div><h2>Todas as propostas</h2><p class="ecp-sub">Valores e documentos originais, sem recalcular o histórico.</p></div>
            <a class="ecp-btn" href="{{ route('proposals.import.page') }}">Importar histórico</a>
        </div>
        <form action="{{ url('/propostas') }}" method="GET" class="ecp-filter">
            <label class="ecp-search">Cliente, contato, responsável ou número<input name="hist_q" value="{{ $histData['q'] }}" placeholder="Pesquisar proposta..." maxlength="200"></label>
            <label>Serviço<select name="hist_service"><option value="">Todos</option>@foreach($serviceNames as $key=>$value)<option value="{{ $key }}" @selected($histData['service']===$key)>{{ $value }}</option>@endforeach</select></label>
            <label>Situação<select name="hist_status"><option value="">Todas</option>@foreach(['draft'=>'Rascunho','sent'=>'Emitida / enviada','accepted'=>'Aceita'] as $key=>$value)<option value="{{ $key }}" @selected($histData['status']===$key)>{{ $value }}</option>@endforeach</select></label>
            <label>Ano<input name="hist_year" value="{{ $histData['year'] }}" pattern="20[0-9]{2}" inputmode="numeric" maxlength="4" placeholder="Todos"></label>
            <div class="ecp-filter-actions"><button class="ecp-btn ecp-btn-primary" type="submit">Buscar</button><a class="ecp-btn" href="{{ url('/propostas') }}">Limpar</a></div>
        </form>
        <div class="ecp-scroll">
            <table class="ecp-table ecp-list">
                <thead><tr><th>Proposta</th><th>Cliente</th><th>Responsável</th><th>Serviço</th><th>Mensalidade / valor</th><th>Situação</th><th>Emissão</th><th>Ações</th></tr></thead>
                <tbody>
                @forelse($histPage as $histProposal)
                    @php
                        $historical = $histProposal->origin === 'imported';
                        $docName = $histData['names']->get($histProposal->id)?->first()?->original_name;
                        $title = $histProposal->getAttribute('hist_title') ?: ($historical ? ($docName ?: 'Proposta histórica') : ($histProposal->legacy_code ?: 'Proposta '.$histProposal->id));
                        $svc = \App\Services\Proposals\HistoricalProposalReadModel::labels($histProposal->getAttribute('hist_services') ?? $histProposal->service_codes);
                        $monthly = $historical ? $histProposal->getAttribute('hist_monthly_amount') : $histProposal->monthly_final;
                        $once = $historical ? $histProposal->getAttribute('hist_one_time_amount') : $histProposal->one_time_final;
                        $formal = $histProposal->status === 'accepted';
                        // EC_ATTACHMENT_V29
                        $crmAccepted = (bool) $histProposal->getAttribute('hist_attachment_accepted') && in_array($histProposal->status,['imported','issued','sent','partially_accepted'],true);
                        $priorCrmV29 = $historical && !$crmAccepted && $histProposal->getAttribute('hist_commercial_status') === 'accepted_crm';
                        $crmHistoryRow = $histFinanceRows->get($histProposal->id);
                        $rawEvidence = $crmHistoryRow?->evidence;
                        $listCrmCompany = $crmHistoryRow?->crm_company_id ? $crmCompanyNames->get($crmHistoryRow->crm_company_id) : null;
                        $listCrmDeal = $crmHistoryRow?->crm_deal_id ? $crmDealNames->get($crmHistoryRow->crm_deal_id) : null;
                        $evidence = json_decode(is_string($rawEvidence) ? $rawEvidence : '{}', true) ?: [];
                        $reviewV24 = $historical ? (($evidence['review_v24']['version'] ?? null) === 24 ? $evidence['review_v24'] : ((($evidence['review_v23']['version'] ?? null) === 23 ? $evidence['review_v23'] : ((($evidence['review_v22']['version'] ?? null) === 22 ? $evidence['review_v22'] : (((($evidence['review_v21']['version'] ?? null) === 21 ? $evidence['review_v21'] : ((($evidence['review_v20']['version'] ?? null) === 20 ? $evidence['review_v20'] : ((($evidence['review_v19']['version'] ?? null) === 19 ? $evidence['review_v19'] : (($evidence['review_v18']['version'] ?? null) === 18 ? $evidence['review_v18'] : (($evidence['review_v17']['version'] ?? null) === 17 ? $evidence['review_v17'] : (($evidence['review_v16']['version'] ?? null) === 16 ? $evidence['review_v16'] : (($evidence['review_v15']['version'] ?? null) === 15 ? $evidence['review_v15'] : (($evidence['review_v14']['version'] ?? null) === 14 ? $evidence['review_v14'] : []))))))))))))))))) : [];
                        $finance = $evidence['finance_v2'] ?? [];
    $reviewV13 = $evidence['review_v13'] ?? [];
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

                        $billingV12 = $historical ? ($evidence['billing_v12'] ?? []) : [];
                        $packageV12 = in_array($billingV12['kind'] ?? '', ['annual_package','recurring_with_extra'], true);
                        $identity = $evidence['identity_v3'] ?? [];
                        $nativeNumbers = (array) ($histProposal->proposal_numbers ?? []);
                        if (!$historical && $nativeNumbers) { $title = 'Proposta EC '.implode(' / ', $nativeNumbers); }
                        $responsible = $historical ? ($histProposal->getAttribute('hist_responsible_name') ?: ($identity['responsible_name'] ?? $histProposal->sales_responsible)) : $histProposal->sales_responsible;
                        $contact = $historical ? ($identity['recipient_name'] ?? $histProposal->client_contact) : $histProposal->client_contact;
                        $responsibleEmail = $historical ? ($histProposal->getAttribute('hist_responsible_email') ?: ($identity['responsible_email'] ?? null)) : null;
                        $implantation = $finance['implantation_amount'] ?? null;
                        $conditionalImplantation = ($finance['implantation_mode'] ?? '') === 'conditional';
                        $nativeItems = !$historical ? (array) (($histProposal->price_snapshot ?? [])['items'] ?? []) : [];
                        $state = $finance['monthly_state'] ?? '';
                        $auditV11 = $evidence['audit_v11'] ?? [];
                        $monthlyAuditV11 = $auditV11['monthly'] ?? [];
                        if ($verifiedV13) { $monthlyAuditV11 = []; }
                        if ($historical && !empty($signatureV13['name'])) { $responsible = $signatureV13['name']; $responsibleEmail = $signatureV13['email'] ?? $responsibleEmail; } elseif ($historical && !empty($reviewV13['suspect_customer_name'])) { $responsible = 'A confirmar'; $responsibleEmail = null; }
                        $useV13Price = $historical && !$packageV12 && (isset($priceV13['monthly']) || isset($priceV13['one_time']) || !empty($priceV13['components']) || (!empty($priceV13['evidence']) && $monthly === null && $once === null));
                        $dateV13 = $reviewV13['issue']['date'] ?? null;
                        if ($reviewV24) { $responsible = $reviewV24['signer']; $contact = $reviewV24['contact']; $responsibleEmail = $reviewV24['responsible_email'] ?? $responsibleEmail; if (!str_starts_with(strtolower((string)$responsibleEmail), strtolower(explode(' ', $responsible)[0]).'@')) { $responsibleEmail = null; } }
                        $quoteConflictV11 = $historical && in_array($monthlyAuditV11['state'] ?? '', ['declared_table_conflict','conflict','conditional_review','previous_review'], true);
                        $declaredV11 = $monthlyAuditV11['declared_values'] ?? [];
                        $tableValuesV11 = $monthlyAuditV11['table_values'] ?? [];
                        $termsV10 = $finance['pricing_terms_v10'] ?? [];
                        $percentV10 = $historical && ($termsV10['kind'] ?? '') === 'percentage_addon';
                        $numberV10 = $evidence['finance_v10'] ?? [];
                        $bundle = $historical && ($finance['billing_mode'] ?? '') === 'historical_bundle_one_time';
                        $statusText = $formal ? 'Aceita formalmente' : ($crmAccepted ? 'Aceita por anexo' : ($historical ? ($priorCrmV29 ? 'Aceite anterior · conferir anexo' : 'Emitida / enviada') : match($histProposal->status){'draft'=>'Rascunho','issued','sent'=>'Emitida / enviada',default=>$histProposal->status}));
                        $statusClass = $formal || $crmAccepted ? 'ecp-chip-accepted' : ($historical || in_array($histProposal->status,['issued','sent'],true) ? 'ecp-chip-sent' : '');
                    @endphp
                    <tr>
                        <td><div class="ecp-name" title="{{ $title }}">{{ str_replace('_',' ',(string)$title) }}</div>@if($reviewV24)<span class="ecp-sub">{{ $reviewV24['cover_number'] === 'Não informado' ? 'Sem numeração na capa' : 'Capa: EC '.$reviewV24['cover_number'] }}@if($reviewV24['filename_number'] !== $reviewV24['cover_number']) · arquivo EC {{ $reviewV24['filename_number'] }}@endif</span>@elseif(!empty($numberV10['number_warning']))<span class="ecp-sub">Capa: EC {{ $numberV10['document_number'] }} · conferir número do arquivo</span>@elseif($histProposal->getAttribute('hist_number'))<span class="ecp-sub">EC {{ $histProposal->getAttribute('hist_year') }} / {{ $histProposal->getAttribute('hist_number') }}</span>@elseif(!empty($numberV10['document_number']))<span class="ecp-sub">EC {{ $numberV10['document_number'] }} · capa</span>@endif</td>
                        <td><div class="ecp-client">{{ $reviewV24['client'] ?? $identity['client_legal_name'] ?? $histProposal->client_name }}</div>@if($contact)<span class="ecp-sub">Contato: {{ $contact }}</span>@endif</td>
                        <td>{{ $responsible ?: 'Não identificado' }}@if($responsibleEmail)<span class="ecp-sub" style="overflow-wrap:anywhere">{{ $responsibleEmail }}</span>@endif</td>
                        <td>@if(!empty($reviewV24['scope']))<span class="ecp-service">{{ $reviewV24['scope'] }}</span>@else @forelse($svc as $code)<span class="ecp-service">{{ $bundle && $code === 'ACOMPANHAMENTO' ? 'Acompanhamento retroativo' : ($serviceNames[$code] ?? $code) }}</span>@empty<span class="ecp-sub">Não identificado</span>@endforelse @endif</td>
                        <td>
                            @if($reviewV24)
                                @include('proposals.historical.review-list-v24')
                            @elseif($packageV12)
                                @include('proposals.historical.billing-list-v12')
                            @elseif($useV13Price && !$percentV10)
                                @include('proposals.historical.review-list-v13')
                            @elseif($percentV10)
                                <div class="ecp-money">+{{ str_replace('.', ',', (string)($termsV10['percent'] ?? '')) }}%</div><span class="ecp-sub">Sobre mensalidade vigente · condicional</span>
                            @elseif($svc === ['RETROATIVAS'] || $state === 'not_applicable')
                                @if($historical && $once !== null)<div class="ecp-money">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($once) }}</div><span class="ecp-sub">Pagamento único{{ $bundle && !empty($finance['bundle_estimated']) ? ' · estimado' : '' }}{{ !empty($termsV10['conditional_discount']) ? ' · com desconto condicional' : '' }}</span>
                                @if(!empty($termsV10['conditional_discount']))<span class="ecp-sub">Sem aceite: {{ \App\Services\Proposals\HistoricalProposalReadModel::money($finance['conditional_total'] ?? $termsV10['gross']) }}</span>@elseif(!empty($termsV10['discount_amount']))<span class="ecp-sub">Desconto: {{ \App\Services\Proposals\HistoricalProposalReadModel::money($termsV10['discount_amount']) }}</span>@endif
                                @if($bundle)@foreach(($finance['components'] ?? []) as $component)<span class="ecp-sub">{{ $component['label'] }}: {{ \App\Services\Proposals\HistoricalProposalReadModel::money($component['amount'] ?? null) }}</span>@endforeach @endif
                                @elseif($historical)<span class="ecp-chip ecp-chip-pending">{{ ($finance['one_time_state'] ?? '') === 'conflict' ? 'Valores divergentes' : 'Valor único pendente' }}</span>
                                @elseif(empty($nativeItems) && $once !== null)<div class="ecp-money">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($once) }}</div><span class="ecp-sub">Implantação e avulsos</span>
                                @else<span class="ecp-sub">Pagamento único</span>@endif
                            @elseif($quoteConflictV11 && count($declaredV11) === 1)
                                <div class="ecp-money">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($declaredV11[0]) }}<span class="ecp-sub" style="display:inline">/mês</span></div>
                                <span class="ecp-sub">Valor declarado no PDF</span>
                                @if(count($tableValuesV11) === 1)<span class="ecp-sub">Tabela-base: {{ \App\Services\Proposals\HistoricalProposalReadModel::money($tableValuesV11[0]) }}</span>@endif
                                <span class="ecp-chip ecp-chip-pending">Conferir condição / valor</span>
                            @elseif($quoteConflictV11)
                                <span class="ecp-chip ecp-chip-pending">Valores a conferir</span>
                                @foreach($declaredV11 as $candidateV11)<span class="ecp-sub">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($candidateV11) }}</span>@endforeach
                            @elseif($monthly !== null)<div class="ecp-money">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($monthly) }}<span class="ecp-sub" style="display:inline">/mês</span></div>@if($historical && ($finance['billing_mode'] ?? '') === 'pre_fixed_reference')<span class="ecp-sub">Estimativa pré-fixada</span><span class="ecp-sub">Pós-fixada: conforme uso</span>@endif @if($state === 'previous_review')<span class="ecp-sub">Valor a conferir</span>@endif
                            @else<span class="ecp-chip ecp-chip-pending">{{ $state === 'conflict' ? 'Valores divergentes' : 'Mensalidade pendente' }}</span>@endif
                            @if(!$reviewV24 && !$packageV12 && !$useV13Price)
                            @if(!$historical && count($nativeItems)>0)
                                @foreach($nativeItems as $nativeCode=>$nativeItem)
                                    @if(isset($nativeItem['implementation']) && (float)$nativeItem['implementation']>0)<span class="ecp-sub">Implantação ({{ $serviceNames[$nativeCode] ?? $nativeCode }}): {{ \App\Services\Proposals\HistoricalProposalReadModel::money($nativeItem['implementation']) }}</span>@endif
                                    @if($nativeCode === 'RETROATIVAS' && isset($nativeItem['one_time']))<span class="ecp-sub">Retroativas: {{ \App\Services\Proposals\HistoricalProposalReadModel::money($nativeItem['one_time']) }}</span>@endif
                                @endforeach
                            @elseif($once !== null && $svc !== ['RETROATIVAS'] && $state !== 'not_applicable')<div class="ecp-money" style="margin-top:3px">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($once) }}</div><span class="ecp-sub">{{ $percentV10 ? 'Desenvolvimento · pontual' : (!$historical ? 'Implantação e avulsos' : (($svc !== ['RETROATIVAS'] && $implantation !== null && $once == $implantation) ? ($conditionalImplantation ? 'Implantação condicional' : 'Implantação') : 'Valor do serviço / pontual')) }} @if(($finance['one_time_state'] ?? '') === 'previous_review')· a conferir @endif</span>@endif
                            @if($historical && $implantation !== null && ($bundle || $svc === ['RETROATIVAS'] || $once === null || $once != $implantation))<span class="ecp-sub">{{ $conditionalImplantation ? 'Implantação condicional: ' : 'Implantação: ' }}{{ \App\Services\Proposals\HistoricalProposalReadModel::money($implantation) }}</span>@endif
                            @endif
                        </td>
                        <td><span class="ecp-chip {{ $statusClass }}">{{ $statusText }}</span>@if($historical && $listCrmDeal)<span class="ecp-sub">{{ $listCrmDeal->name }}</span><span class="ecp-sub">{{ $listCrmDeal->stage_label ?: 'Etapa não informada' }}</span>@elseif($historical && $listCrmCompany)<span class="ecp-sub">HubSpot: {{ $listCrmCompany }}</span><span class="ecp-sub">Confirmar negócio</span>@elseif($historical)<span class="ecp-sub">Conciliar HubSpot</span>@endif</td>
                        <td class="ecp-nowrap">{{ \App\Services\Proposals\HistoricalProposalReadModel::date($reviewV24['issued_on'] ?? $histProposal->getAttribute('hist_issued_on') ?? $histProposal->issued_on ?? $dateV13) }}@if(empty($reviewV24['issued_on']) && !$histProposal->getAttribute('hist_issued_on') && !$histProposal->issued_on && $histProposal->getAttribute('hist_year'))<span class="ecp-sub">Ano: {{ $histProposal->getAttribute('hist_year') }}</span>@endif</td>
                        <td class="ecp-action ecp-nowrap">@include('proposals.historical.detail-link')</td>
                    </tr>
                @empty<tr><td colspan="8" class="ecp-empty">Nenhuma proposta encontrada.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="ecp-pagination">{{ $histPage->links() }}</div>
    </section>
</div>
