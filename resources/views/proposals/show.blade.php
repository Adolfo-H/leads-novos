<x-proposals.shell title="Proposta comercial" subtitle="Detalhes, valores e documentos preservados por versão.">

@php
    $ecHistoricalRecord = null;
    foreach (get_defined_vars() as $ecHistoricalVar) {
        if ($ecHistoricalVar instanceof \App\Models\EcProposal) { $ecHistoricalRecord = $ecHistoricalVar; break; }
    }
@endphp
@if(auth()->user() instanceof \App\Models\User && auth()->user()->isCommercialManager() && !request()->boolean('visao_original') && \Illuminate\Support\Facades\Schema::hasTable('ec_proposal_history_metadata') && $ecHistoricalRecord instanceof \App\Models\EcProposal && $ecHistoricalRecord->origin === 'imported')
@include('proposals.historical.show', ['histProposal' => $ecHistoricalRecord])
@else

    @php
        $br = static fn($value) => $value === null ? '—' : 'R$ '.number_format((float)$value,2,',','.');
        $numbers = (array) ($proposal->proposal_numbers ?? []);
        $snap = (array) ($proposal->price_snapshot ?? []);
        $canManage = auth()->user()?->isCommercialManager();
    @endphp
    <div class="card" style="margin-bottom:16px">
        <div class="cardhead"><div><span class="eyebrow">{{ $proposal->origin === 'imported' ? 'DOCUMENTO HISTÓRICO' : 'PROPOSTA EXPORTCONTROL' }}</span><h2>{{ $proposal->client_name }}</h2><p class="sub">{{ $numbers ? implode(' / ', $numbers) : ($proposal->legacy_code ?: 'Rascunho #'.$proposal->id) }} · {{ implode(' + ',(array)$proposal->service_codes) }}</p></div><span class="tag {{ $proposal->status }}">{{ strtoupper($proposal->status) }}</span></div>
        <div class="actions">
            @if($proposal->status==='draft')<a class="btn primary" href="{{ route('proposals.edit',$proposal) }}">Editar rascunho</a>@endif
            @if($proposal->status !== 'generating')<form method="POST" action="{{ route('proposals.duplicate',$proposal) }}">@csrf<button class="btn" type="submit">Duplicar como novo</button></form>@endif
            <a class="btn" href="{{ route('proposals.index') }}">Voltar às propostas</a>
            @if(auth()->user()?->isCommercialManager() && in_array($proposal->status,['issued','sent','accepted','partially_accepted'],true))
                <form method="POST" action="{{ route('proposals.revise',$proposal) }}">@csrf<button type="submit" class="btn">Criar revisão (mesmo número, REV)</button></form>
            @endif
            @if($company && ctype_digit($portal))<a class="btn" target="_blank" rel="noopener noreferrer" href="https://app.hubspot.com/contacts/{{ $portal }}/record/0-2/{{ $company->hubspot_id }}">Abrir empresa no HubSpot</a>@endif
            @if($deal && ctype_digit($portal))<a class="btn" target="_blank" rel="noopener noreferrer" href="https://app.hubspot.com/contacts/{{ $portal }}/record/0-3/{{ $deal->hubspot_id }}">Abrir negócio no HubSpot</a>@endif
        </div>
    </div>
    <div class="grid two">
        <div class="grid" style="align-content:start">
            <div class="card"><div class="cardhead"><h2>Serviços e memória de cálculo</h2><span class="tag">{{ $proposal->price_date?->format('d/m/Y') ?: 'Sem data-base' }}</span></div>
                @if($snap && ! empty($snap['items']))
                    <div class="summary"><div class="line"><span>Mensalidade final</span><strong>{{ $br($snap['monthly_final'] ?? null) }}</strong></div><div class="line"><span>Implantação e avulsos</span><strong>{{ $br($snap['one_time_final'] ?? null) }}</strong></div></div>
                    @foreach((array)$snap['items'] as $code=>$item)
                    <details style="margin-top:12px"><summary class="link">{{ $code }} · {{ $br($item['final']??null) }} — Ver memória de faixas</summary><div class="table-wrap" style="margin-top:8px"><table><thead><tr><th>Faixa</th><th>Qtd aplicada</th><th>Fixo líquido</th><th>Unitário líquido</th><th>Subtotal</th></tr></thead><tbody>@foreach((array)($item['tiers'] ?? []) as $tier)<tr><td>{{ $tier['min'] }}–{{ $tier['max']??'∞' }}</td><td>{{ $tier['applied'] }}</td><td>{{ $br($tier['fixed']) }}</td><td>{{ $br($tier['unit']) }}</td><td>{{ $br($tier['subtotal']) }}</td></tr>@endforeach</tbody></table></div></details>
                    @endforeach
                @else <div class="help">Ainda sem cotação. Informe volumes e serviços aprovados, ou consulte o PDF histórico original.</div>@endif
            </div>
            <div class="card"><h2>Arquivos e documentos</h2><p class="sub">Os PDFs e HTMLs ficam em armazenamento privado, acessíveis apenas a usuários autorizados.</p>
                <div class="list" style="margin-top:15px">@forelse($proposal->documents as $document)
                    <div class="listrow"><div><strong>{{ $document->original_name }}</strong><div class="sub">{{ $document->kind }} · {{ $document->service ?: 'Apresentação' }} · {{ number_format($document->bytes/1024,1,',','.') }} KB</div></div><a class="btn" href="{{ route('proposals.document',[$proposal,$document]) }}">Baixar</a></div>
                @empty <div class="help">Nenhum documento anexado.</div>@endforelse</div>
                @if($proposal->status==='generation_failed')<div class="notice error"><strong>Falha ao gerar:</strong> {{ $proposal->issue_error }}. Os números reservados não serão reutilizados.</div>@endif
            </div>
            <div class="card"><h2>Histórico auditável</h2><div class="list" style="margin-top:12px">@foreach($proposal->events as $event)<div class="listrow"><div><strong>{{ $event->event }}</strong><div class="sub">{{ $event->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</div></div><small>Usuário #{{ $event->actor_id }}</small></div>@endforeach</div></div>
        </div>
        <aside class="grid" style="align-content:start">
            <div class="card"><h2>Informações comerciais</h2><div class="divider"></div><div class="list">
                <div class="listrow"><span>Responsável</span><b>{{ $proposal->client_contact ?: 'Pendente' }}</b></div>
                <div class="listrow"><span>E-mail</span><b>{{ $proposal->client_email ?: 'Pendente' }}</b></div>
                <div class="listrow"><span>Emissão</span><b>{{ $proposal->issued_on?->format('d/m/Y') ?: 'Não emitida' }}</b></div>
                <div class="listrow"><span>Pagamento</span><b>{{ $proposal->payment_days }} dias</b></div>
                <div class="listrow"><span>Validade</span><b>{{ $proposal->validity_days }} dias</b></div>
            </div><p class="sub" style="margin-top:12px">{{ $proposal->notes ?: 'Sem observações adicionais' }}</p></div>
            @if($canManage && in_array($proposal->status,['draft','generation_failed'],true))
            <div class="card"><h2>{{ $proposal->status === 'generation_failed' ? 'Reprocessar PDF (mesmos números)' : 'Emitir proposta oficial' }}</h2><p class="sub">Exige dados e logo completos, precificação aprovada e numeração conciliada. Nenhum e-mail será enviado.</p>
                <form method="POST" action="{{ route('proposals.issue',$proposal) }}" style="margin-top:13px">@csrf<label class="choice"><input type="checkbox" name="confirm_issue" value="1" required>Confirmo revisão comercial, dados e documento.</label><button class="btn primary" type="submit" style="margin-top:10px">{{ $proposal->status==='generation_failed'?'Reprocessar documentos':'Emitir PDF e reservar números' }}</button></form>
            </div>
            @endif
            @if($canManage && in_array($proposal->status,['issued','sent','accepted','partially_accepted'],true))
            <div class="card"><h2>Registrar andamento</h2><p class="sub">Registro manual com evidência. Não envia e-mail e não presume aceite.</p>
                <form method="POST" action="{{ route('proposals.status',$proposal) }}" class="formgrid" style="margin-top:12px">@csrf
                    <label class="full">Nova situação<select name="status"><option value="sent">Enviada ao cliente</option><option value="accepted">Aceita (com confirmação inequívoca)</option><option value="declined">Recusada</option></select></label>
                    <fieldset class="full"><legend>Serviços efetivamente aceitos (obrigatório para aceite)</legend>
                        @foreach((array)$proposal->service_codes as $service)
                            <label class="choice" style="margin-top:5px"><input type="checkbox" name="accepted_services[]" value="{{ $service }}" @checked(in_array($service, (array)($proposal->accepted_services??[]),true))>{{ $service }}</label>
                        @endforeach
                        <small>Aceite parcial é registrado separadamente. Nenhum contrato é emitido automaticamente.</small>
                    </fieldset>
                    <label class="full">Evidência / referência do aceite ou envio<textarea required name="evidence" minlength="8" placeholder="Ex.: e-mail enviado em... / cliente aprovou serviços e números..."></textarea></label>
                    <button class="btn primary" type="submit">Registrar situação</button>
                </form>
            </div>
            @endif
        </aside>
    </div>

@endif
</x-proposals.shell>
