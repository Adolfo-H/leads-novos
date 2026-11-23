@php
    $histData = app(\App\Services\Proposals\HistoricalProposalReadModel::class)->listing(request());
    $histPage = $histData['page'];
    $histFinanceRows = \Illuminate\Support\Facades\DB::table('ec_proposal_history_metadata')
        ->whereIn('proposal_id', $histPage->getCollection()->pluck('id')->all())
        ->get(['proposal_id', 'evidence'])->keyBy('proposal_id');
    $serviceNames = ['LICENCIAMENTO'=>'Licenciamento','RETROATIVAS'=>'Retroativas','ACOMPANHAMENTO'=>'Acompanhamento','DFE'=>'DF-e','NFSE'=>'NFS-e'];
@endphp
@include('proposals.historical.styles-v2')
<div class="ecp-history">
    <div class="ecp-kpis" aria-label="Indicadores de propostas">
        @foreach (['total'=>['Propostas cadastradas','Inclui o histórico importado'],'draft'=>['Rascunhos','Em preparação'],'sent'=>['Emitidas / enviadas','Sem aceite identificado'],'accepted'=>['Aceitas','Aceite formal ou situação no CRM']] as $histKey=>$histCard)
            <a class="ecp-kpi {{ $histKey === 'accepted' ? 'ecp-kpi-accepted' : '' }} {{ ($histData['status'] === $histKey || ($histKey === 'total' && $histData['status'] === '')) ? 'ecp-kpi-active' : '' }}" href="{{ url('/propostas').($histKey === 'total' ? '' : '?hist_status='.$histKey) }}">
                <span class="ecp-label">{{ $histCard[0] }}</span>
                <strong>{{ number_format($histData['counts'][$histKey],0,',','.') }}</strong>
                <span class="ecp-sub">{{ $histCard[1] }}</span>
            </a>
        @endforeach
    </div>
    <section class="ecp-panel">
        <div class="ecp-head">
            <div><h2>Todas as propostas</h2><p class="ecp-sub">Valores e documentos originais, sem recalcular o histórico.</p></div>
            <a class="ecp-btn" href="{{ url('/propostas') }}?visao_original=1">Gestão original</a>
        </div>
        <form action="{{ url('/propostas') }}" method="GET" class="ecp-filter">
            <label class="ecp-search">Cliente, nome ou número<input name="hist_q" value="{{ $histData['q'] }}" placeholder="Pesquisar proposta..." maxlength="200"></label>
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
                        $monthly = $histProposal->getAttribute('hist_monthly_amount');
                        $once = $histProposal->getAttribute('hist_one_time_amount');
                        $formal = $histProposal->status === 'accepted';
                        $crmAccepted = $historical && $histProposal->getAttribute('hist_commercial_status') === 'accepted_crm';
                        $rawEvidence = $histFinanceRows->get($histProposal->id)?->evidence;
                        $finance = (json_decode(is_string($rawEvidence) ? $rawEvidence : '{}', true)['finance_v2'] ?? []);
                        $state = $finance['monthly_state'] ?? '';
                        $statusText = $formal ? 'Aceita formalmente' : ($crmAccepted ? 'Aceita no CRM' : ($historical ? 'Emitida / enviada' : match($histProposal->status){'draft'=>'Rascunho','issued','sent'=>'Emitida / enviada',default=>$histProposal->status}));
                        $statusClass = $formal || $crmAccepted ? 'ecp-chip-accepted' : ($historical || in_array($histProposal->status,['issued','sent'],true) ? 'ecp-chip-sent' : '');
                    @endphp
                    <tr>
                        <td><div class="ecp-name" title="{{ $title }}">{{ str_replace('_',' ',(string)$title) }}</div>@if($histProposal->getAttribute('hist_number'))<span class="ecp-sub">EC {{ $histProposal->getAttribute('hist_year') }} / {{ $histProposal->getAttribute('hist_number') }}</span>@endif</td>
                        <td><div class="ecp-client">{{ $histProposal->client_name }}</div></td>
                        <td>{{ $histProposal->getAttribute('hist_responsible_name') ?: '—' }}@if($histProposal->getAttribute('hist_responsible_email'))<span class="ecp-sub">{{ $histProposal->getAttribute('hist_responsible_email') }}</span>@endif</td>
                        <td>@forelse($svc as $code)<span class="ecp-service">{{ $serviceNames[$code] ?? $code }}</span>@empty<span class="ecp-sub">Não identificado</span>@endforelse</td>
                        <td>
                            @if(!$historical)<span class="ecp-sub">Ver cálculo original</span>
                            @elseif($monthly !== null)<div class="ecp-money">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($monthly) }}<span class="ecp-sub" style="display:inline">/mês</span></div>@if($state === 'previous_review')<span class="ecp-sub">Valor a conferir</span>@endif
                            @elseif($svc === ['RETROATIVAS'] || $state === 'not_applicable')<span class="ecp-sub">Serviço pontual</span>
                            @else<span class="ecp-chip ecp-chip-pending">{{ $state === 'conflict' ? 'Valores divergentes' : 'Mensalidade pendente' }}</span>@endif
                            @if($once !== null)<div class="ecp-money" style="margin-top:3px">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($once) }}</div><span class="ecp-sub">Valor pontual @if(($finance['one_time_state'] ?? '') === 'previous_review')· a conferir @endif</span>@endif
                        </td>
                        <td><span class="ecp-chip {{ $statusClass }}">{{ $statusText }}</span>@if($historical && $histProposal->getAttribute('hist_crm_stage'))<span class="ecp-sub">{{ $histProposal->getAttribute('hist_crm_stage') }}</span>@elseif($historical)<span class="ecp-sub">Vínculo CRM pendente</span>@endif</td>
                        <td class="ecp-nowrap">{{ \App\Services\Proposals\HistoricalProposalReadModel::date($histProposal->getAttribute('hist_issued_on') ?? $histProposal->issued_on) }}@if(!$histProposal->getAttribute('hist_issued_on') && !$histProposal->issued_on && $histProposal->getAttribute('hist_year'))<span class="ecp-sub">Ano: {{ $histProposal->getAttribute('hist_year') }}</span>@endif</td>
                        <td class="ecp-action ecp-nowrap">@include('proposals.historical.detail-link')</td>
                    </tr>
                @empty<tr><td colspan="8" class="ecp-empty">Nenhuma proposta encontrada.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="ecp-pagination">{{ $histPage->links() }}</div>
    </section>
</div>
