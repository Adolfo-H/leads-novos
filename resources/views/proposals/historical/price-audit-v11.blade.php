<section class="ecp-panel" aria-label="Conferência de valores do documento">
    <div class="ecp-head"><div><h2>Conferência de valores do documento</h2><p class="ecp-sub">Preço declarado, tabela e condições não são necessariamente a mesma coisa.</p></div><span class="ecp-chip ecp-chip-pending">Revisão necessária</span></div>
    <div class="ecp-note ecp-warning">{{ $monthlyAuditV11['reason'] ?? 'Valores ou condições diferentes no documento.' }} O valor declarado é uma referência documental; o valor financeiro cadastrado não foi substituído automaticamente.</div>
    <div class="ecp-scroll ecp-spaced"><table class="ecp-table"><thead><tr><th>Origem</th><th>Valor / evidência</th></tr></thead><tbody>
        @foreach($declaredV11 as $candidateV11)<tr><td>Investimento mensal declarado</td><td><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($candidateV11) }}</strong></td></tr>@endforeach
        @foreach(($monthlyAuditV11['table_values'] ?? []) as $candidateV11)<tr><td>Total da tabela-base original</td><td><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($candidateV11) }}</strong></td></tr>@endforeach
        @foreach(($monthlyAuditV11['net_values'] ?? []) as $candidateV11)<tr><td>Total líquido original</td><td>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($candidateV11) }}</td></tr>@endforeach
        <tr><td>Valor atualmente cadastrado</td><td>{{ $monthly === null ? 'Não confirmado' : \App\Services\Proposals\HistoricalProposalReadModel::money($monthly) }}</td></tr>
    </tbody></table></div>
    @if(!empty($monthlyAuditV11['evidence']))<details class="ecp-disclosure ecp-spaced"><summary>Ver evidências do documento</summary><div style="padding:12px 16px">@foreach($monthlyAuditV11['evidence'] as $candidateV11)<p class="ecp-sub">{{ $candidateV11['trecho'] ?? '' }}</p>@endforeach</div></details>@endif
</section>
