{{-- EC commercial terms V10: values are proposal conditions, never implied acceptance. --}}
@if(($termsV10['kind'] ?? '') === 'percentage_addon')
    <div class="ecp-note">Esta proposta possui uma cobrança pontual de desenvolvimento e um adicional percentual condicionado. O documento não informa o valor em reais da mensalidade vigente.</div>
    <div class="ecp-scroll ecp-spaced"><table class="ecp-table"><thead><tr><th>Componente</th><th>Condição</th><th class="ecp-number">Valor / regra</th></tr></thead><tbody>
        <tr><td>Desenvolvimento</td><td>{{ $conditions['pagamento'] ?? 'Conforme documento' }}</td><td class="ecp-number"><strong>{{ $onceText }}</strong></td></tr>
        <tr><td>Adicional mensal</td><td>Condicional · sobre a mensalidade vigente</td><td class="ecp-number"><strong>+{{ str_replace('.', ',', (string)$termsV10['percent']) }}%</strong></td></tr>
    </tbody></table></div>
    <p class="ecp-note ecp-spaced">{{ $termsV10['condition'] }}</p>
@elseif(($termsV10['kind'] ?? '') === 'one_time')
    <div class="ecp-spaced"><h3 style="margin-bottom:10px">Composição do investimento</h3>
        <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Componente</th><th class="ecp-number">Valor</th></tr></thead><tbody>
            <tr><td>Valor original do serviço</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($termsV10['gross']) }}</td></tr>
            @if(($termsV10['discount_amount'] ?? null) !== null)<tr><td>Desconto{{ !empty($termsV10['conditional_discount']) ? ' condicionado ao aceite indicado' : '' }} @if(isset($termsV10['discount_percent'])) ({{ str_replace('.', ',', (string)$termsV10['discount_percent']) }}%) @endif</td><td class="ecp-number">− {{ \App\Services\Proposals\HistoricalProposalReadModel::money($termsV10['discount_amount']) }}</td></tr>@endif
            <tr><td><strong>Valor do serviço{{ !empty($termsV10['conditional_discount']) ? ' com desconto condicionado' : '' }}</strong></td><td class="ecp-number"><strong>{{ $onceText }}</strong></td></tr>
            @if($implantation !== null)<tr><td>Implantação{{ $conditionalImplantation ? ' condicional' : '' }}</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($implantation) }}</td></tr>@endif
        </tbody></table></div>
    </div>
    @if(!empty($termsV10['scenarios']))<div class="ecp-spaced"><h3 style="margin-bottom:10px">Cenários comerciais previstos</h3><div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Condição</th><th class="ecp-number">Serviço</th><th class="ecp-number">Implantação</th><th class="ecp-number">Total</th></tr></thead><tbody>
        @foreach($termsV10['scenarios'] as $scenario)<tr><td>{{ $scenario['label'] }}</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($scenario['service']) }}</td><td class="ecp-number">{{ $scenario['implantation'] === null ? 'Não cobrada neste cenário' : \App\Services\Proposals\HistoricalProposalReadModel::money($scenario['implantation']) }}</td><td class="ecp-number"><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($scenario['total']) }}</strong></td></tr>@endforeach
    </tbody></table></div><p class="ecp-sub ecp-spaced">As alternativas não são cumulativas. Nenhuma condição comercial foi presumida como aceita.</p></div>@endif
    @foreach(($termsV10['notes'] ?? []) as $termNote)<p class="ecp-note ecp-spaced">{{ $termNote }}</p>@endforeach
@endif
