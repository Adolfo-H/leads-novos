{{-- V9: service tables remain independent; only the quoted combined amount is the total. --}}
<div class="ecp-note">Serviços históricos cobrados uma única vez. As bases das consultas e da cobrança não devem ser somadas como se fossem documentos diferentes. Valores estimados estão identificados separadamente.</div>
<div class="ecp-scroll ecp-spaced"><table class="ecp-table"><thead><tr><th>Serviço</th><th>Base de cálculo</th><th>Modalidade</th><th class="ecp-number">Valor</th></tr></thead><tbody>
    @foreach(($finance['components'] ?? []) as $component)
        <tr><td><strong>{{ $component['label'] }}</strong></td><td>{{ isset($component['table_quantity']) ? number_format((float)$component['table_quantity'],0,',','.').' NFs'.(!empty($component['estimated']) ? ' estimadas' : '') : 'Base não identificada' }}</td><td>Pagamento único{{ !empty($component['estimated']) ? ' · estimativa' : '' }}</td><td class="ecp-number"><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($component['amount']) }}</strong></td></tr>
    @endforeach
    <tr><td colspan="3"><strong>Total{{ !empty($finance['bundle_estimated']) ? ' estimado' : '' }} dos serviços (sem implantação condicional)</strong></td><td class="ecp-number"><strong>{{ $onceText }}</strong></td></tr>
    @if($conditionalImplantation && $implantation !== null)
        <tr><td colspan="3">Implantação condicional · somente se ocorrer a condição prevista</td><td class="ecp-number">{{ \App\Services\Proposals\HistoricalProposalReadModel::money($implantation) }}</td></tr>
        @if($conditionalTotal !== null)<tr><td colspan="3"><strong>Total com implantação condicional</strong></td><td class="ecp-number"><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($conditionalTotal) }}</strong></td></tr>@endif
    @endif
</tbody></table></div>
@foreach(($finance['components'] ?? []) as $component)
    <div class="ecp-spaced">
        <h3 style="margin-bottom:10px">{{ $component['label'] }} · memória de cálculo</h3>
        @if(!empty($component['estimated']))<p class="ecp-note" style="margin-bottom:10px">Base estimada de pendências. Sujeita a recálculo após as consultas, conforme condições da proposta.</p>@endif
        @if(!empty($component['bands']))
        <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Faixa</th><th>Mínimo</th><th>Máximo</th><th class="ecp-number">Investimento fixo</th><th class="ecp-number">Por NF excedente</th><th class="ecp-number">NFs aplicadas</th><th class="ecp-number">Subtotal</th></tr></thead><tbody>
        @foreach($component['bands'] as $band)
            <tr><td>{{ $band['faixa'] ?? '—' }}</td><td>{{ $band['min'] ?? '—' }}</td><td>{{ $band['max'] ?? '—' }}</td><td class="ecp-number">{{ $formatBandMoney($band['fixo'] ?? null) }}</td><td class="ecp-number">{{ $formatBandMoney($band['unitario'] ?? null) }}</td><td class="ecp-number">{{ $band['quantidade'] ?? '—' }}</td><td class="ecp-number">{{ $formatBandMoney($band['subtotal'] ?? null) }}</td></tr>
        @endforeach
        <tr><td colspan="5"><strong>Total informado</strong></td><td class="ecp-number"><strong>{{ number_format((float)$component['table_quantity'],0,',','.') }}</strong></td><td class="ecp-number"><strong>{{ \App\Services\Proposals\HistoricalProposalReadModel::money($component['table_total']) }}</strong></td></tr>
        </tbody></table></div>
        @else
            <p class="ecp-note">{{ !empty($component['table_pending']) ? 'Tabela não estruturada automaticamente. Consulte o documento original.' : 'Preço identificado pelo texto comercial; sem tabela de faixas identificada.' }}</p>
        @endif
    </div>
@endforeach
<p class="ecp-note ecp-spaced">Os subtotais e valores unitários são os valores impressos na proposta, sem recalcular tarifas ou arredondamentos.</p>
