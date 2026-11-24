<p class="ecp-sub">Composição por serviço. As faixas abaixo são as que constam no documento, sem recálculo.</p>
@foreach($priceV13['components'] ?? [] as $component)
    <div class="ecp-note ecp-spaced"><strong>{{ $component['label'] }} — {{ \App\Services\Proposals\HistoricalProposalReadModel::money($component['amount']) }}</strong> · {{ $component['billing'] === 'monthly' ? 'Mensal' : 'Pontual' }}</div>
    @if(!empty($component['bands']))<div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Faixa</th><th>Min.</th><th>Máx.</th><th>Fixo</th><th>Por NF</th><th>NFs</th><th>Subtotal</th></tr></thead><tbody>@foreach($component['bands'] as $band)<tr><td>{{ $band['faixa'] ?? '—' }}</td><td>{{ $band['min'] ?? '—' }}</td><td>{{ $band['max'] ?? '—' }}</td><td>{{ $formatBandMoney($band['fixo'] ?? null) }}</td><td>{{ $formatBandMoney($band['unitario'] ?? null) }}</td><td>{{ $band['quantidade'] ?? '—' }}</td><td>{{ $formatBandMoney($band['subtotal'] ?? null) }}</td></tr>@endforeach</tbody></table></div>
    @else<p class="ecp-sub">Faixas não estruturadas para este componente. Consulte o original.</p>@endif
@endforeach
