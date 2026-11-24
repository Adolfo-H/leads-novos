@php $brV13 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
@if(isset($priceV13['monthly']))<div class="ecp-money">{{ $brV13($priceV13['monthly']) }}/mês</div>@endif
@if(isset($priceV13['one_time']))<div class="ecp-money">{{ $brV13($priceV13['one_time']) }}</div><span class="ecp-sub">Pagamento único</span>@endif
@foreach($priceV13['components'] ?? [] as $component)<span class="ecp-sub">{{ $component['label'] }}: {{ $brV13($component['amount']) }}{{ $component['billing'] === 'monthly' ? '/mês' : ' · pontual' }}</span>@endforeach
@if(!isset($priceV13['monthly']) && !isset($priceV13['one_time']) && empty($priceV13['components']))<span class="ecp-chip ecp-chip-pending">Valores por conferir</span>@foreach(array_unique(array_column($priceV13['evidence'] ?? [],'value')) as $candidate)<span class="ecp-sub">{{ $brV13($candidate) }}</span>@endforeach @endif
@if(in_array($priceV13['state'] ?? '', ['review','declared_conflict','previous_review','unresolved'],true))<span class="ecp-sub">Conferir condição / valor</span>@elseif(($priceV13['state'] ?? '') === 'rounding')<span class="ecp-sub">Diferença de centavo no original</span>@endif
@if($implantation !== null)<span class="ecp-sub">{{ $conditionalImplantation ? 'Implantação condicional' : 'Implantação' }}: {{ $brV13($implantation) }}</span>@endif
@if(isset($priceV13['monthly']) && $once !== null && ($implantation === null || $once != $implantation))<span class="ecp-sub">Valor pontual cadastrado: {{ $brV13($once) }}</span>@endif
