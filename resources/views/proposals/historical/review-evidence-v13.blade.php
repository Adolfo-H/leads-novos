@if(($priceV13['state'] ?? '') === 'rounding')
    <div class="ecp-note">Possível arredondamento de R$ 0,01: valor declarado {{ \App\Services\Proposals\HistoricalProposalReadModel::money($priceV13['monthly']) }}, tabela {{ \App\Services\Proposals\HistoricalProposalReadModel::money($priceV13['table_amount']) }}. Valor previamente cadastrado preservado.</div>
@elseif(in_array($priceV13['state'] ?? '', ['review','declared_conflict','previous_review','unresolved'],true))
    <section class="ecp-panel ecp-spaced"><div class="ecp-head"><div><h2>Conferência de valores</h2><p class="ecp-sub">Valores por condição, serviço e evidência. Nenhuma soma ou contratação presumida.</p></div></div>
        @foreach($priceV13['warnings'] ?? [] as $warning)<p class="ecp-note ecp-warning">{{ $warning }}</p>@endforeach
        <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Evidência original</th><th class="ecp-number">Valor</th></tr></thead><tbody>
        @foreach($priceV13['evidence'] ?? [] as $candidate)<tr><td>{{ $candidate['text'] }}</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($candidate['value']) }}</td></tr>@endforeach
        @if($monthly !== null)<tr><td>Mensalidade anteriormente cadastrada</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($monthly) }}</td></tr>@endif
        @if($once !== null)<tr><td>Valor pontual anteriormente cadastrado</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($once) }}</td></tr>@endif
        </tbody></table></div>
    </section>
@endif
