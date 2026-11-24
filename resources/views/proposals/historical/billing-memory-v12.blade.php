@php $brV12 = static fn($value) => \App\Services\Proposals\HistoricalProposalReadModel::money($value); @endphp
<div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Componente do investimento inicial</th><th>Natureza</th><th class="ecp-number">Valor</th></tr></thead><tbody>
    <tr><td>Instalação, configuração e treinamento</td><td>Implantação</td><td class="ecp-number">{{ $brV12($billingV12['implantation']) }}</td></tr>
    @if($billingV12['retroactive'] !== null)<tr><td>Consultas retroativas @if(isset($billingV12['retro_quantity']))· {{ number_format((float)$billingV12['retro_quantity'],0,',','.') }} NFs @endif</td><td>Avulso</td><td class="ecp-number">{{ $brV12($billingV12['retroactive']) }}</td></tr>@endif
    @if($billingV12['historical_import'] !== null)<tr><td>Importação histórica de notas</td><td>Avulso</td><td class="ecp-number">{{ $brV12($billingV12['historical_import']) }}</td></tr>@endif
    @if($billingV12['extra_period'] !== null)<tr><td>Período inicial adicional</td><td>Licenciamento</td><td class="ecp-number">{{ $brV12($billingV12['extra_period']) }}</td></tr>@endif
    @if($billingV12['annual_amount'] !== null)<tr><td>Pacote anual</td><td>Licenciamento antecipado</td><td class="ecp-number">{{ $brV12($billingV12['annual_amount']) }}</td></tr>@endif
    <tr><td colspan="2"><strong>Total inicial indicado na proposta</strong></td><td class="ecp-number"><strong>{{ $brV12($billingV12['initial_total']) }}</strong></td></tr>
</tbody></table></div>
@if(!empty($billingV12['post_fixed']))<p class="ecp-note ecp-spaced">Modalidade pós-fixada: mensalidade apurada conforme a utilização. Simulações não são valores contratados.</p>@endif
@if(!empty($billingV12['table']['bands']))
    <div class="ecp-spaced"><h3>Tabela-base original do licenciamento</h3><div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Faixa</th><th>Mínimo</th><th>Máximo</th><th>Fixo</th><th>Por NF</th><th>NFs</th><th class="ecp-number">Subtotal</th></tr></thead><tbody>
    @foreach($billingV12['table']['bands'] as $band)<tr><td>{{ $band['faixa'] }}</td><td>{{ $band['min'] }}</td><td>{{ $band['max'] }}</td><td>{{ $formatBandMoney($band['fixo']) }}</td><td>{{ $formatBandMoney($band['unitario']) }}</td><td>{{ $band['quantidade'] }}</td><td class="ecp-number">{{ $formatBandMoney($band['subtotal']) }}</td></tr>@endforeach
    <tr><td colspan="5"><strong>Total da tabela-base</strong></td><td>{{ $billingV12['table']['table_quantity'] }}</td><td class="ecp-number"><strong>{{ $brV12($billingV12['table']['table_total']) }}</strong></td></tr>
    </tbody></table></div></div>
@else<p class="ecp-note ecp-spaced">Tabela não estruturada com segurança; consultar o original. Os componentes do investimento foram identificados separadamente.</p>@endif
<details class="ecp-disclosure ecp-spaced"><summary>Evidências da composição do investimento</summary><div style="padding:12px 16px">@foreach(($billingV12['evidence'] ?? []) as $entry)<p class="ecp-sub">{{ $entry['text'] }}</p>@endforeach</div></details>
