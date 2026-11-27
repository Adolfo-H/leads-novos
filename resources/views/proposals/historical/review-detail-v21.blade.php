@php $moneyV21 = static fn($v) => \App\Services\Proposals\HistoricalProposalReadModel::money($v); @endphp
<div class="ecp-summary" data-ec-history-review-v21>
    <div class="ecp-value ecp-value-main">
        <span class="ecp-label">{{ $reviewV21['principal_label'] }}</span>
        <strong>{{ isset($reviewV21['monthly_summary']) ? $moneyV21($reviewV21['monthly_summary']).'/mês' : (isset($reviewV21['summary']) ? $moneyV21($reviewV21['summary']) : ($reviewV21['principal'] !== null ? $moneyV21($reviewV21['principal']) : match($reviewV21['kind']) {'simulacoes'=>'Por volume','condicoes'=>'Por condição','divergencia'=>$reviewV21['principal_label'],'modalidades'=>'Por modalidade',default=>'Consultar cenários'})) }}</strong>
        <span class="ecp-sub">{{ $reviewV21['scope'] ?? 'Consultas retroativas · prestação de serviço pontual' }}</span>
    </div>
    <div class="ecp-value"><span class="ecp-label">{{ isset($reviewV21['setup_summary']) ? 'Implantação oferecida' : match($reviewV21['setup_mode']) { 'conditional'=>'Implantação condicional','additional'=>'Implantação adicional','by_modality'=>'Implantação por modalidade',default=>'Implantação' } }}</span><strong>{{ isset($reviewV21['setup_summary']) ? $moneyV21($reviewV21['setup_summary']) : (($reviewV21['setup_mode'] ?? '') === 'by_modality' ? 'Conforme modalidade' : ($reviewV21['setup_amount'] === null ? 'Não informada' : $moneyV21($reviewV21['setup_amount']))) }}</strong><span class="ecp-sub">{{ $reviewV21['setup_note'] }}</span></div>
    <div class="ecp-value"><span class="ecp-label">{{ $reviewV21['base_label'] ?? 'Documentos do período' }}</span><strong>{{ $reviewV21['base_quantity'] !== null ? number_format((int)$reviewV21['base_quantity'],0,',','.') : 'Conforme volume' }}</strong><span class="ecp-sub">Base histórica informada no documento original</span></div>
</div>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Informações da proposta</h2><p class="ecp-sub">Conferência direta do documento original · V{{ $reviewV21['version'] }}</p></div></div>
    <dl class="ecp-facts">
        @foreach(['Razão social'=>$reviewV21['client'],'Nome no cadastro'=>$histProposal->client_name,'CNPJ'=>$histProposal->client_cnpj ?: 'Não identificado','Contato do cliente'=>$reviewV21['contact'],'Número do arquivo'=>'EC '.$reviewV21['filename_number'],'Número da capa'=>'EC '.$reviewV21['cover_number'],'Emissão'=>\App\Services\Proposals\HistoricalProposalReadModel::date($reviewV21['issued_on']),'Responsável pela proposta'=>$reviewV21['signer'],'E-mail do responsável'=>$reviewV21['responsible_email'] ?? 'Consultar documento','Serviço'=>$reviewV21['scope'] ?? 'Consultas retroativas','E-mail do destinatário indicado'=>$histInfo?->recipient_email ?: ($identity['recipient_email'] ?? ($histProposal->client_email ?: 'Não identificado'))] as $label=>$value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <p class="ecp-note ecp-spaced">A numeração, a situação comercial, os documentos originais e os vínculos CRM permanecem como cadastrados. A identificação da capa e da assinatura é documental; não comprova aceite.</p>
</section>
<section class="ecp-panel">
    <div class="ecp-head"><div><h2>Memória de cálculo · cenários da proposta</h2><p class="ecp-sub">Valores históricos conforme o documento original. Não se somam alternativas nem se usa a tabela atual.</p></div></div>
    <div class="ecp-scroll"><table class="ecp-table"><thead><tr><th>Cenário / condição</th><th>Documentos</th><th class="ecp-number">Serviço pontual</th><th class="ecp-number">Mensalidade</th><th class="ecp-number">Implantação neste cenário</th><th class="ecp-number">Desconto</th><th class="ecp-number">Total pontual na condição</th><th>Fonte</th></tr></thead><tbody>
        @foreach($reviewV21['scenarios'] as $scenario)
            <tr><td><strong>{{ $scenario['label'] }}</strong>@if(!empty($scenario['note']))<span class="ecp-sub">{{ $scenario['note'] }}</span>@endif</td><td>{{ $scenario['quantity'] !== null ? number_format((int)$scenario['quantity'],0,',','.') : '—' }}</td><td class="ecp-number">{{ $scenario['service'] !== null ? $moneyV21($scenario['service']) : '—' }}</td><td class="ecp-number">{{ isset($scenario['monthly']) ? $moneyV21($scenario['monthly']).'/mês' : '—' }}</td><td class="ecp-number">{{ $scenario['setup'] !== null ? $moneyV21($scenario['setup']) : '—' }}</td><td class="ecp-number">{{ isset($scenario['discount']) ? $moneyV21($scenario['discount']) : '—' }}</td><td class="ecp-number">{{ $scenario['total'] !== null ? $moneyV21($scenario['total']) : '—' }}@if(isset($scenario['computed_total']))<span class="ecp-sub">Calculado: {{ $moneyV21($scenario['computed_total']) }} · conferir diferença</span>@endif</td><td>{{ $scenario['locator'] ?? ('PDF, p. '.$scenario['page']) }}</td></tr>
        @endforeach
    </tbody></table></div>
    <div class="ecp-note ecp-spaced"><strong>Implantação:</strong> {{ $reviewV21['setup_note'] }} Não presumir contratação do licenciamento nem somar modalidades alternativas.</div>
    @if(!empty($reviewV21['preserved_amounts']))<div class="ecp-note ecp-warning ecp-spaced"><strong>Valores cadastrados anteriormente (preservados):</strong> {{ implode(' / ',array_map($moneyV21,$reviewV21['preserved_amounts'])) }}. Não foram usados para escolher uma condição comercial.</div>@endif
@if(!empty($reviewV21['preserved_monthly_amounts']))<div class="ecp-note ecp-warning ecp-spaced"><strong>Mensalidades anteriores preservadas:</strong> {{ implode(' / ',array_map($moneyV21,$reviewV21['preserved_monthly_amounts'])) }}. Não confirmam a modalidade contratada.</div>@endif
</section>
<section class="ecp-panel">
    <div class="ecp-head"><h2>Condições comerciais e conferência</h2></div>
    <dl class="ecp-facts">
        @foreach($reviewV21['conditions'] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
    </dl>
    @if(!empty($reviewV21['document_group']))<p class="ecp-note ecp-spaced">Há versões relacionadas no catálogo documental. Os registros permanecem separados; a relação não confirma aceite.</p>@endif
    @foreach($reviewV21['notes'] as $note)<div class="ecp-note ecp-spaced">{{ $note }}</div>@endforeach
    <details class="ecp-disclosure ecp-spaced"><summary>Documento conferido</summary><div style="padding:12px 16px"><p class="ecp-sub">{{ $reviewV21['filename'] }}</p>@foreach(($reviewV21['inventory_paths'] ?? []) as $sourcePath)<p class="ecp-sub" style="overflow-wrap:anywhere">Inventário: {{ $sourcePath }}</p>@endforeach<p class="ecp-sub" style="overflow-wrap:anywhere">SHA-256: {{ $reviewV21['sha256'] }}</p></div></details>
</section>
