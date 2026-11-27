@php $moneyV22 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($reviewV22['monthly_summary']))
    <div class="ecp-money">{{ $moneyV22($reviewV22['monthly_summary']) }}/mês</div>
    <span class="ecp-sub">{{ $reviewV22['principal_label'] }}</span>
    @if(isset($reviewV22['scenarios'][0]['monthly']) && $reviewV22['scenarios'][0]['monthly'] !== $reviewV22['monthly_summary'])
        <span class="ecp-sub">Tabela-base: {{ $moneyV22($reviewV22['scenarios'][0]['monthly']) }}/mês</span>
    @endif
@elseif(isset($reviewV22['summary']))
    <div class="ecp-money">{{ $moneyV22($reviewV22['summary']) }}</div>
    <span class="ecp-sub">{{ $reviewV22['principal_label'] }}</span>
    @if($reviewV22['kind'] === 'componentes')
        @foreach($reviewV22['scenarios'] as $scenario)
            @if(in_array($scenario['label'], ['Consultas retroativas','Acompanhamento retroativo opcional','Acompanhamento retroativo estimado'],true))<span class="ecp-sub">{{ $scenario['label'] }}: {{ $moneyV22($scenario['service']) }}</span>@endif
        @endforeach
    @endif
@elseif($reviewV22['principal'] !== null)
    <div class="ecp-money">{{ $moneyV22($reviewV22['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV22['principal_label'] }} · pontual</span>
    @if($reviewV22['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV22['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV22['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV22['principal_label'] }}</span>
    @foreach($reviewV22['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>@if(isset($scenario['monthly'])){{ $moneyV22($scenario['monthly']) }}/mês @if($scenario['setup'] !== null) · {{ $moneyV22($scenario['setup']) }} implantação @endif @else{{ $moneyV22($scenario['total'] ?? $scenario['service'] ?? $scenario['setup']) }}@endif</strong></span>
    @endforeach
@endif
@if($reviewV22['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV22['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV22($reviewV22['setup_amount']) }}</span>
@endif
@if(!empty($reviewV22['preserved_monthly_amounts']))<span class="ecp-sub">Há mensalidade anterior preservada; ver detalhes.</span>@endif
@if(($reviewV22['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV22['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif

@if(!empty($reviewV22['list_note']))<span class="ecp-sub">{{ $reviewV22['list_note'] }}</span>@endif
