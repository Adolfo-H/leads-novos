@php $moneyV19 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV19['monthly_summary']))
    <div class="ecp-money">{{ $moneyV19($reviewV19['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV19['principal_label'] }}</span>
    @if(isset($reviewV19['scenarios'][0]['monthly']) && $reviewV19['scenarios'][0]['monthly'] !== $reviewV19['monthly_summary'])
        <span class="ecp-sub">Tabela-base: {{ $moneyV19($reviewV19['scenarios'][0]['monthly']) }}/mês</span>
    @endif
@elseif(isset($reviewV19['summary']))
    <div class="ecp-money">{{ $moneyV19($reviewV19['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV19['principal_label'] }}</span>
    @if($reviewV19['kind'] === 'componentes')
        @foreach($reviewV19['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV19($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV19['principal'] !== null)
    <div class="ecp-money">{{ $moneyV19($reviewV19['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV19['principal_label'] }} · pontual</span>
    @if($reviewV19['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV19['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV19['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV19['principal_label'] }}</span>
    @foreach($reviewV19['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV19($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV19($scenario['setup']) }} implantação @endif @else{{ $moneyV19($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV19['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV19['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV19($reviewV19['setup_amount']) }}</span>
@endif
@if(!empty($reviewV19['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV19['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV19['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV19['list_note']))<span class="ecp-sub">{{ $reviewV19['list_note'] }}</span>@endif
