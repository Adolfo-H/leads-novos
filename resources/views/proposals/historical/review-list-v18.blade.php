@php $moneyV18 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV18['monthly_summary']))
    <div class="ecp-money">{{ $moneyV18($reviewV18['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV18['principal_label'] }}</span>
    @if(isset($reviewV18['scenarios'][0]['monthly']) && $reviewV18['scenarios'][0]['monthly'] !== $reviewV18['monthly_summary'])
        <span class="ecp-sub">Tabela-base: {{ $moneyV18($reviewV18['scenarios'][0]['monthly']) }}/mês</span>
    @endif
@elseif(isset($reviewV18['summary']))
    <div class="ecp-money">{{ $moneyV18($reviewV18['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV18['principal_label'] }}</span>
    @if($reviewV18['kind'] === 'componentes')
        @foreach($reviewV18['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV18($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV18['principal'] !== null)
    <div class="ecp-money">{{ $moneyV18($reviewV18['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV18['principal_label'] }} · pontual</span>
    @if($reviewV18['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV18['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV18['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV18['principal_label'] }}</span>
    @foreach($reviewV18['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV18($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV18($scenario['setup']) }} implantação @endif @else{{ $moneyV18($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV18['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV18['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV18($reviewV18['setup_amount']) }}</span>
@endif
@if(!empty($reviewV18['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV18['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV18['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV18['list_note']))<span class="ecp-sub">{{ $reviewV18['list_note'] }}</span>@endif
