{{-- E05 · Resumo comercial. Dados locais; placeholders sempre explícitos. --}}
<section class="e53-screen" x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak aria-label="Resumo comercial">
    <div class="e53-metrics e53-metrics--four">
        <div class="e53-metric"><span>Situação CRM</span><strong>{{ $e53Status }}</strong><small>{{ $e53Deals->isNotEmpty() ? $e53Deals->count().' negócio(s) vinculado(s)' : 'Sem negócio ativo identificado' }}</small></div>
        <div class="e53-metric e53-metric--accent"><span>ICP</span><strong>{{ $e53Icp?->grade ?? '—' }} · {{ $e53Icp?->score ?? '—' }}</strong><small>{{ $e53Icp?->label ?: 'Perfil ainda não calculado' }}</small></div>
        <div class="e53-metric"><span>Exportação</span><strong>{{ $e53Signal }}</strong><small>{{ $e53Export?->research_status === 'completed' ? $company->exportEvidence->count().' evidência(s) analisada(s)' : 'Pesquisa pública não concluída' }}</small></div>
        <div class="e53-metric e53-metric--accent"><span>Score SDR</span><strong>{{ $e53Score?->score ?? '—' }}</strong><small>{{ $e53Score?->label ?: 'Score indisponível' }}</small></div>
    </div>

    <div class="e53-summary-layout">
        <div class="e53-summary-left">
            <h2 class="e53-section-title">Leitura comercial</h2>
            <div class="e53-mini-grid">
                <article class="e53-panel e53-mini">
                    <span class="e53-chip e53-chip--green">{{ $e53ResearchStatus === 'completed' ? 'Pesquisa concluída' : 'Pesquisa '.match($e53ResearchStatus){'queued'=>'na fila','processing'=>'em andamento','failed'=>'com erro',default=>'pendente'} }}</span>
                    <h3>{{ $e53Export?->research_status === 'completed' ? 'Potencial para conversa qualificada' : 'Contexto de prospecção' }}</h3>
                    <p>{{ \Illuminate\Support\Str::limit($e53ExportTitle, 220) }}</p>
                </article>
                <article class="e53-panel e53-mini">
                    <span class="e53-chip e53-chip--green">{{ $company->exportEvidence->count() ? 'Evidência registrada' : 'Sem evidências' }}</span>
                    <h3>Produtos e mercados citados</h3>
                    <p>{{ \Illuminate\Support\Str::limit((string) ($e53Export?->overall_summary ?: 'Ainda não há evidência pública suficiente para afirmar atuação em exportação.'), 200) }}</p>
                </article>
            </div>
            <article class="e53-panel e53-tall">
                <div class="e53-panel-heading"><h3>Negócios associados</h3><button class="e53-text-link" type="button" x-on:click="dossierView='crm'">Ver relacionamento <span aria-hidden="true">→</span></button></div>
                <div class="e53-table-wrap"><table class="e53-table">
                    <thead><tr><th>Negócio</th><th>Etapa</th><th>Responsável</th><th>Estado</th></tr></thead>
                    <tbody>
                        @forelse ($e53Deals->take(8) as $deal)
                            <tr><td><strong>{{ $deal->name ?: $deal->hubspot_id }}</strong></td><td>{{ $deal->stage_label ?: 'Não informada' }}</td><td>{{ $deal->owner_name ?: 'Não atribuído' }}</td><td>{{ $deal->is_closed ? ($deal->is_closed_won ? 'Ganho' : 'Encerrado') : 'Aberto' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="e53-empty">Não há negócios associados no espelho local do HubSpot.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
                @if ($e53Deals->count() > 8)<p class="e53-muted">Exibindo 8 de {{ $e53Deals->count() }} negócios. Consulte a seção de relacionamento.</p>@endif
            </article>
        </div>
        <aside class="e53-summary-right">
            <h2 class="e53-section-title">Próxima ação</h2>
            <article class="e53-panel e53-mini e53-next-action">
                <span class="e53-chip {{ $e53NextTask?->due_at?->isPast() ? 'e53-chip--amber' : 'e53-chip--green' }}">{{ $e53NextTask ? ($e53NextTask->due_at?->isPast() ? 'Atrasado' : 'Em aberto') : 'Sem tarefa aberta' }}</span>
                <h3>{{ $e53NextTask?->title ?: 'Sem ação agendada' }}</h3>
                <p>@if($e53NextTask?->due_at)Prazo: {{ $e53NextTask->due_at->format('d/m/Y H:i') }}@else Nenhum prazo ativo identificado no HubSpot. @endif</p>
                <p>Origem: {{ $e53NextTask ? 'Tarefa HubSpot' : 'Base local' }}</p>
                <button class="e53-text-link" type="button" x-on:click="dossierView='history'">Ver detalhes <span aria-hidden="true">→</span></button>
            </article>
            <article class="e53-panel e53-context">
                <h3>Contexto do relacionamento</h3>
                <dl>
                    <div><dt>Responsável</dt><dd>{{ $e53Owner }}</dd></div>
                    <div><dt>Verificado no CRM</dt><dd>{{ $e53Crm?->checked_at?->format('d/m/Y H:i') ?: 'Não informado' }}</dd></div>
                    <div><dt>Última atividade</dt><dd>{{ $e53LastActivity?->format('d/m/Y H:i') ?: 'Data indisponível' }}</dd></div>
                </dl>
            </article>
        </aside>
    </div>

    <article class="e53-panel e53-table-panel">
        <h3>Dimensões de exportação</h3>
        <div class="e53-table-wrap"><table class="e53-table">
            <thead><tr><th>Dimensão</th><th>Status</th><th>Confiança registrada</th><th>Última revisão</th></tr></thead>
            <tbody>
                @foreach (['direct'=>'Direta','indirect'=>'Indireta','trading'=>'Trading'] as $key=>$label)
                    @php $status = data_get($e53Export, $key.'_status'); $confirmed = (bool) data_get($e53Export, $key.'_confirmed'); $confidence = data_get($e53Export,$key.'_confidence'); @endphp
                    <tr><td><strong>{{ $label }}</strong></td>
                    <td>{{ $confirmed ? 'Confirmada' : (match($status){'yes'=>'Indícios','positive'=>'Indícios','no'=>'Sem indício','negative'=>'Sem indício','uncertain'=>'Inconclusiva',default=>'Não pesquisada'}) }}</td>
                    <td>{{ is_numeric($confidence) ? (int)$confidence.'%' : 'Não informada' }}</td>
                    <td>{{ $e53Export?->reviewed_at?->format('d/m/Y') ?: $e53Export?->researched_at?->format('d/m/Y') ?: '—' }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
        <p class="e53-caption">Dimensões independentes: indícios não confirmam operação de exportação. Informações de fontes públicas devem ser verificadas antes de abordagem comercial.</p>
    </article>

    <article class="e53-panel e53-table-panel">
        <div class="e53-panel-heading"><h3>Evidências de exportação</h3><button type="button" class="e53-text-link" x-on:click="dossierView='history'">Ver todas <span aria-hidden="true">→</span></button></div>
        <div class="e53-table-wrap"><table class="e53-table">
            <thead><tr><th>Sinal</th><th>Fonte</th><th>Data</th><th>Leitura</th></tr></thead>
            <tbody>
                @forelse ($company->exportEvidence->take(8) as $evidence)
                    <tr><td><strong>{{ ucfirst((string) ($evidence->signal ?: 'Contextual')) }}</strong></td><td>
                        @if (filter_var($evidence->source_url, FILTER_VALIDATE_URL) && in_array(parse_url($evidence->source_url, PHP_URL_SCHEME), ['https','http'], true))
                            <a class="e53-text-link" href="{{ $evidence->source_url }}" target="_blank" rel="noopener noreferrer">{{ $evidence->source_name ?: 'Abrir fonte' }} ↗</a>
                        @else{{ $evidence->source_name ?: 'Não informada' }}@endif
                    </td><td>{{ $evidence->observed_at?->format('d/m/Y') ?: '—' }}</td><td>{{ \Illuminate\Support\Str::limit((string) ($evidence->title ?: $evidence->evidence_text ?: '—'), 100) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="e53-empty">Nenhuma evidência de exportação registrada para esta empresa.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @if ($company->exportEvidence->count() > 8)<p class="e53-caption">Mais evidências disponíveis no histórico detalhado.</p>@endif
    </article>
</section>
