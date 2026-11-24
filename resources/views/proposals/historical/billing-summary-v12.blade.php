@php
    $brV12 = static fn($value) => \App\Services\Proposals\HistoricalProposalReadModel::money($value);
    $annualV12 = $billingV12['kind'] === 'annual_package';
@endphp
<div class="ecp-summary">
    <div class="ecp-value ecp-value-main"><span class="ecp-label">{{ $annualV12 ? 'Licenciamento · pacote anual' : 'Mensalidade de referência' }}</span><strong>{{ $brV12($annualV12 ? $billingV12['annual_amount'] : $billingV12['monthly_reference']) }}</strong><span class="ecp-sub">{{ $annualV12 ? 'Equivalente mensal: '.$brV12($billingV12['monthly_reference']).' (não é cobrança mensal adicional)' : 'Valor original · modalidade pré-fixada quando oferecida' }}</span></div>
    <div class="ecp-value"><span class="ecp-label">Investimento inicial total</span><strong>{{ $brV12($billingV12['initial_total']) }}</strong><span class="ecp-sub">Composição detalhada abaixo · não somar novamente</span></div>
    <div class="ecp-value"><span class="ecp-label">Base mensal de documentos</span><strong>{{ isset($billingV12['monthly_quantity']) ? number_format((float)$billingV12['monthly_quantity'],0,',','.') : 'Não informada' }}</strong><span class="ecp-sub">{{ isset($billingV12['annual_quantity']) ? number_format((float)$billingV12['annual_quantity'],0,',',' ').' NFs na base anual' : 'Conforme documento' }}</span></div>
</div>
@foreach(($billingV12['warnings'] ?? []) as $warning)<div class="ecp-note ecp-warning">{{ $warning }}</div>@endforeach
