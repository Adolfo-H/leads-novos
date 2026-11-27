@php $moneyV17 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV17['monthly_summary']))
    <div class="ecp-money">{{ $moneyV17($reviewV17['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV17['principal_label'] }}</span>
    <span class="ecp-sub">Tabela-base: {{ $moneyV17($reviewV17['scenarios'][0]['monthly']) }}/mês</span>
@elseif(isset($reviewV17['summary']))
    <div class="ecp-money">{{ $moneyV17($reviewV17['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV17['principal_label'] }}</span>
    @if($reviewV17['kind'] === 'componentes')
        @foreach($reviewV17['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV17($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV17['principal'] !== null)
    <div class="ecp-money">{{ $moneyV17($reviewV17['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV17['principal_label'] }} · pontual</span>
    @if($reviewV17['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV17['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV17['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV17['principal_label'] }}</span>
    @foreach($reviewV17['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV17($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV17($scenario['setup']) }} implantação @endif @else{{ $moneyV17($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV17['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV17['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV17($reviewV17['setup_amount']) }}</span>
@endif
@if(!empty($reviewV17['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV17['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV17['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV17['list_note']))<span class="ecp-sub">{{ $reviewV17['list_note'] }}</span>@endif
