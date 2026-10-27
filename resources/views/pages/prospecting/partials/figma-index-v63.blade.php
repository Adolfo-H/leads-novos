{{--
    EC_PROSPECTING_FIGMA_V63: P01 + P02 + P02-CONFIRM
    Os dados e operações são exclusivamente do componente Volt existente.
    Nenhuma busca externa dispara antes do formulário; nenhuma rodada antes da confirmação.
--}}
<div class="ec-page-shell pr-workspace prv2-page prv63-page prv63-discovery"
     data-test="prospecting-workspace"
     data-ec-prospecting-figma="P01-P02-V63"
     x-data="{
         mode: '{{ $searched ? 'preview' : 'config' }}',
         dirty: false,
         statePickerOpen: false, cnaePickerOpen: false,
         stateSearch: '', cnaeSearch: '', candidateSearch: '',
         candidatePage: 1, selectedCandidate: 0,

         matches(query, text) {
             const normal = value => String(value || '').normalize('NFD')
                 .replace(/[\u0300-\u036f]/g, '').toLowerCase();
             return normal(text).includes(normal(query));
         }
     }"

>
    @php
        $runs = $this->recentRuns;
        $previewCount = count($prospects);
        $candidatePages = max(1, (int) ceil($previewCount / 6));
        $number = static fn ($value): string => number_format((int) $value, 0, ',', '.');
        $noveltyPercent = $discoveredCount > 0
            ? (int) round(min(100, max(0, $newCount * 100 / $discoveredCount)))
            : 0;
        $stateSummary = collect($selectedStates)->take(4)->implode(' · ');
        $cnaeSummary = collect($selectedCnaes)->take(2)->implode(' · ');
    @endphp

    <div class="prv63-sr-only">Configurar filtros · Revisar prévia · Confirmar envio · Acompanhar rodada</div>

    {{-- P01: configuração / P02: apresentação dos resultados. --}}
    <header class="prv63-page-header">
        <div class="prv63-kicker-line">
            <span class="prv63-kicker">PROSPECÇÃO</span>
            <span class="prv63-tag" x-show="!$wire.searched || mode === 'config'">Base nacional · critérios explícitos</span>
        </div>
        <h1 x-show="!$wire.searched || mode === 'config'">Encontre seu próximo mercado</h1>
        <h1 x-show="$wire.searched && mode === 'preview'" x-cloak>
            Sua busca encontrou {{ $number($discoveredCount) }} candidatos
        </h1>
        <p x-show="!$wire.searched || mode === 'config'">Defina critérios claros para consultar a base nacional da Receita.</p>
        <p x-show="$wire.searched && mode === 'preview'" x-cloak>
            {{ $stateSummary ?: 'Sem estados selecionados' }} · CNAEs {{ $cnaeSummary ?: 'não selecionados' }} · prévia cadastral
        </p>
        <div class="prv63-top-actions">
            <div x-show="!$wire.searched || mode === 'config'" class="prv63-inline-actions">
                <a class="prv63-button prv63-button-outline" href="{{ route('imports.index') }}" wire:navigate>Já tenho CNPJs</a>
                <button class="prv63-button prv63-button-primary" type="submit" form="prv63-filters-form"
                        wire:loading.attr="disabled" wire:target="search">
                    <span wire:loading.remove wire:target="search">Buscar prospects</span>
                    <span wire:loading wire:target="search">Buscando na Receita...</span>
                </button>
            </div>
            <div x-show="$wire.searched && mode === 'preview'" x-cloak class="prv63-inline-actions">
                <button type="button" class="prv63-button prv63-button-outline" x-on:click="mode = 'config'">Revisar critérios</button>
                <button type="button" class="prv63-button prv63-button-primary"
                        wire:click="confirmExecution"
                        x-bind:disabled="dirty"
                        wire:loading.attr="disabled" wire:target="confirmExecution,search,executeProspecting"
                        @disabled($prospects === [] || $confirmingExecution)>Iniciar qualificação</button>
            </div>
        </div>
    </header>

    @if ($errors->any())
        <div class="prv63-notice prv63-notice-error" role="alert" x-init="mode='config'">
            Revise os filtros selecionados antes de iniciar uma nova consulta.
            @foreach (['limit', 'selectedStates', 'selectedCnaes'] as $field)
                @error($field)<p>{{ $message }}</p>@enderror
            @endforeach
        </div>
    @endif
    @if ($error)
        <div class="prv63-notice prv63-notice-error" role="alert" x-init="mode='config'">
            <strong>Não foi possível concluir a operação</strong><p>{{ $error }}</p>
        </div>
    @endif
    @if ($executionMessage)
        <div class="prv63-notice prv63-notice-success" role="status">
            <div><strong>{{ $executedBatchUuid ? 'Prospecção iniciada' : 'Nenhum novo envio' }}</strong><p>{{ $executionMessage }}</p>
                @if ($executedBatchUuid)<small>A prévia pode apresentar os próximos candidatos disponíveis.</small>@endif
            </div>
            @if ($executedBatchUuid)
                <a class="prv63-button prv63-button-primary" href="{{ route('prospecting.show', $executedBatchUuid) }}" wire:navigate>Acompanhar rodada →</a>
            @endif
        </div>
    @endif

    <form id="prv63-filters-form" class="prv63-form" wire:submit="search"
          x-on:submit="dirty=false; mode='preview'; candidatePage=1; selectedCandidate=0"
          x-show="mode==='config' || !$wire.searched || !!$wire.error" novalidate>
        <section class="prv63-card prv63-config-card" aria-labelledby="prv63-quantity-title">
            <h2 id="prv63-quantity-title">Dimensione a busca</h2>
            <label class="prv63-field-label" for="prv63-limit">Quantidade pretendida</label>
            <div class="prv63-number-shell">
                <input id="prv63-limit" class="prv63-input prv63-input-number" type="number" min="1" max="200" step="1"
                       wire:model.blur.number="limit"
                       x-on:input="dirty=true" inputmode="numeric" aria-describedby="prv63-limit-hint">
                <div class="prv63-number-quick" aria-label="Atalhos de quantidade">
                    @foreach ([10, 20, 50, 100] as $preset)
                        <button type="button"
                                class="prv63-chip {{ (int) $limit === $preset ? 'is-active' : '' }}"
                                wire:click="$set('limit', {{ $preset }})"
                                x-on:click="dirty=true">{{ $preset }}</button>
                    @endforeach
                </div>
            </div>
            <small id="prv63-limit-hint">Atalhos: 10 · 20 · 50 · 100 · personalizada. Máximo: 200 empresas.</small>
        </section>

        <section class="prv63-card prv63-config-card" aria-labelledby="prv63-states-title">
            <div class="prv63-config-head">
                <h2 id="prv63-states-title">Estados prioritários</h2>
                <div class="prv63-inline-actions">
                    <button type="button" class="prv63-quiet-link" wire:click="selectAllStates" x-on:click="dirty=true">Selecionar todos</button>
                    <button type="button" class="prv63-quiet-link" wire:click="clearStates" x-on:click="dirty=true">Limpar</button>
                </div>
            </div>
            <span class="prv63-field-label">Selecionados</span>
            <div class="prv63-picker" :class="{ 'is-open': statePickerOpen }">
                <button type="button" class="prv63-picker-trigger" aria-label="Selecionar estados prioritários"
                        x-on:click="statePickerOpen = ! statePickerOpen"
                        x-bind:aria-expanded="statePickerOpen ? 'true' : 'false'">
                    <span>{{ $stateSummary ?: 'Selecione estados' }} @if (count($selectedStates) > 4) e mais {{ count($selectedStates) - 4 }} @endif</span>
                    <span aria-hidden="true" x-text="statePickerOpen ? '⌃' : '⌄'"></span>
                </button>
                <div class="prv63-picker-body" x-show="statePickerOpen" x-cloak x-on:click.outside="statePickerOpen = false">
                    <input type="search" class="prv63-picker-search" x-model="stateSearch" placeholder="Buscar estado ou sigla" aria-label="Buscar estados">
                    <div class="prv63-check-grid">
                        @foreach ($stateOptions as $uf => $stateName)
                            <label class="prv63-check-row" x-show="matches(stateSearch, $el.dataset.search)" data-search="{{ $uf }} {{ $stateName }}" wire:key="prv63-state-{{ $uf }}">
                                <input type="checkbox" value="{{ $uf }}" wire:model.live="selectedStates" x-on:change="dirty=true; statePickerOpen = true">
                                <span><strong>{{ $uf }}</strong> · {{ $stateName }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <small>Opções prioritárias: MA, SP, PA, RO, MT, MS, TO, GO e MG.</small>
        </section>

        <section class="prv63-card prv63-config-card" aria-labelledby="prv63-cnaes-title">
            <div class="prv63-config-head">
                <h2 id="prv63-cnaes-title">Atividades prioritárias</h2>
                <div class="prv63-inline-actions">
                    <button type="button" class="prv63-quiet-link" wire:click="selectAllCnaes" x-on:click="dirty=true">Selecionar todos</button>
                    <button type="button" class="prv63-quiet-link" wire:click="clearCnaes" x-on:click="dirty=true">Limpar</button>
                </div>
            </div>
            <span class="prv63-field-label">CNAEs</span>
            <div class="prv63-picker" :class="{ 'is-open': cnaePickerOpen }">
                <button type="button" class="prv63-picker-trigger" aria-label="Selecionar atividades prioritárias"
                        x-on:click="cnaePickerOpen = ! cnaePickerOpen"
                        x-bind:aria-expanded="cnaePickerOpen ? 'true' : 'false'">
                    <span>{{ $cnaeSummary ?: 'Selecione atividades' }} @if (count($selectedCnaes) > 2) e mais {{ count($selectedCnaes) - 2 }} @endif</span>
                    <span aria-hidden="true" x-text="cnaePickerOpen ? '⌃' : '⌄'"></span>
                </button>
                <div class="prv63-picker-body" x-show="cnaePickerOpen" x-cloak x-on:click.outside="cnaePickerOpen = false">
                    <input type="search" class="prv63-picker-search" x-model="cnaeSearch" placeholder="Código CNAE ou descrição" aria-label="Buscar CNAEs">
                    <div class="prv63-check-grid">
                        @foreach ($cnaeOptions as $code => $description)
                            <label class="prv63-check-row" x-show="matches(cnaeSearch, $el.dataset.search)" data-search="{{ $code }} {{ $description }}" wire:key="prv63-cnae-{{ $code }}">
                                <input type="checkbox" value="{{ $code }}" wire:model.live="selectedCnaes" x-on:change="dirty=true; cnaePickerOpen = true">
                                <span><strong>{{ $code }}</strong> · {{ $description }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <small>Também disponíveis: 0115600 Soja; 0111302 Milho; 1071600 Açúcar em bruto.</small>
        </section>

        <div class="prv63-notice prv63-notice-success" role="note">
            <span aria-hidden="true">✓</span><div><strong>Primeiro, uma prévia</strong><p>A busca consulta os critérios informados. Importação, qualificação, pesquisa externa e criação de oportunidade acontecem em etapas próprias.</p></div>
        </div>
        <div class="prv63-form-mobile-submit">
            <button class="prv63-button prv63-button-primary" type="submit" wire:loading.attr="disabled" wire:target="search">Buscar prospects</button>
        </div>
    </form>

    {{-- P02: resultados reais da consulta, sem gravar empresas até confirmação. --}}
    @if ($searched && ! $error)
        <section class="prv63-preview" aria-labelledby="prv63-preview-title" x-show="mode==='preview'" x-cloak>
            <h2 id="prv63-preview-title" class="prv63-sr-only">Revisar prévia de candidatos</h2>
            <div class="prv63-metrics" aria-label="Indicadores dos candidatos">
                <article class="prv63-metric"><span>Descobertos</span><strong>{{ $number($discoveredCount) }}</strong><small>Candidatos desta consulta</small></article>
                <article class="prv63-metric prv63-metric-accent"><span>Novos</span><strong>{{ $number($newCount) }}</strong><small>Ainda elegíveis para o fluxo</small></article>
                <article class="prv63-metric"><span>Já trabalhados</span><strong>{{ $number($knownCount) }}</strong><small>Preservados no histórico</small></article>
                <article class="prv63-metric"><span>Novidade da busca</span><strong>{{ $discoveredCount > 0 ? $noveltyPercent.'%' : '—' }}</strong><small>Proporção de novos candidatos; não é conversão</small></article>
            </div>
            <section class="prv63-card prv63-table-card" aria-label="Prévia cadastral dos candidatos">
                <div class="prv63-card-heading"><div><h2>Prévia cadastral</h2><small>CRM ainda não validado nesta etapa</small></div>
                    <input type="search" class="prv63-table-search" x-model="candidateSearch" x-on:input="candidatePage=1"
                           aria-label="Filtrar candidatos exibidos" placeholder="Buscar candidato...">
                </div>
                @if ($prospects !== [])
                    <div class="prv63-table-scroll"><table class="prv63-table">
                        <thead><tr><th>Empresa / CNPJ</th><th>UF</th><th>CNAE correspondente</th><th>Pré-ICP</th><th>Capital social</th><th>Unidades ativas</th></tr></thead>
                        <tbody>
                            @foreach ($prospects as $prospect)
                                @php
                                    $cnpj = (string) ($prospect['cnpj'] ?? '');
                                    $cnae = (string) ($prospect['matched_cnae'] ?? '');
                                    $gradeScore = $prospect['discovery_score'] ?? null;
                                    $grade = is_numeric($gradeScore) ? match(true) { $gradeScore >= 75 => 'A', $gradeScore >= 55 => 'B', $gradeScore >= 35 => 'C', default => 'D' } : '—';
                                @endphp
                                <tr wire:key="prv63-prospect-{{ $cnpj ?: $loop->index }}"
                                    data-search="{{ ($prospect['corporate_name'] ?? '').' '.$cnpj.' '.($prospect['state'] ?? '').' '.$cnae }}"
                                    x-show="(candidateSearch.trim() !== '' || candidatePage === {{ intdiv($loop->index, 6) + 1 }}) && matches(candidateSearch, $el.dataset.search)" x-cloak>
                                    <td><button type="button" class="prv63-company-name" x-on:click="selectedCandidate={{ $loop->index }}">{{ $prospect['corporate_name'] ?? 'Nome cadastral indisponível' }}</button>
                                        <small>{{ $cnpj ? \App\Support\Cnpj::format($cnpj) : 'CNPJ não informado' }}</small></td>
                                    <td>{{ $prospect['state'] ?? '—' }}</td>
                                    <td><span>{{ $cnae ?: '—' }}</span></td>
                                    <td>{{ $grade }}{{ is_numeric($gradeScore) ? ' · '.$gradeScore : '' }}</td>
                                    <td>{{ $this->capital($prospect['share_capital'] ?? null) }}</td>
                                    <td>{{ $prospect['active_establishments'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <footer class="prv63-table-footer">
                        <span x-show="candidateSearch.trim() === ''">Página <span x-text="candidatePage">1</span> de {{ $candidatePages }} · {{ $number($previewCount) }} candidatos na prévia</span>
                        <span x-show="candidateSearch.trim() !== ''" x-cloak>Pesquisando entre os {{ $number($previewCount) }} candidatos desta prévia</span>
                        <div class="prv63-inline-actions" x-show="candidateSearch.trim() === ''">
                            <button type="button" class="prv63-button prv63-button-outline prv63-button-small" x-on:click="candidatePage=Math.max(1,candidatePage-1)" x-bind:disabled="candidatePage<=1">Anterior</button>
                            <button type="button" class="prv63-button prv63-button-outline prv63-button-small" x-on:click="candidatePage=Math.min({{ $candidatePages }},candidatePage+1)" x-bind:disabled="candidatePage>={{ $candidatePages }}">Próxima</button>
                        </div>
                    </footer>
                @else
                    <div class="prv63-empty"><strong>Nenhum prospect encontrado</strong><p>Amplie os estados ou CNAEs e faça uma nova pesquisa.</p></div>
                @endif
            </section>
            <div class="prv63-notice prv63-notice-neutral"><span aria-hidden="true">✓</span><div><strong>Critérios vinculados à prévia</strong><p>Alterar quantidade, estados ou CNAEs exige uma nova busca antes da qualificação.</p></div></div>
            <div class="prv63-notice prv63-notice-warning" x-show="dirty" x-cloak><strong>Os filtros mudaram. Execute uma nova busca antes de confirmar.</strong></div>

            @if ($prospects !== [])
                <h3 class="prv63-subheading">Candidato expandido</h3>
                @foreach ($prospects as $prospect)
                    <article class="prv63-card prv63-candidate-detail" x-show="selectedCandidate === {{ $loop->index }}" x-cloak wire:key="prv63-detail-{{ $loop->index }}">
                        @php
                            $candidateScore = $prospect['discovery_score'] ?? null;
                            $candidateGrade = is_numeric($candidateScore) ? match (true) { $candidateScore >= 75 => 'A', $candidateScore >= 55 => 'B', $candidateScore >= 35 => 'C', default => 'D' } : '—';
                        @endphp
                        <span class="prv63-tag">Pré-ICP {{ $candidateGrade }}{{ is_numeric($candidateScore) ? ' · '.$candidateScore : '' }}</span>
                        <h3>{{ $prospect['corporate_name'] ?? 'Empresa' }}</h3>
                        <p><strong>Detalhes cadastrais:</strong> CNPJ raiz {{ $prospect['cnpj_root'] ?? '—' }} ·
                            {{ $prospect['active_establishments'] ?? '—' }} unidades ativas ·
                            {{ $prospect['active_states'] ?? '—' }} estados com unidades.</p>
                        <p class="prv63-muted">CRM ainda não validado nesta etapa; pesquisa de exportações e SDR serão avaliados durante o processamento.</p>
                    </article>
                @endforeach
            @endif
        </section>
    @endif

    {{-- P02-CONFIRM: a ação real só é executada ao confirmar neste diálogo. --}}
    @if ($confirmingExecution)
        <div class="prv63-modal-backdrop" wire:key="prv63-confirmation-modal">
            <section class="prv63-modal" role="dialog" aria-modal="true" aria-labelledby="prv63-confirm-title">
                <div class="prv63-modal-kicker">QUALIFICAÇÃO DE CANDIDATOS</div>
                <h2 id="prv63-confirm-title">Qualificar {{ $number($newCount) }} candidatos?</h2>
                <p>Confirme antes de iniciar o processamento. Os candidatos elegíveis serão revalidados no envio.</p>
                <div class="prv63-modal-summary">
                    <div><span>Descobertos</span><strong>{{ $number($discoveredCount) }}</strong></div>
                    <div><span>Novos</span><strong>{{ $number($newCount) }}</strong></div>
                    <div><span>Já trabalhados</span><strong>{{ $number($knownCount) }}</strong></div>
                </div>
                <ol class="prv63-modal-steps">
                    <li>Importar e enriquecer registros cadastrais</li>
                    <li>Calcular ICP e Score SDR</li>
                    <li>Verificar o relacionamento no CRM</li>
                    <li>Pesquisar exportação quando a empresa for elegível</li>
                    <li>Priorizar os candidatos disponíveis para atuação comercial</li>
                </ol>
                <div class="prv63-notice prv63-notice-warning"><strong>Pesquisas externas podem consumir créditos do provedor.</strong><p>Uma prévia não confirma exportação, nem cria automaticamente oportunidades no HubSpot.</p></div>
                <div class="prv63-modal-actions">
                    <button type="button" class="prv63-button prv63-button-outline" wire:click="cancelExecution" wire:loading.attr="disabled" wire:target="executeProspecting">Cancelar</button>
                    <button type="button" class="prv63-button prv63-button-primary" wire:click="executeProspecting" x-bind:disabled="dirty"
                            wire:loading.attr="disabled" wire:target="executeProspecting,cancelExecution">
                        <span wire:loading.remove wire:target="executeProspecting">Confirmar e executar</span>
                        <span wire:loading wire:target="executeProspecting">Iniciando rodada...</span>
                    </button>
                </div>
            </section>
        </div>
    @endif

    {{-- Rodadas reais do banco: tabela P01. --}}
    <section class="prv63-card prv63-runs-card" id="prv63-history" aria-labelledby="prv63-runs-title"
             x-show="mode === 'config' || !$wire.searched" x-cloak>
        <div class="prv63-card-heading"><div><h2 id="prv63-runs-title">Rodadas recentes</h2><small>Histórico de prospecção · últimas 10 execuções</small></div>
            <button type="button" class="prv63-quiet-link" wire:click="$refresh" wire:loading.attr="disabled">Atualizar</button>
        </div>
        @if ($runs !== [])
            <div class="prv63-table-scroll"><table class="prv63-table">
                <thead><tr><th>Rodada</th><th>Data</th><th>Candidatos</th><th>Estado</th><th>Ação</th></tr></thead>
                <tbody>
                    @foreach ($runs as $run)
                        @php
                            $runDate = ! empty($run['created_at']) ? \Illuminate\Support\Carbon::parse($run['created_at'])->format('d/m/Y H:i') : '—';
                            $runStatus = (string) ($run['status'] ?? '');
                        @endphp
                        <tr wire:key="prv63-run-{{ $run['uuid'] }}">
                            <td><strong>Rodada {{ \Illuminate\Support\Str::upper(substr((string) $run['uuid'], 0, 8)) }}</strong></td>
                            <td>{{ $runDate }}</td>
                            <td>{{ $number($run['total_rows'] ?? $run['discovered_count'] ?? 0) }}</td>
                            <td><span class="prv63-status {{ $runStatus === 'completed' ? 'is-success' : ($runStatus === 'failed' ? 'is-error' : '') }}">{{ $this->runStatusLabel($runStatus) }}</span></td>
                            <td><a class="prv63-action-link" href="{{ route('prospecting.show', $run['uuid']) }}" wire:navigate>Ver resultado →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @else
            <div class="prv63-empty"><strong>Nenhuma rodada registrada</strong><p>Após confirmar a primeira qualificação, as rodadas aparecerão aqui.</p></div>
        @endif
    </section>
    <footer class="prv63-page-footer">ExportControl Prospector · Consultas e resultados da base local</footer>
</div>
