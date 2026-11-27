@php $moneyV24 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV24['monthly_summary']))
    <div class="ecp-money">{{ $moneyV24($reviewV24['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV24['principal_label'] }}</span>
    @if(isset($reviewV24['scenarios'][0]['monthly']) && $reviewV24['scenarios'][0]['monthly'] !== $reviewV24['monthly_summary'])
        <span class="ecp-sub">Tabela-base: {{ $moneyV24($reviewV24['scenarios'][0]['monthly']) }}/mês</span>
    @endif
@elseif(isset($reviewV24['summary']))
    <div class="ecp-money">{{ $moneyV24($reviewV24['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV24['principal_label'] }}</span>
    @if($reviewV24['kind'] === 'componentes')
        @foreach($reviewV24['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV24($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV24['principal'] !== null)
    <div class="ecp-money">{{ $moneyV24($reviewV24['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV24['principal_label'] }} · pontual</span>
    @if($reviewV24['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV24['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV24['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV24['principal_label'] }}</span>
    @foreach($reviewV24['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV24($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV24($scenario['setup']) }} implantação @endif @else{{ $moneyV24($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV24['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV24['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV24($reviewV24['setup_amount']) }}</span>
@endif
@if(!empty($reviewV24['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV24['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">{{ str_starts_with($reviewV24['setup_note'], 'Sem implantação adicional') ? $reviewV24['setup_note'] : 'Implantação não informada nesta versão' }}</span>@endif
@if(!empty($reviewV24['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV24['list_note']))<span class="ecp-sub">{{ $reviewV24['list_note'] }}</span>@endif
