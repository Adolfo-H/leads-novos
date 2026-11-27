@php $moneyV15 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
<div class="ecp-summary" data-ec-history-review-v15>
    <div class="ecp-value ecp-value-main">
        <span class="ecp-label">{{ $reviewV15['principal_label'] }}</span>
        <strong>{{ $reviewV15['principal'] !== null ? $moneyV15($reviewV15['principal']) : match($reviewV15['kind']) {'simulacoes'=>'Por volume','condicoes'=>'Por condição','divergencia'=>$reviewV15['principal_label'],default=>'Consultar cenários'} }}</strong>
        <span class="ecp-sub">Consultas retroativas · prestação de serviço pontual</span>
    </div>
    <div class="ecp-value"><span class="ecp-label">{{ match($reviewV15['setup_mode']) { 'conditional'=>'Implantação condicional','additional'=>'Implantação adicional',default=>'Implantação' } }}</span><strong>{{ $reviewV15['setup_amount'] === null ? 'Não informada' : $moneyV15($reviewV15['setup_amount']) }}</strong><span class="ecp-sub">{{ $reviewV15['setup_note'] }}</span></div>
    <div class="ecp-value"><span class="ecp-label">Documentos do período</span><strong>{{ $reviewV15['base_quantity'] !== null ? number_format((int)$reviewV15['base_quantity'],0,',','.') : 'Conforme volume' }}</strong><span class="ecp-sub">Base histórica informada no documento original</span></div>
</div>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Informações da proposta</h2><p class="ecp-sub">Conferência direta do documento original · V{{ $reviewV15['version'] }}</p></div></div>
    <dl class="ecp-facts">
        @foreach(['Razão social'=>$reviewV15['client'],'Nome no cadastro'=>$histProposal->client_name,'CNPJ'=>$histProposal->client_cnpj ?: 'Não identificado','Contato do cliente'=>$reviewV15['contact'],'Número do arquivo'=>'EC '.$reviewV15['filename_number'],'Número da capa'=>'EC '.$reviewV15['cover_number'],'Emissão'=>\App\Services\Proposals\HistoricalProposalReadModel::date($reviewV15['issued_on']),'Responsável pela proposta'=>$reviewV15['signer'],'E-mail do responsável'=>$reviewV15['responsible_email'] ?? 'Consultar documento','Serviço'=>'Consultas retroativas','E-mail do destinatário indicado'=>$histInfo?->recipient_email ?: ($identity['recipient_email'] ?? ($histProposal->client_email ?: 'Não identificado'))] as $label=>$value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <p class="ecp-note ecp-spaced">A numeração, a situação comercial, os documentos originais e os vínculos CRM permanecem como cadastrados. A identificação da capa e da assinatura é documental; não comprova aceite.</p>
</section>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Memória de cálculo · cenários da proposta</h2><p class="ecp-sub">Valores históricos conforme o documento original. Não se somam alternativas nem se usa a tabela atual.</p></div></div>
    <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Cenário / condição</th><th>Documentos</th><th class="ecp-number">Serviço</th><th class="ecp-number">Implantação neste cenário</th><th class="ecp-number">Total na condição</th><th>Fonte</th></tr></thead><tbody>
        @foreach($reviewV15['scenarios'] as $scenario)
            <tr><td><strong>{{ $scenario['label'] }}</strong>@if(!empty($scenario['note']))<span class="ecp-sub">{{ $scenario['note'] }}</span>@endif</td><td>{{ $scenario['quantity'] !== null ? number_format((int)$scenario['quantity'],0,',','.') : '—' }}</td><td class="ecp-number">{{ $moneyV15($scenario['service']) }}</td><td class="ecp-number">{{ $scenario['setup'] !== null ? $moneyV15($scenario['setup']) : '—' }}</td><td class="ecp-number">{{ $scenario['total'] !== null ? $moneyV15($scenario['total']) : '—' }}</td><td>{{ $scenario['locator'] ?? ('PDF, p. '.$scenario['page']) }}</td></tr>
        @endforeach
    </tbody></table></div>
    <div class="ecp-note ecp-spaced"><strong>Implantação:</strong> {{ $reviewV15['setup_note'] }} Não presumir contratação do licenciamento nem somar opções.</div>
    @if(!empty($reviewV15['preserved_amounts']))<div class="ecp-note ecp-warning ecp-spaced"><strong>Valores cadastrados anteriormente (preservados):</strong> {{ implode(' / ',array_map($moneyV15,$reviewV15['preserved_amounts'])) }}. Não foram usados para escolher uma condição comercial.</div>@endif
</section>
<section class="ecp-panel">
    <div class="ecp-head"><h2>Condições comerciais e conferência</h2></div>
    <dl class="ecp-facts">
        @foreach($reviewV15['conditions'] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
    </dl>
    @if(!empty($reviewV15['document_group']))<p class="ecp-note ecp-spaced">Há versões relacionadas no catálogo documental. Os registros permanecem separados; a relação não confirma aceite.</p>@endif
    @foreach($reviewV15['notes'] as $note)<div class="ecp-note ecp-spaced">{{ $note }}</div>@endforeach
    <details class="ecp-disclosure ecp-spaced"><summary>Documento conferido</summary><div style="padding:12px 16px"><p class="ecp-sub">{{ $reviewV15['filename'] }}</p>@foreach(($reviewV15['inventory_paths'] ?? []) as $sourcePath)<p class="ecp-sub" style="overflow-wrap:anywhere">Inventário: {{ $sourcePath }}</p>@endforeach<p class="ecp-sub" style="overflow-wrap:anywhere">SHA-256: {{ $reviewV15['sha256'] }}</p></div></details>
</section>
