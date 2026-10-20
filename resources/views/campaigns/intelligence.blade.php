{{-- EC_RECEITA_UNIFIED_V10 --}}
<x-layouts::app :title="'Inteligência Comercial'">
<style>
.eci9{max-width:1330px;margin:auto;padding:24px 16px;color:#e0f0f9}.eci9 .eyebrow{color:#52d6ca;letter-spacing:.12em;font-size:11px;font-weight:750}.eci9 h1{font-size:clamp(28px,3vw,36px);font-weight:750;margin:10px 0}.eci9 h2{font-size:18px;font-weight:700;margin:0 0 10px}.eci9 p,.eci9 .muted{color:#aac6d6;line-height:1.5}.eci9 .small{font-size:12px}.eci9 .tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:22px 0}.eci9 .tile,.eci9 .panel{background:#092941;border:1px solid #24506b;border-radius:14px;padding:20px;min-width:0}.eci9 .tile strong{font-size:30px;display:block;margin:8px 0}.eci9 .two{display:grid;grid-template-columns:1fr 1fr;gap:16px}.eci9 section{margin:18px 0}.eci9 label{display:block;font-size:13px;font-weight:650;margin:13px 0 6px}.eci9 input:not([type=checkbox]),.eci9 select,.eci9 textarea{width:100%;min-width:0;background:#061f34;border:1px solid #355c79;border-radius:9px;color:white;padding:11px}.eci9 textarea{min-height:112px}.eci9 input[type=checkbox]{accent-color:#3bdbcd}.eci9 .check{font-weight:400}.eci9 button{background:#3ed8ca;color:#053042;border:0;border-radius:10px;padding:12px 16px;font-weight:800;margin-top:12px;cursor:pointer}.eci9 button[disabled]{background:#35646b;color:#acc0c9;cursor:not-allowed}.eci9 .minor{font-size:12px;color:#a7c0d0}.eci9 table{width:100%;border-collapse:collapse;font-size:13px}.eci9 td,.eci9 th{padding:11px 9px;border-bottom:1px solid #244661;text-align:left;overflow-wrap:anywhere}.eci9 .scroll{overflow-x:auto}.eci9 .alert{background:#144459;border:1px solid #357384;color:#d4fff8;padding:14px;border-radius:10px}.eci9 .error{color:#ffb0b0}.eci9 .tag{display:inline-block;padding:3px 8px;background:#16465b;border-radius:15px;font-size:11px}.eci9 a{color:#60dfd4}.eci9 hr{border:0;border-top:1px solid #234961;margin:22px 0}@media(max-width:900px){.eci9 .tiles{grid-template-columns:repeat(2,1fr)}.eci9 .two{grid-template-columns:1fr}}@media(max-width:480px){.eci9 .tiles{grid-template-columns:1fr}}
</style>
<div class="eci9">
<span class="eyebrow">PROSPECTOR / CAMPANHAS / INTELIGÊNCIA</span>
<h1>Inteligência comercial</h1>
<p>Segmentos do HubSpot, Prospector e Receita Federal, follow-ups em rascunho e análise de resultados. Nenhuma automação de envio é ativada nesta tela.</p>
@include('campaigns.module-nav')
@if(session('intelligence_success'))<div class="alert" role="status">{{ session('intelligence_success') }}</div>@endif
@if($errors->any())<div class="alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="tiles">
    <div class="tile"><span class="muted small">Campanhas criadas · 90d</span><strong>{{ number_format($metrics['campaigns'],0,',','.') }}</strong></div>
    <div class="tile"><span class="muted small">Aceitas pelo SMTP · 90d</span><strong>{{ number_format($metrics['smtp_accepted'],0,',','.') }}</strong></div>
    <div class="tile"><span class="muted small">Respostas comerciais · 90d</span><strong>{{ number_format($metrics['responses'],0,',','.') }}</strong></div>
    <div class="tile"><span class="muted small">Devoluções · 90d</span><strong>{{ number_format($metrics['bounces'],0,',','.') }}</strong></div>
</div>
<p class="small">Descadastros nos últimos 90 dias: {{ $metrics['unsubscribes'] }}. Contatos de campanhas associados a negócios HubSpot: {{ $metrics['contacts_with_hubspot_deals'] }} (associação existente, <strong>não é atribuição de vendas</strong>).</p>
<section class="panel" id="segmentos">
<h2>1. Segmentação de contatos</h2><p>Contatos já bloqueados, clientes HubSpot marcados como <code>customer</code> e endereços já trabalhados ficam fora da seleção padrão. Cadastros manuais e canais da Receita exigem revisão para identificar clientes atuais e validar a finalidade do contato.</p>
<form method="GET" action="{{ route('campaigns.intelligence.index') }}">
<div class="two"><div><label for="origin">Origem</label><select name="origin" id="origin"><option value="all">Todas</option><option value="hubspot" {{ $filters['origin'] === 'hubspot' ? 'selected' : '' }}>HubSpot</option><option value="manual" {{ $filters['origin'] === 'manual' ? 'selected' : '' }}>Cadastros manuais</option><option value="receita" {{ $filters['origin'] === 'receita' ? 'selected' : '' }}>Receita Federal (canal cadastral)</option></select></div><div><label for="company">Empresa contém</label><input id="company" name="company" value="{{ $filters['company'] }}" placeholder="Nome da empresa"></div><div><label for="job">Cargo contém</label><input id="job" name="job" value="{{ $filters['job'] }}" placeholder="Contabilidade, fiscal..."></div><div><label for="search">Nome contém</label><input id="search" name="search" value="{{ $filters['search'] }}" placeholder="Nome do contato"></div></div>
<button type="submit">Pré-visualizar segmento</button>
</form>
<div class="scroll"><table><thead><tr><th>Contato</th><th>E-mail</th><th>Empresa / cargo</th><th>Origem</th></tr></thead><tbody>
@forelse($preview as $person)<tr><td>{{ $person['name'] }}</td><td>{{ $person['email'] }}</td><td>{{ $person['company'] }} · {{ $person['job'] }}</td><td>{{ $person['origin'] }} @if($person['needs_review'])<span class="tag">Revisar cliente</span>@endif</td></tr>@empty<tr><td colspan="4">Nenhum contato elegível para os filtros.</td></tr>@endforelse
</tbody></table></div><p class="small">Mostrando até 40 contatos elegíveis; filtros salvos são recalculados na criação do rascunho.</p>
<form method="POST" action="{{ route('campaigns.intelligence.segments.save') }}">@csrf
<input type="hidden" name="origin" value="{{ $filters['origin'] }}"><input type="hidden" name="company" value="{{ $filters['company'] }}"><input type="hidden" name="job" value="{{ $filters['job'] }}"><input type="hidden" name="search" value="{{ $filters['search'] }}"><input type="hidden" name="exclude_customers" value="1">
<label for="segment_name">Nome do segmento</label><input id="segment_name" name="segment_name" required maxlength="150" placeholder="Ex.: Gestores fiscais — cooperativas"><button type="submit">Salvar segmento</button>
</form>
</section>
<div class="two">
<section class="panel"><h2>2. Criar campanha em rascunho</h2><p>O Prospector escolhe somente contatos elegíveis do segmento salvo. <strong>Não envia mensagens</strong>.</p>
<form method="POST" action="{{ route('campaigns.intelligence.draft') }}">@csrf
<label for="segId">Segmento salvo</label><select id="segId" name="segment_id" required>@foreach($segments as $segment)<option value="{{ $segment->id }}">{{ $segment->name }}</option>@endforeach</select>
<label for="draftName">Nome da campanha</label><input id="draftName" name="name" required maxlength="150">
<label for="draftSubject">Assunto</label><input id="draftSubject" name="subject" required maxlength="180">
<label for="draftBody">Mensagem</label><textarea id="draftBody" name="body" required maxlength="12000" placeholder="Olá, contato..."></textarea>
<label class="check"><input type="checkbox" name="basis_reviewed" value="1" required> Revisei a base legal, os contatos e o objetivo da abordagem.</label>
<button type="submit" {{ $segments->isEmpty() ? 'disabled' : '' }}>Gerar rascunho para aprovação</button>
</form></section>
<section class="panel" id="followups"><h2>3. Follow-up sem resposta</h2><p>Planeje uma abordagem posterior. Quando vencer o prazo, o sistema poderá preparar um <strong>novo rascunho</strong> apenas de contatos sem resposta, bloqueio ou bounce. O gestor precisa aprovar o envio.</p>
<form method="POST" action="{{ route('campaigns.intelligence.sequence') }}">@csrf
<label for="seqSource">Campanha original</label><select id="seqSource" name="email_campaign_id" required>@foreach($campaigns as $campaign)<option value="{{ $campaign->id }}">#{{ $campaign->id }} · {{ $campaign->name }} ({{ $campaign->status }})</option>@endforeach</select>
<label for="seqName">Nome da etapa</label><input id="seqName" name="name" required maxlength="150" placeholder="Retorno após apresentação">
<label for="seqDays">Dias desde o aceite do SMTP</label><input id="seqDays" name="delay_days" type="number" min="1" max="90" value="5" required>
<label for="seqSubject">Assunto do follow-up</label><input id="seqSubject" name="subject" required maxlength="180">
<label for="seqBody">Mensagem do follow-up</label><textarea id="seqBody" name="body" required maxlength="12000"></textarea>
<button type="submit">Salvar planejamento</button>
</form></section>
</div>
<section class="panel"><h2>Planos de follow-up</h2>
<div class="scroll"><table><thead><tr><th>Plano</th><th>Campanha origem</th><th>Espera</th><th>Estado</th><th>Ação</th></tr></thead><tbody>
@forelse($sequences as $seq)<tr><td>{{ $seq->name }}</td><td>#{{ $seq->email_campaign_id }}</td><td>{{ $seq->delay_days }} dias</td><td><span class="tag">{{ $seq->status }}</span>@if($seq->draft_campaign_id) #{{ $seq->draft_campaign_id }} @endif</td><td>@if($seq->status === 'planned')<form method="POST" action="{{ route('campaigns.intelligence.prepare', ['sequence' => $seq->id]) }}">@csrf <label class="check"><input name="confirm_draft" value="1" type="checkbox" required> Gerar somente rascunho</label><button type="submit">Preparar</button></form>@else — @endif</td></tr>@empty<tr><td colspan="5">Ainda não existem follow-ups planejados.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel" id="responder"><h2>4. Responder pelo Prospector</h2><p>Envio <strong>individual</strong>, apenas para o endereço exato que respondeu a uma campanha. Não permite listas, anexos ou destinatários avulsos. Respostas incertas não serão reenviadas automaticamente.</p>
@if(!$repliesEnabled)<div class="alert">Indisponível enquanto o envio comercial ou a autorização específica para respostas estiverem desligados. Requer <code>CAMPAIGN_REPLY_SEND_ENABLED=true</code> e homologação comercial.</div>@endif
<form method="POST" action="{{ route('campaigns.intelligence.reply') }}">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label for="replyId">Resposta recebida</label><select id="replyId" name="reply_id" required>@foreach($replies as $reply)<option value="{{ $reply->id }}">#{{ $reply->id }} · {{ $reply->from_email }} · {{ $reply->subject }}</option>@endforeach</select>
<label for="replyBody">Sua mensagem</label><textarea id="replyBody" name="body" required maxlength="10000"></textarea>
<label class="check"><input type="checkbox" name="confirm_recipient" value="1" required> Confirmo que este é o destinatário correto e autorizo esta resposta individual.</label>
<button type="submit" {{ !$repliesEnabled || $replies->isEmpty() ? 'disabled' : '' }}>Enviar resposta individual</button>
</form></section>
<p class="small">Segurança: respeita opt-out, respostas e DSNs. Aceite SMTP não comprova recebimento. Negócios existentes no HubSpot não são atribuídos automaticamente a campanhas.</p>
</div>
</x-layouts::app>
