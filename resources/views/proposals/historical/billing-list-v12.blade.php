@php $brV12 = static fn($value) => \App\Services\Proposals\HistoricalProposalReadModel::money($value); @endphp
@if($billingV12['kind'] === 'annual_package')
    <div class="ecp-money">{{ $brV12($billingV12['annual_amount']) }}</div><span class="ecp-sub">Pacote anual</span>
    <span class="ecp-sub">Referência mensal: {{ $brV12($billingV12['monthly_reference']) }}</span>
@else
    <div class="ecp-money">{{ $brV12($billingV12['monthly_reference']) }}/mês</div>
    @if(!empty($billingV12['pre_fixed']))<span class="ecp-sub">Estimativa pré-fixada</span>@endif
    @if(!empty($billingV12['post_fixed']))<span class="ecp-sub">Pós-fixada: conforme uso</span>@endif
@endif
<span class="ecp-sub">Implantação: {{ $brV12($billingV12['implantation']) }}</span>
@if($billingV12['retroactive'] !== null)<span class="ecp-sub">Consultas retroativas: {{ $brV12($billingV12['retroactive']) }}</span>@endif
@if($billingV12['historical_import'] !== null)<span class="ecp-sub">Importação histórica: {{ $brV12($billingV12['historical_import']) }}</span>@endif
<div class="ecp-money" style="margin-top:4px">{{ $brV12($billingV12['initial_total']) }}</div><span class="ecp-sub">Total inicial · inclui os componentes acima</span>
