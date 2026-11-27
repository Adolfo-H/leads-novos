@php $moneyV14 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if($reviewV14['principal'] !== null)
    <div class="ecp-money">{{ $moneyV14($reviewV14['principal']) }}</div>
    <span class="ecp-sub">{{ $reviewV14['principal_label'] }} · pontual</span>
    @if($reviewV14['kind'] === 'periodos')<span class="ecp-sub">{{ count($reviewV14['scenarios']) - 1 }} alternativas anuais</span>@endif
@else
    <span class="ecp-chip {{ $reviewV14['kind'] === 'divergencia' ? 'ecp-chip-pending' : 'ecp-chip-sent' }}">{{ $reviewV14['principal_label'] }}</span>
    @foreach($reviewV14['scenarios'] as $scenario)
        <span class="ecp-sub">{{ $scenario['label'] }}: <strong>{{ $moneyV14($scenario['total'] ?? $scenario['service']) }}</strong></span>
    @endforeach
@endif
@if($reviewV14['setup_amount'] !== null)
    <span class="ecp-sub">{{ $reviewV14['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}: {{ $moneyV14($reviewV14['setup_amount']) }}</span>
@endif
@if(!empty($reviewV14['preserved_amounts']))<span class="ecp-sub">Há valor anterior preservado; ver detalhes.</span>@endif
