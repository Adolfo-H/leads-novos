{{-- E05 · Resumo comercial. Dados locais; placeholders sempre explícitos. --}}
<section class="e53-screen" x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak aria-label="Resumo comercial">
{{-- DOSSIE_EXPORTACAO_V58: a leitura agora é produzida pelo serviço, sem concatenar textos brutos. --}}
    <div class="e53-metrics e53-metrics--four">
        <div class="e53-metric"><span>Situação CRM</span><strong>{{ $e53Status }}</strong><small>{{ $e53Deals->isNotEmpty() ? $e53Deals->count().' negócio(s) vinculado(s)' : 'Sem negócio ativo identificado' }}</small></div>
        <div class="e53-metric e53-metric--accent"><span>ICP</span><strong>{{ $e53Icp?->grade ?? '—' }} · {{ $e53Icp?->score ?? '—' }}</strong><small>{{ $e53Icp?->label ?: 'Perfil ainda não calculado' }}</small></div>
        <div class="e53-metric"><span>Exportação</span><strong>{{ $e53Signal }}</strong><small>Índice dos indícios: {{ $e58Dossier['index'] }}/100 · {{ $e58Dossier['evidence_count'] }} evidência(s)</small></div>
        <div class="e53-metric e53-metric--accent"><span>Score SDR</span><strong>{{ $e53Score?->score ?? '—' }}</strong><small>{{ $e53Score?->label ?: 'Score indisponível' }}</small></div>
    </div>

    <div class="e53-summary-layout">
        <div class="e53-summary-left">
                                    <h2 class="e53-section-title">Leitura comercial</h2>
            <div class="e53-mini-grid">
                <article class="e53-panel e53-mini">
                    <span class="e53-chip e53-chip--green">{{ $e53ResearchStatus === 'completed' ? 'Pesquisa concluída' : 'Pesquisa '.match($e53ResearchStatus){'queued'=>'na fila','processing'=>'em andamento','failed'=>'com erro',default=>'pendente'} }}</span>
                    <h3>O que a pesquisa encontrou</h3>
                    <p>{{ $e58Dossier['reading'] }}</p>
                </article>
                <article class="e53-panel e53-mini">
                    <span class="e53-chip e53-chip--green">{{ $e58Dossier['evidence_count'] }} evidência(s) consultada(s)</span>
                    <h3>Produtos e mercados citados</h3>
                    <p><strong>Produtos citados:</strong> {{ $e58Dossier['products'] }}</p>
                    <p><strong>Mercados citados:</strong> {{ $e58Dossier['markets'] }}</p>
                    <small>São menções das fontes, não destinos de exportação comprovados para a empresa.</small>
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
            <thead><tr><th>Dimensão</th><th>Situação</th><th>Força dos indícios</th><th>Interpretação</th></tr></thead>
            <tbody>
                @foreach ($e58Dossier['dimensions'] as $dimension)
                    <tr>
                        <td><strong>{{ $dimension['label'] }}</strong></td>
                        <td>{{ $dimension['badge'] }}</td>
                        <td><strong>{{ $dimension['index'] }}/100</strong></td>
                        <td>{{ $dimension['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <p class="e53-caption">O índice 0–100 considera sinais positivos, qualidade e diversidade das fontes, além de eventuais contradições. Não é probabilidade estatística nem comprovação fiscal. Menções neutras a trading ou exportação valem apenas como pistas contextuais; trading é agrupada em Indireta.</p>
    </article>

    <article class="e53-panel e53-table-panel">
        <div class="e53-panel-heading"><h3>Evidências de exportação</h3><button type="button" class="e53-text-link" x-on:click="dossierView='history'">Ver todas <span aria-hidden="true">→</span></button></div>
        <div class="e53-table-wrap"><table class="e53-table">
            <thead><tr><th>Sinal</th><th>Fonte</th><th>Publicação</th><th>Leitura</th></tr></thead>
            <tbody>
                @forelse ($company->exportEvidence->take(8) as $evidence)
                    <tr>
                        <td><strong>{{ ucfirst((string) ($evidence->signal ?: 'Contextual')) }}</strong></td>
                        <td>
                            @if (filter_var($evidence->source_url, FILTER_VALIDATE_URL) && in_array(parse_url($evidence->source_url, PHP_URL_SCHEME), ['https','http'], true))
                                <a class="e53-text-link" href="{{ $evidence->source_url }}" target="_blank" rel="noopener noreferrer">{{ $evidence->source_name ?: 'Abrir fonte' }} ↗</a>
                            @else{{ $evidence->source_name ?: 'Não informada' }}@endif
                        </td>
                        <td title="Data original da publicação, quando fornecida pela fonte">{{ app(\App\Services\CompanyExportDossierV58Service::class)->publicationDate($evidence) ?? 'Não informada' }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) ($evidence->title ?: $evidence->evidence_text ?: '—'), 105) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="e53-empty">Nenhuma evidência de exportação registrada para esta empresa.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <p class="e53-caption">A data exibida é a publicação original da fonte, não a consulta do Prospector. Fontes sem data publicada ficam marcadas como “Não informada”.</p>
    </article>
</section>
