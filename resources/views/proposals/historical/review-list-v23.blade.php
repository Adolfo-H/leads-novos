@php $moneyV23 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV23['monthly_summary']))
    <div class="ecp-money">{{ $moneyV23($reviewV23['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV23['principal_label'] }}</span>
    @if(isset($reviewV23['scenarios'][0]['monthly']) && $reviewV23['scenarios'][0]['monthly'] !== $reviewV23['monthly_summary'])
        <span class="ecp-sub">Tabela-base: {{ $moneyV23($reviewV23['scenarios'][0]['monthly']) }}/mês</span>
    @endif
@elseif(isset($reviewV23['summary']))
    <div class="ecp-money">{{ $moneyV23($reviewV23['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV23['principal_label'] }}</span>
    @if($reviewV23['kind'] === 'componentes')
        @foreach($reviewV23['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV23($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV23['principal'] !== null)
    <div class="ecp-money">{{ $moneyV23($reviewV23['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV23['principal_label'] }} · pontual</span>
    @if($reviewV23['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV23['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV23['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV23['principal_label'] }}</span>
    @foreach($reviewV23['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV23($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV23($scenario['setup']) }} implantação @endif @else{{ $moneyV23($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV23['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV23['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV23($reviewV23['setup_amount']) }}</span>
@endif
@if(!empty($reviewV23['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV23['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV23['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV23['list_note']))<span class="ecp-sub">{{ $reviewV23['list_note'] }}</span>@endif
