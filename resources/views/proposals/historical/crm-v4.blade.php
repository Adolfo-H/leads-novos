@php
    $crmV4 = is_array($histEvidence['crm_v4'] ?? null) ? $histEvidence['crm_v4'] : [];
    $crmCompany = $histInfo?->crm_company_id ? \Illuminate\Support\Facades\DB::table('hubspot_companies')->where('id',$histInfo->crm_company_id)->first(['id','name','hubspot_id']) : null;
    $crmDeal = $histInfo?->crm_deal_id ? \Illuminate\Support\Facades\DB::table('hubspot_deals')->where('id',$histInfo->crm_deal_id)->first(['id','name','hubspot_id','stage_label']) : null;
    $crmCompanyCandidates = \Illuminate\Support\Facades\DB::table('hubspot_companies')->whereIn('id',array_slice((array)($crmV4['company_candidates'] ?? []),0,20))->orderBy('name')->get(['id','name','hubspot_id']);
    $crmDealCandidates = \Illuminate\Support\Facades\DB::table('hubspot_deals')->whereIn('id',array_slice((array)($crmV4['deal_candidates'] ?? []),0,30))->get(['id','name','hubspot_id','stage_label'])->keyBy('id');
    $crmSuggested = $crmDealCandidates->get((int)($crmV4['generic_candidate'] ?? 0));
    $crmPortal = (string) config('services.hubspot.portal_id','');
    $crmHubUrl = static fn($type,$external) => ctype_digit($crmPortal) && ctype_digit((string)$external) ? 'https://app.hubspot.com/contacts/'.$crmPortal.'/record/'.$type.'/'.$external : null;
    $crmMatchLabels = ['exact_code'=>'Número exato da proposta','unique_company_service'=>'Empresa e serviço correspondentes','manual_confirmed'=>'Confirmado pelo gestor','existing_core'=>'Vínculo já registrado','generic_candidate'=>'Negócio da empresa — confirmar proposta','shared_deal_candidate'=>'Negócio compartilhado por propostas','ambiguous_company'=>'Mais de uma empresa possível','ambiguous_deal'=>'Mais de um negócio possível','existing_link_conflict'=>'Vínculo anterior divergente','company_only'=>'Empresa identificada; conferir negócio','no_deal'=>'Empresa sem negócio no espelho','unmatched_company'=>'Empresa não identificada automaticamente'];
    $crmManager = auth()->user() instanceof \App\Models\User && auth()->user()->isCommercialManager();
