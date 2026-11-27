@php $moneyV15 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if($reviewV15['principal'] !== null)
    <div class="ecp-money">{{ $moneyV15($reviewV15['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV15['principal_label'] }} · pontual</span>
    @if($reviewV15['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV15['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV15['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV15['principal_label'] }}</span>
    @foreach($reviewV15['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>{{ $moneyV15($scenario['total'] ?? $scenario['service']) }}</strong></span>
    @endforeach
@endif
@if($reviewV15['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV15['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV15($reviewV15['setup_amount']) }}</span>
@endif
@if(($reviewV15['setup_mode'] ?? '') === 'not_informed')<span class="ecp-sub">Implantação não informada nesta versão</span>@endif
@if(!empty($reviewV15['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif
