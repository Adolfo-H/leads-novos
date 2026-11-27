@php $moneyV14 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
<div class="ecp-summary" data-ec-history-review-v14>
    <div class="ecp-value ecp-value-main">
        <span class="ecp-label">{{ $reviewV14['principal_label'] }}</span>
        <strong>{{ $reviewV14['principal'] !== null ? $moneyV14($reviewV14['principal']) : match($reviewV14['kind']) {'simulacoes'=>'Por volume','condicoes'=>'Por condição','divergencia'=>'R$ 0,05 de diferença',default=>'Consultar cenários'} }}</strong>
        <span class="ecp-sub">Consultas retroativas · prestação de serviço pontual</span>
    </div>
    <div class="ecp-value"><span class="ecp-label">{{ $reviewV14['setup_mode'] === 'conditional' ? 'Implantação condicional' : 'Implantação adicional' }}</span><strong>{{ $moneyV14($reviewV14['setup_amount']) }}</strong><span class="ecp-sub">{{ $reviewV14['setup_note'] }}</span></div>
    <div class="ecp-value"><span class="ecp-label">Documentos do período</span><strong>{{ $reviewV14['base_quantity'] !== null ? number_format((int)$reviewV14['base_quantity'],0,',','.') : 'Conforme volume' }}</strong><span class="ecp-sub">Base histórica informada no PDF</span></div>
</div>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Informações da proposta</h2><p class="ecp-sub">Conferência direta do documento original · V14</p></div></div>
    <dl class="ecp-facts">
        @foreach(['Razão social'=>$reviewV14['client'],'Nome no cadastro'=>$histProposal->client_name,'CNPJ'=>$histProposal->client_cnpj ?: 'Não identificado','Contato do cliente'=>$reviewV14['contact'],'Número do arquivo'=>'EC '.$reviewV14['filename_number'],'Número da capa'=>'EC '.$reviewV14['cover_number'],'Emissão'=>\App\Services\Proposals\HistoricalProposalReadModel::date($reviewV14['issued_on']),'Responsável pela proposta'=>$reviewV14['signer'],'Serviço'=>'Consultas retroativas','E-mail do destinatário indicado'=>$histInfo?->recipient_email ?: ($identity['recipient_email'] ?? ($histProposal->client_email ?: 'Não identificado'))] as $label=>$value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <p class="ecp-note ecp-spaced">A numeração, a situação comercial, os documentos originais e os vínculos CRM permanecem como cadastrados. A identificação da capa e da assinatura é documental; não comprova aceite.</p>
</section>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Memória de cálculo · cenários da proposta</h2><p class="ecp-sub">Valores históricos conforme o PDF. Não se somam alternativas nem se usa a tabela atual.</p></div></div>
    <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Cenário / condição</th><th>Documentos</th><th class="ecp-number">Serviço</th><th class="ecp-number">Implantação neste cenário</th><th class="ecp-number">Total na condição</th><th>Fonte</th></tr></thead><tbody>
        @foreach($reviewV14['scenarios'] as $scenario)
            <tr><td><strong>{{ $scenario['label'] }}</strong>@if(!empty($scenario['note']))<span class="ecp-sub">{{ $scenario['note'] }}</span>@endif</td><td>{{ $scenario['quantity'] !== null ? number_format((int)$scenario['quantity'],0,',','.') : '—' }}</td><td class="ecp-number">{{ $moneyV14($scenario['service']) }}</td><td class="ecp-number">{{ $scenario['setup'] !== null ? $moneyV14($scenario['setup']) : '—' }}</td><td class="ecp-number">{{ $scenario['total'] !== null ? $moneyV14($scenario['total']) : '—' }}</td><td>PDF, p. {{ $scenario['page'] }}</td></tr>
        @endforeach
    </tbody></table></div>
    <div class="ecp-note ecp-spaced"><strong>Implantação:</strong> {{ $reviewV14['setup_note'] }} Não presumir contratação do licenciamento nem somar opções.</div>
    @if(!empty($reviewV14['preserved_amounts']))<div class="ecp-note ecp-warning ecp-spaced"><strong>Valores cadastrados anteriormente (preservados):</strong> {{ implode(' / ',array_map($moneyV14,$reviewV14['preserved_amounts'])) }}. Não foram usados para escolher uma condição comercial.</div>@endif
</section>
<section class="ecp-panel">
    <div class="ecp-head"><h2>Condições comerciais e conferência</h2></div>
    <dl class="ecp-facts">
        @foreach($reviewV14['conditions'] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
    </dl>
    @foreach($reviewV14['notes'] as $note)<div class="ecp-note ecp-spaced">{{ $note }}</div>@endforeach
    <details class="ecp-disclosure ecp-spaced"><summary>Documento conferido</summary><div style="padding:12px 16px"><p class="ecp-sub">{{ $reviewV14['filename'] }}</p><p class="ecp-sub" style="overflow-wrap:anywhere">SHA-256: {{ $reviewV14['sha256'] }}</p></div></details>
</section>
