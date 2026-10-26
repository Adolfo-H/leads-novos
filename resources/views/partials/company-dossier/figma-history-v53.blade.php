{{-- E07 · Linha do tempo e fontes. Sem criar atividades fictícias. --}}
<section class="e53-screen" x-show="dossierTab === 'commercial' && dossierView === 'history'" x-cloak aria-label="Histórico, tarefas e evidências">
    <div class="e53-history-layout">
        <article class="e53-panel e53-timeline-panel">
            <h2>Histórico comercial</h2>
            <ol class="e53-timeline">
                @forelse ($e53Timeline->take(16) as $activity)
                    <li><span class="e53-timeline-dot" aria-hidden="true"></span>
                        <time>{{ $activity['at']?->format('d/m/Y H:i') ?: 'Data não informada' }}</time>
                        <strong>{{ $activity['title'] }}</strong>
                        <p>{{ \Illuminate\Support\Str::limit((string) $activity['description'], 280) }}</p>
                        <small>{{ $activity['origin'] }}</small>
                    </li>
                @empty
                    <li><span class="e53-timeline-dot" aria-hidden="true"></span><strong>Sem atividade registrada</strong><p>As atividades sincronizadas do HubSpot e os lançamentos comerciais aparecerão aqui.</p></li>
                @endforelse
            </ol>
            @if ($e53Timeline->count()>16)<p class="e53-caption">Exibindo as 16 atividades mais recentes de {{ $e53Timeline->count() }}.</p>@endif
        </article>
        <div class="e53-history-aside">
            <article class="e53-panel e53-table-panel">
                <h2>Tarefas disponíveis</h2>
                <div class="e53-table-wrap"><table class="e53-table">
                    <thead><tr><th>Assunto</th><th>Prazo</th><th>Estado</th></tr></thead>
                    <tbody>
                        @forelse ($e53Tasks->take(12) as $task)
                            <tr><td><strong>{{ $task->title ?: 'Sem assunto' }}</strong></td><td>{{ $task->due_at?->format('d/m/Y') ?: '—' }}</td><td>{{ $task->completed_at ? 'Concluída' : ($task->is_open ? ($task->due_at?->isPast() ? 'Atrasada' : 'Aberta') : 'Encerrada') }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="e53-empty">Nenhuma tarefa vinculada no HubSpot.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </article>
            <article class="e53-panel e53-icp-details">
                <h2>ICP {{ $e53Icp?->grade ?? '—' }} · {{ $e53Icp?->score ?? '—' }} / 100 pontos</h2>
                @php $e53Factors = is_array($e53Icp?->factors) ? $e53Icp->factors : []; @endphp
                @forelse ($e53Factors as $factorKey=>$factor)
                    @php
                        $e53FactorLabel = is_array($factor) ? (string)($factor['label'] ?? $factor['name'] ?? $factor['key'] ?? $factorKey) : (string)$factorKey;
                        $e53FactorPoints = is_array($factor) ? ($factor['score'] ?? $factor['points'] ?? null) : (is_numeric($factor)?$factor:null);
                        $e53FactorMax = is_array($factor) ? ($factor['max_score'] ?? $factor['max_points'] ?? $factor['max'] ?? null) : null;
                        $e53FactorPct = is_numeric($e53FactorPoints) && is_numeric($e53FactorMax) && (float)$e53FactorMax > 0
                            ? min(100, max(0, 100 * (float)$e53FactorPoints / (float)$e53FactorMax)) : null;
                    @endphp
                    <div class="e53-factor">
                        <span>{{ $e53FactorLabel }}</span>
                        <div class="e53-factor-track" aria-hidden="true"><i style="width:{{ $e53FactorPct ?? 0 }}%"></i></div>
                        <strong>{{ is_numeric($e53FactorPoints) ? $e53FactorPoints : '—' }}@if(is_numeric($e53FactorMax)) / {{ $e53FactorMax }}@endif</strong>
                    </div>
                @empty
                    <p class="e53-muted">A composição detalhada do ICP ainda não foi disponibilizada para esta empresa.</p>
                @endforelse
            </article>
        </div>
    </div>

    <h2 class="e53-section-title">Fontes para conferência</h2>
    <div class="e53-grid-2">
        @forelse ($company->exportEvidence->take(6) as $evidence)
            <article class="e53-panel e53-mini">
                <span class="e53-chip e53-chip--green">{{ ucfirst((string) ($evidence->signal ?: 'Evidência')) }}</span>
                <h3>{{ $evidence->title ?: $evidence->source_name ?: 'Fonte pública' }}</h3>
                <p>{{ \Illuminate\Support\Str::limit((string) ($evidence->evidence_text ?: 'Sem resumo textual'), 230) }}</p>
                <p>Fonte: {{ $evidence->source_name ?: 'Não informada' }} · {{ $evidence->observed_at?->format('d/m/Y') ?: 'Sem data' }}</p>
                @if (filter_var($evidence->source_url, FILTER_VALIDATE_URL) && in_array(parse_url($evidence->source_url, PHP_URL_SCHEME), ['https','http'], true))
                    <a class="e53-text-link" href="{{ $evidence->source_url }}" target="_blank" rel="noopener noreferrer">Abrir fonte <span aria-hidden="true">↗</span></a>
                @endif
            </article>
        @empty
            <article class="e53-panel e53-mini"><h3>Sem fontes registradas</h3><p>As fontes públicas consultadas durante a pesquisa de exportações aparecerão neste painel.</p></article>
        @endforelse
    </div>
    <div class="e53-origin-note"><strong>Origem do histórico</strong><p>Tarefas e atividades são consultadas no espelho HubSpot/Prospector. Esta tela não é um editor completo de chamadas, reuniões ou mensagens.</p></div>
    <div class="e53-notice e53-notice--green"><strong>Como ler a priorização</strong><p>O ICP e o Score SDR têm critérios distintos. A classificação não substitui validação comercial nem verificação das fontes.</p></div>
</section>
