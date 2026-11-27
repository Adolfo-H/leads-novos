@php $moneyV21 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV21['monthly_summary']))
    <div class="ecp-money">{{ $moneyV21($reviewV21['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV21['principal_label'] }}</span>
    @if(isset($reviewV21['scenarios'][0]['monthly']) && $reviewV21['scenarios'][0]['monthly'] !== $reviewV21['monthly_summary'])
        <span class="ecp-sub">Tabela-base: {{ $moneyV21($reviewV21['scenarios'][0]['monthly']) }}/mês</span>
    @endif
@elseif(isset($reviewV21['summary']))
    <div class="ecp-money">{{ $moneyV21($reviewV21['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV21['principal_label'] }}</span>
    @if($reviewV21['kind'] === 'componentes')
        @foreach($reviewV21['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV21($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV21['principal'] !== null)
    <div class="ecp-money">{{ $moneyV21($reviewV21['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV21['principal_label'] }} · pontual</span>
    @if($reviewV21['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV21['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV21['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV21['principal_label'] }}</span>
    @foreach($reviewV21['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV21($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV21($scenario['setup']) }} implantação @endif @else{{ $moneyV21($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV21['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV21['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV21($reviewV21['setup_amount']) }}</span>
@endif
@if(!empty($reviewV21['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV21['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV21['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV21['list_note']))<span class="ecp-sub">{{ $reviewV21['list_note'] }}</span>@endif