@endphp
<section class="ecp-panel" id="ec-crm-v4">
    <div class="ecp-head"><div><h2>Empresa e negócio · HubSpot</h2><p class="ecp-sub">Associações comerciais do espelho local; os documentos e o CRM remoto não são alterados.</p></div><span class="ecp-chip {{ $crmDeal ? 'ecp-chip-accepted' : 'ecp-chip-pending' }}">{{ $crmDeal ? 'Vinculada' : 'Conciliação pendente' }}</span></div>
    @if(session('history_crm_success'))<p class="ecp-note" role="status">{{ session('history_crm_success') }}</p>@endif
    <div class="ecp-two">
        <div class="ecp-value"><span class="ecp-label">Empresa cadastrada no HubSpot</span><strong style="font-size:16px;overflow-wrap:anywhere">{{ $crmCompany?->name ?: 'Ainda não confirmada' }}</strong>@if($crmCompany)<span class="ecp-sub">ID HubSpot: {{ $crmCompany->hubspot_id }}</span>@if($crmHubUrl('0-2',$crmCompany->hubspot_id))<a class="ecp-link" href="{{ $crmHubUrl('0-2',$crmCompany->hubspot_id) }}" target="_blank" rel="noopener noreferrer">Abrir empresa no HubSpot ↗</a>@endif @endif</div>
        <div class="ecp-value"><span class="ecp-label">{{ $crmDeal ? 'Negócio associado à proposta' : ($crmSuggested ? 'Negócio principal sugerido' : 'Negócio associado à proposta') }}</span><strong style="font-size:16px;overflow-wrap:anywhere">{{ $crmDeal?->name ?? $crmSuggested?->name ?? 'Ainda não confirmado' }}</strong>@if($crmDeal || $crmSuggested)@php $crmShown = $crmDeal ?? $crmSuggested; @endphp<span class="ecp-sub">ID HubSpot: {{ $crmShown->hubspot_id }}</span>@if($crmHubUrl('0-3',$crmShown->hubspot_id))<a class="ecp-link" href="{{ $crmHubUrl('0-3',$crmShown->hubspot_id) }}" target="_blank" rel="noopener noreferrer">Abrir negócio no HubSpot ↗</a>@endif @endif</div>
    </div>
    <dl class="ecp-facts ecp-spaced">
        <div><dt>Etapa atual no espelho</dt><dd>{{ $crmDeal?->stage_label ?? $crmSuggested?->stage_label ?? 'Não identificada' }}@if(!$crmDeal && $crmSuggested)<span class="ecp-sub">Etapa do candidato, não da proposta confirmada</span>@endif</dd></div>
        <div><dt>Etapa na última conciliação</dt><dd>{{ $histInfo?->crm_stage ?: '—' }}</dd></div>
        <div><dt>Critério</dt><dd>{{ $crmMatchLabels[$histInfo?->crm_match ?? ''] ?? 'A conferir' }}</dd></div>
    </dl>
    @if(!$crmDeal && $crmDealCandidates->isNotEmpty())
        <div class="ecp-spaced"><h3 style="margin-bottom:10px">Negócios disponíveis para esta empresa</h3><div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Negócio</th><th>Etapa no HubSpot</th><th>ID</th><th>Conferir</th></tr></thead><tbody>
            @foreach((array)($crmV4['deal_candidates'] ?? []) as $candidateId)
                @php $candidate = $crmDealCandidates->get((int)$candidateId); @endphp
                @if($candidate)<tr><td>{{ $candidate->name }}</td><td>{{ $candidate->stage_label ?: '—' }}</td><td>{{ $candidate->hubspot_id }}</td><td>@if($crmHubUrl('0-3',$candidate->hubspot_id))<a class="ecp-link" href="{{ $crmHubUrl('0-3',$candidate->hubspot_id) }}" target="_blank" rel="noopener noreferrer">Abrir ↗</a>@endif</td></tr>@endif
            @endforeach
        </tbody></table></div></div>
    @endif
    @if(!$crmCompany && $crmCompanyCandidates->isNotEmpty())<div class="ecp-spaced"><h3>Empresas candidatas</h3>@foreach($crmCompanyCandidates as $candidate)<p class="ecp-sub">{{ $candidate->name }} · ID {{ $candidate->hubspot_id }} @if($crmHubUrl('0-2',$candidate->hubspot_id))<a class="ecp-link" href="{{ $crmHubUrl('0-2',$candidate->hubspot_id) }}" target="_blank" rel="noopener noreferrer">Abrir ↗</a>@endif</p>@endforeach</div>@endif
    <p class="ecp-note ecp-spaced">A etapa do negócio não comprova assinatura ou aceite formal. Negócios candidatos não são contabilizados como propostas aceitas. @if(!$crmDeal)Confira qual negócio corresponde ao documento antes de confirmar.@endif</p>
    @if($crmManager && $histInfo)
        <details class="ecp-disclosure ecp-spaced" @if(!$crmDeal || $errors->has('deal_external_id') || $errors->has('company_external_id')) open @endif>
            <summary>{{ $crmDeal ? 'Corrigir vínculo comercial' : 'Confirmar empresa e negócio' }}</summary>
            <form method="POST" action="{{ route('proposals.history-crm.confirm',$histProposal) }}" style="padding:0 16px 16px;display:grid;gap:12px">
                @csrf
                @if($errors->any())<div class="ecp-note ecp-warning" role="alert">{{ $errors->first() }}</div>@endif
                <div class="ecp-two">
                    <label>Empresa · ID externo HubSpot<input style="display:block;width:100%;padding:10px;border:1px solid #d5e3e8;border-radius:7px;background:transparent" name="company_external_id" value="{{ old('company_external_id',$crmCompany?->hubspot_id ?? '') }}" required pattern="[0-9]{1,20}" maxlength="20" inputmode="numeric"></label>
                    <label>Negócio · ID externo HubSpot<input style="display:block;width:100%;padding:10px;border:1px solid #d5e3e8;border-radius:7px;background:transparent" name="deal_external_id" value="{{ old('deal_external_id',$crmDeal?->hubspot_id ?? $crmSuggested?->hubspot_id ?? '') }}" required pattern="[0-9]{1,20}" maxlength="20" inputmode="numeric"></label>
                </div>
                <label style="display:flex;align-items:flex-start;gap:8px"><input type="checkbox" name="confirm_crm" value="1" required> Conferi que este negócio corresponde à proposta e à empresa indicada.</label>
                <div><button class="ecp-btn ecp-btn-primary" type="submit">Salvar vínculo no Prospector</button></div>
                <p class="ecp-sub">Use o ID numérico do registro HubSpot (não o ID local). A associação será validada pelo espelho; nenhuma informação será enviada ao HubSpot.</p>
            </form>
        </details>
    @endif
</section>
