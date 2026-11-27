@php $moneyV20 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV20['monthly_summary']))
    <div class="ecp-money">{{ $moneyV20($reviewV20['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV20['principal_label'] }}</span>
    @if(isset($reviewV20['scenarios'][0]['monthly']) && $reviewV20['scenarios'][0]['monthly'] !== $reviewV20['monthly_summary'])
        <span class="ecp-sub">Tabela-base: {{ $moneyV20($reviewV20['scenarios'][0]['monthly']) }}/mês</span>
    @endif
@elseif(isset($reviewV20['summary']))
    <div class="ecp-money">{{ $moneyV20($reviewV20['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV20['principal_label'] }}</span>
    @if($reviewV20['kind'] === 'componentes')
        @foreach($reviewV20['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV20($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV20['principal'] !== null)
    <div class="ecp-money">{{ $moneyV20($reviewV20['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV20['principal_label'] }} · pontual</span>
    @if($reviewV20['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV20['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV20['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV20['principal_label'] }}</span>
    @foreach($reviewV20['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV20($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV20($scenario['setup']) }} implantação @endif @else{{ $moneyV20($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV20['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV20['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV20($reviewV20['setup_amount']) }}</span>
@endif
@if(!empty($reviewV20['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV20['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV20['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV20['list_note']))<span class="ecp-sub">{{ $reviewV20['list_note'] }}</span>@endif
