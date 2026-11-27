@php $moneyV20 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
<div class="ecp-summary" data-ec-history-review-v20>
    <div class="ecp-value ecp-value-main">
        <span class="ecp-label">{{ $reviewV20['principal_label'] }}</span>
        <strong>{{ isset($reviewV20['monthly_summary']) ? $moneyV20($reviewV20['monthly_summary']).'/mês' : (isset($reviewV20['summary']) ? $moneyV20($reviewV20['summary']) : ($reviewV20['principal'] !== null ? $moneyV20($reviewV20['principal']) : match($reviewV20['kind']) {'simulacoes'=>'Por volume','condicoes'=>'Por condição','divergencia'=>$reviewV20['principal_label'],'modalidades'=>'Por modalidade',default=>'Consultar cenários'})) }}</strong>
        <span class="ecp-sub">{{ $reviewV20['scope'] ?? 'Consultas retroativas · prestação de serviço pontual' }}</span>
    </div>
    <div class="ecp-value"><span class="ecp-label">{{ isset($reviewV20['setup_summary']) ? 'Implantação oferecida' : match($reviewV20['setup_mode']) { 'conditional'=>'Implantação condicional','additional'=>'Implantação adicional','by_modality'=>'Implantação por modalidade',default=>'Implantação' } }}</span><strong>{{ isset($reviewV20['setup_summary']) ? $moneyV20($reviewV20['setup_summary']) : (($reviewV20['setup_mode'] ?? '') === 'by_modality' ? 'Conforme modalidade' : ($reviewV20['setup_amount'] === null ? 'Não informada' : $moneyV20($reviewV20['setup_amount']))) }}</strong><span class="ecp-sub">{{ $reviewV20['setup_note'] }}</span></div>
    <div class="ecp-value"><span class="ecp-label">{{ $reviewV20['base_label'] ?? 'Documentos do período' }}</span><strong>{{ $reviewV20['base_quantity'] !== null ? number_format((int)$reviewV20['base_quantity'],0,',','.') : 'Conforme volume' }}</strong><span class="ecp-sub">Base histórica informada no documento original</span></div>
</div>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Informações da proposta</h2><p class="ecp-sub">Conferência direta do documento original · V{{ $reviewV20['version'] }}</p></div></div>
    <dl class="ecp-facts">
        @foreach(['Razão social'=>$reviewV20['client'],'Nome no cadastro'=>$histProposal->client_name,'CNPJ'=>$histProposal->client_cnpj ?: 'Não identificado','Contato do cliente'=>$reviewV20['contact'],'Número do arquivo'=>'EC '.$reviewV20['filename_number'],'Número da capa'=>'EC '.$reviewV20['cover_number'],'Emissão'=>\App\Services\Proposals\HistoricalProposalReadModel::date($reviewV20['issued_on']),'Responsável pela proposta'=>$reviewV20['signer'],'E-mail do responsável'=>$reviewV20['responsible_email'] ?? 'Consultar documento','Serviço'=>$reviewV20['scope'] ?? 'Consultas retroativas','E-mail do destinatário indicado'=>$histInfo?->recipient_email ?: ($identity['recipient_email'] ?? ($histProposal->client_email ?: 'Não identificado'))] as $label=>$value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <p class="ecp-note ecp-spaced">A numeração, a situação comercial, os documentos originais e os vínculos CRM permanecem como cadastrados. A identificação da capa e da assinatura é documental; não comprova aceite.</p>
</section>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Memória de cálculo · cenários da proposta</h2><p class="ecp-sub">Valores históricos conforme o documento original. Não se somam alternativas nem se usa a tabela atual.</p></div></div>
    <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Cenário / condição</th><th>Documentos</th><th class="ecp-number">Serviço pontual</th><th class="ecp-number">Mensalidade</th><th class="ecp-number">Implantação neste cenário</th><th class="ecp-number">Desconto</th><th class="ecp-number">Total pontual na condição</th><th>Fonte</th></tr></thead><tbody>
        @foreach($reviewV20['scenarios'] as $scenario)
            <tr><td><strong>{{ $scenario['label'] }}</strong>@if(!empty($scenario['note']))<span class="ecp-sub">{{ $scenario['note'] }}</span>@endif</td><td>{{ $scenario['quantity'] !== null ? number_format((int)$scenario['quantity'],0,',','.') : '—' }}</td><td class="ecp-number">{{ $scenario['service'] !== null ? $moneyV20($scenario['service']) : '—' }}</td><td class="ecp-number">{{ isset($scenario['monthly']) ? $moneyV20($scenario['monthly']).'/mês' : '—' }}</td><td class="ecp-number">{{ $scenario['setup'] !== null ? $moneyV20($scenario['setup']) : '—' }}</td><td class="ecp-number">{{ isset($scenario['discount']) ? $moneyV20($scenario['discount']) : '—' }}</td><td class="ecp-number">{{ $scenario['total'] !== null ? $moneyV20($scenario['total']) : '—' }}@if(isset($scenario['computed_total']))<span class="ecp-sub">Calculado: {{ $moneyV20($scenario['computed_total']) }} · conferir diferença</span>@endif</td><td>{{ $scenario['locator'] ?? ('PDF, p. '.$scenario['page']) }}</td></tr>
        @endforeach
    </tbody></table></div>
    <div class="ecp-note ecp-spaced"><strong>Implantação:</strong> {{ $reviewV20['setup_note'] }} Não presumir contratação do licenciamento nem somar modalidades alternativas.</div>
    @if(!empty($reviewV20['preserved_amounts']))<div class="ecp-note ecp-warning ecp-spaced"><strong>Valores cadastrados anteriormente (preservados):</strong> {{ implode(' / ',array_map($moneyV20,$reviewV20['preserved_amounts'])) }}. Não foram usados para escolher uma condição comercial.</div>@endif
@if(!empty($reviewV20['preserved_monthly_amounts']))<div class="ecp-note ecp-warning ecp-spaced"><strong>Mensalidades anteriores preservadas:</strong> {{ implode(' / ',array_map($moneyV20,$reviewV20['preserved_monthly_amounts'])) }}. Não confirmam a modalidade contratada.</div>@endif
</section>
<section class="ecp-panel">
    <div class="ecp-head"><h2>Condições comerciais e conferência</h2></div>
    <dl class="ecp-facts">
        @foreach($reviewV20['conditions'] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
    </dl>
    @if(!empty($reviewV20['document_group']))<p class="ecp-note ecp-spaced">Há versões relacionadas no catálogo documental. Os registros permanecem separados; a relação não confirma aceite.</p>@endif
    @foreach($reviewV20['notes'] as $note)<div class="ecp-note ecp-spaced">{{ $note }}</div>@endforeach
    <details class="ecp-disclosure ecp-spaced"><summary>Documento conferido</summary><div style="padding:12px 16px"><p class="ecp-sub">{{ $reviewV20['filename'] }}</p>@foreach(($reviewV20['inventory_paths'] ?? []) as $sourcePath)<p class="ecp-sub" style="overflow-wrap:anywhere">Inventário: {{ $sourcePath }}</p>@endforeach<p class="ecp-sub" style="overflow-wrap:anywhere">SHA-256: {{ $reviewV20['sha256'] }}</p></div></details>
</section>
