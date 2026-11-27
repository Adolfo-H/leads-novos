@php $moneyV16 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV16['summary']))
    <div class="ecp-money">{{ $moneyV16($reviewV16['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV16['principal_label'] }}</span>
    @if($reviewV16['kind'] === 'componentes')
        @foreach($reviewV16['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV16($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV16['principal'] !== null)
    <div class="ecp-money">{{ $moneyV16($reviewV16['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV16['principal_label'] }} · pontual</span>
    @if($reviewV16['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV16['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV16['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV16['principal_label'] }}</span>
    @foreach($reviewV16['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV16($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV16($scenario['setup']) }} implantação @endif @else{{ $moneyV16($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV16['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV16['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV16($reviewV16['setup_amount']) }}</span>
@endif
@if(!empty($reviewV16['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV16['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV16['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif
