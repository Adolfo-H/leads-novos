{{-- EC_BASE_FIGMA_V32: experiência D02 preservando as consultas e as ações existentes. --}}
<div class="ef32-base" data-ef32="base-empresarial-d02">
    @if ($manager)
        @php
            $ef32Tabs = [
                ['key' => 'companies', 'label' => 'Empresas', 'value' => $s['companies'], 'detail' => 'Empresas locais'],
                ['key' => 'establishments', 'label' => 'Estabelecimentos', 'value' => $s['establishments'], 'detail' => 'Matrizes e filiais'],
                ['key' => 'active', 'label' => 'Ativos', 'value' => $s['active'], 'detail' => 'Com situação ATIVA'],
                ['key' => 'cnaes', 'label' => 'CNAEs', 'value' => $s['cnaes'], 'detail' => 'CNAEs distintos'],
            ];
            $ef32CurrentTab = match ($dataset) {
                'cnaes', 'cnae' => 'cnaes',
                'active' => 'active',
                'companies' => 'companies',
                default => 'establishments',
            };
            $ef32States = [
                'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
                'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal',
                'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão',
                'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
                'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba',
                'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí',
                'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
                'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia',
                'RR' => 'Roraima', 'SC' => 'Santa Catarina',
                'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
            ];
        @endphp
        <header class="ef32-head">
            <div class="ef32-head-copy">
                <span class="ef32-eyebrow">DASHBOARD / BASE EMPRESARIAL</span>
                <h1>Explore sua base empresarial</h1>
                <p>Cadastros locais organizados por empresa, unidade e atividade.</p>
                <div class="ef32-head-actions">
                    <button type="button" class="ef32-button ef32-button--secondary" x-on:click="dashboardTab = 'commercial'">
                        ← Voltar ao dashboard
                    </button>
                    <a href="{{ route('prospecting.index') }}" wire:navigate class="ef32-button ef32-button--primary">Nova prospecção <span aria-hidden="true">↗</span></a>
                    <button type="button" class="ef32-button ef32-button--quiet" wire:click="refreshSummary" wire:loading.attr="disabled" title="Atualizar indicadores">↻ Atualizar</button>
                </div>
            </div>
        </header>

        <nav class="ef32-tabs" aria-label="Conjuntos de dados da base empresarial">
            @foreach ($ef32Tabs as $tab)
                <button type="button" data-dataset="{{ $tab['key'] }}"
                    class="ef32-tab {{ $dataset !== '' && $ef32CurrentTab === $tab['key'] ? 'is-active' : '' }}"
                    aria-current="{{ $dataset !== '' && $ef32CurrentTab === $tab['key'] ? 'page' : 'false' }}"
                    wire:click="selectBaseDataset('{{ $tab['key'] }}')" wire:loading.attr="disabled">
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </nav>

        <section class="ef32-kpis" aria-label="Indicadores gerais da base local">
            @foreach ($ef32Tabs as $tab)
                <button type="button" class="ef32-kpi {{ $dataset !== '' && $ef32CurrentTab === $tab['key'] ? 'is-active' : '' }}"
                    wire:click="selectBaseDataset('{{ $tab['key'] }}')" wire:loading.attr="disabled">
                    <span>{{ $tab['detail'] }}</span>
                    <strong>{{ $number($tab['value']) }}</strong>
                    <small>Ver registros <span aria-hidden="true">→</span></small>
                </button>
            @endforeach
        </section>

        <section class="ef32-search-card" aria-labelledby="ef32-search-heading">
            <div class="ef32-section-heading">
                <h2 id="ef32-search-heading">Busca na base local</h2>
                <span>Dados reais do Prospector</span>
            </div>
            <div class="ef32-fields">
                <div class="ef32-field">
                    <label for="ef32-search">{{ $dataset === 'cnaes' ? 'CNAE ou descrição' : 'Empresa ou CNPJ' }}</label>
                    <input id="ef32-search" type="search" wire:model.live.debounce.350ms="search"
                        maxlength="120" autocomplete="off"
                        placeholder="{{ $dataset === 'cnaes' ? 'Busque código ou atividade...' : 'Pesquise por empresa ou CNPJ...' }}">
                    <small>Até 120 caracteres. A pesquisa filtra os registros exibidos.</small>
                </div>
                <div class="ef32-field">
                    <label for="ef32-uf">Recorte atual</label>
                    <select id="ef32-uf" wire:model.live="baseUf" aria-label="Filtrar base por unidade federativa">
                        <option value="">Todas as UFs</option>
                        @foreach ($ef32States as $code => $stateName)
                            <option value="{{ $code }}">{{ $code }} — {{ $stateName }}</option>
                        @endforeach
                    </select>
                    <small>Filtro aplicado à tabela; indicadores acima mostram o total geral.</small>
                </div>
            </div>
            <div class="ef32-status" role="status" wire:loading.delay wire:target="search,baseUf,selectBaseDataset,openExplorer,nextPage,previousPage">
                Atualizando registros…
            </div>
            <div class="ef32-offline" role="status" wire:offline>Sem conexão. A lista pode estar desatualizada.</div>
        </section>

        <section class="ef32-results" data-overview-explorer tabindex="-1" aria-labelledby="ef32-table-title">
            @if ($dataset !== '')
                @php $records = $this->records; @endphp
                <div class="ef32-results-head">
                    <div>
                        <h2 id="ef32-table-title">{{ $this->explorerTitle }}</h2>
                        <p>{{ $number($records->total()) }} registros encontrados{{ $baseUf !== '' ? ' · UF: '.$baseUf : '' }}</p>
                    </div>
                    @if ($search !== '' || $baseUf !== '')
                        <button type="button" class="ef32-clear" wire:click="clearBaseFilters">Limpar filtros</button>
                    @endif
                </div>
                <div class="ef32-table-scroll" wire:loading.class="ef32-is-loading" wire:target="search,baseUf,selectBaseDataset,openExplorer,nextPage,previousPage">
                    <table class="ef32-table">
                        @if ($dataset === 'cnaes')
                            <thead><tr><th>CNAE</th><th>Atividade</th><th>Estabelecimentos</th><th>Ação</th></tr></thead>
                            <tbody>
                            @forelse ($records as $row)
                                <tr wire:key="ef32-cnae-{{ $row->id }}">
                                    <td class="ef32-code">{{ $row->code }}</td>
                                    <td>{{ $row->description ?: 'Descrição não informada' }}</td>
                                    <td>{{ $number($row->establishments_count) }}</td>
                                    <td><button type="button" class="ef32-open" wire:click="openExplorer('cnae', '{{ $row->code }}')">Ver unidades →</button></td>
                                </tr>
                            @empty
                                <tr><td class="ef32-empty" colspan="4">Nenhum CNAE encontrado para os filtros selecionados.</td></tr>
                            @endforelse
                            </tbody>
                        @elseif ($dataset === 'companies')
                            <thead><tr><th>Empresa</th><th>Raiz do CNPJ</th><th>Unidades</th><th>Ação</th></tr></thead>
                            <tbody>
                            @forelse ($records as $row)
                                <tr wire:key="ef32-company-{{ $row->id }}">
                                    <td class="ef32-name">{{ $row->corporate_name }}</td>
                                    <td class="ef32-code">{{ $row->cnpj_root }}</td>
                                    <td>{{ $number($row->establishments_count) }}</td>
                                    <td><a class="ef32-open" href="{{ route('companies.show', ['company' => $row->company_uuid]) }}" wire:navigate>Abrir dossiê →</a></td>
                                </tr>
                            @empty
                                <tr><td class="ef32-empty" colspan="4">Nenhuma empresa encontrada para os filtros selecionados.</td></tr>
                            @endforelse
                            </tbody>
                        @else
                            <thead><tr><th>Empresa / CNPJ</th><th>Localização</th><th>Situação</th><th>Contato cadastral</th><th>Ação</th></tr></thead>
                            <tbody>
                            @forelse ($records as $row)
                                <tr wire:key="ef32-establishment-{{ $row->id }}">
                                    <td><strong class="ef32-name">{{ $row->corporate_name }}</strong><small class="ef32-secondary">{{ \App\Support\Cnpj::format($row->cnpj) }} · {{ $row->type === 'matrix' ? 'Matriz' : ($row->type === 'branch' ? 'Filial' : 'Tipo não informado') }}</small></td>
                                    <td>{{ $row->municipality_name ?: 'Não informado' }}<small class="ef32-secondary">{{ $row->state ?: 'Sem UF' }}</small></td>
                                    <td><span class="ef32-tag {{ $row->registration_status === 'ATIVA' ? 'is-active' : '' }}">{{ $row->registration_status ?: 'Não informada' }}</span></td>
                                    <td>{{ trim($row->email ?? '') !== '' ? $row->email : 'Sem e-mail' }}<small class="ef32-secondary">{{ trim($row->phone_1 ?? '') !== '' ? $row->phone_1 : (trim($row->phone_2 ?? '') !== '' ? $row->phone_2 : 'Sem telefone') }}</small></td>
                                    <td><a class="ef32-open" href="{{ route('companies.show', ['company' => $row->company_uuid]) }}" wire:navigate>Abrir dossiê →</a></td>
                                </tr>
                            @empty
                                <tr><td class="ef32-empty" colspan="5">Nenhum estabelecimento encontrado para os filtros selecionados.</td></tr>
                            @endforelse
                            </tbody>
                        @endif
                    </table>
                </div>
                <footer class="ef32-pagination">
                    <span>{{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} de {{ $number($records->total()) }} registros · Página {{ $records->currentPage() }} de {{ $records->lastPage() }}</span>
                    <div>
                        <button type="button" wire:click="previousPage('overviewPage')" wire:loading.attr="disabled" @disabled($records->onFirstPage())>← Anterior</button>
                        <button type="button" wire:click="nextPage('overviewPage')" wire:loading.attr="disabled" @disabled(! $records->hasMorePages())>Próxima →</button>
                    </div>
                </footer>
            @else
                <h2 id="ef32-table-title">Empresas</h2>
                <p class="ef32-empty">Clique em “Empresas” para abrir a base local.</p>
            @endif
        </section>

        <details class="ef32-advanced ef33-advanced" data-ef33="cadastral-polished">
            <summary class="ef33-summary">
                <span class="ef33-summary-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7" rx="1.5" />
                        <rect x="14" y="3" width="7" height="7" rx="1.5" />
                        <rect x="3" y="14" width="7" height="7" rx="1.5" />
                        <rect x="14" y="14" width="7" height="7" rx="1.5" />
                    </svg>
                </span>
                <strong>Indicadores cadastrais avançados</strong>
                <span class="ef33-summary-subtitle">Cobertura, concentração por UF e matrizes/filiais</span>
                <span class="ef33-summary-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg>
                </span>
            </summary>
            <div class="ef32-advanced-body">
                    <div class="eo-main-grid">
        <section class="eo-panel" aria-labelledby="eo-coverage-title">
            <div class="eo-panel-head">
                <div><h2 id="eo-coverage-title">Cobertura cadastral</h2><p>O que já está disponível para trabalhar a base.</p></div>
                <span class="eo-chip">{{ $number($s['establishments']) }} registros</span>
            </div>
            <div class="eo-coverage-list">
                @php
                    $coverage = [
                        ['key' => 'active', 'missing' => 'inactive', 'icon' => 'check', 'label' => 'Situação ativa', 'note' => 'Cadastro com situação ATIVA', 'missingLabel' => 'fora de ATIVA'],
                        ['key' => 'email', 'missing' => 'missing_email', 'icon' => 'mail', 'label' => 'E-mail informado', 'note' => 'Contato cadastral preenchido', 'missingLabel' => 'sem e-mail'],
                        ['key' => 'phone', 'missing' => 'missing_phone', 'icon' => 'phone', 'label' => 'Telefone informado', 'note' => 'Primeiro ou segundo telefone', 'missingLabel' => 'sem telefone'],
                    ];
                @endphp
                @foreach ($coverage as $item)
                    @php
                        $pct = $percentage(
                            $s[$item['key']]
                        );
                    @endphp
                    <div class="eo-coverage-row">
                        <span class="eo-icon eo-icon-neutral">@include('livewire.dashboard-overview.icon', ['name' => $item['icon']])</span>
                        <div class="eo-coverage-body">
                            <div class="eo-coverage-title"><strong>{{ $item['label'] }}</strong><span>{{ number_format($pct, 1, ',', '.') }}%</span></div>
                            <div class="eo-meter" role="meter" aria-label="{{ $item['label'] }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}"><span style="width: {{ $pct }}%"></span></div>
                            <div class="eo-coverage-detail">
                                <button type="button" class="eo-text-link eo-link-muted" wire:click="openExplorer('{{ $item['key'] }}')" wire:loading.attr="disabled" @disabled(! $manager)>{{ $number($s[$item['key']]) }} registros</button>
                                <button type="button" class="eo-text-link eo-link-attention" wire:click="openExplorer('{{ $item['missing'] }}')" wire:loading.attr="disabled" @disabled(! $manager)>{{ $number($s['establishments'] - $s[$item['key']]) }} {{ $item['missingLabel'] }} @if ($manager)<span aria-hidden="true">→</span>@endif</button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="eo-panel-note">Campo preenchido não significa contato validado ou decisor identificado.</p>
        </section>

        <section class="eo-panel" aria-labelledby="eo-regions-title">
            <div class="eo-panel-head">
                <div><h2 id="eo-regions-title">Base por UF</h2><p>As cinco maiores concentrações de estabelecimentos.</p></div>
                <span class="eo-head-icon">@include('livewire.dashboard-overview.icon', ['name' => 'pin'])</span>
            </div>
            <div class="ef33-uf-columns" aria-hidden="true">
                <span>UF</span><span>Distribuição</span><span>Registros</span><span>% da base</span><span></span>
            </div>
            <div class="eo-regions">
                @forelse ($s['states'] as $state)
                    @php
                    $pct = $percentage(
                        $state['total']
                    );
                @endphp
                    <button type="button" class="eo-region" wire:click="openExplorer('state', '{{ $state['code'] }}')" wire:loading.attr="disabled" @disabled(! $manager)>
                        <span class="eo-region-code">{{ $state['code'] === '__unknown__' ? 'Sem UF' : $state['code'] }}</span>
                        <span class="eo-region-meter"><span style="width: {{ $pct }}%"></span></span>
                        <strong>{{ $number($state['total']) }}</strong>
                        <span class="eo-region-pct">{{ number_format($pct, 1, ',', '.') }}%</span>
                        @if ($manager)<span class="eo-region-arrow" aria-hidden="true">↗</span>@endif
                    </button>
                @empty
                    <div class="eo-empty">A distribuição aparece após a importação de estabelecimentos.</div>
                @endforelse
            </div>
            <div class="eo-regions-footer"><span>Percentuais sobre toda a base.</span>
                @if ($manager)<button type="button" class="eo-text-link" wire:click="openExplorer('establishments')">Ver todos →</button>@endif
            </div>
        </section>
    </div>

    <section class="eo-panel eo-structure" aria-labelledby="eo-structure-title">
        <div class="eo-structure-heading">
            <span class="ef33-structure-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="9" y="2.5" width="6" height="5" rx="1" />
                    <rect x="2" y="16" width="6" height="5" rx="1" />
                    <rect x="9" y="16" width="6" height="5" rx="1" />
                    <rect x="16" y="16" width="6" height="5" rx="1" />
                    <path d="M12 7.5v4.5 M5 16v-4h14v4 M12 12v4" />
                </svg>
            </span>
            <div><h2 id="eo-structure-title">Estrutura empresarial</h2><p>CNPJs completos, separados por tipo.</p></div>
        </div>
        <div class="eo-structure-content">
            <div class="eo-structure-labels">
                <button type="button" class="eo-structure-stat" wire:click="openExplorer('matrix')" wire:loading.attr="disabled" @disabled(! $manager)><i class="eo-dot-matrix"></i><span>Matrizes</span><strong>{{ $number($s['matrix']) }}</strong><small>{{ number_format($percentage($s['matrix']), 1, ',', '.') }}%</small>@if ($manager)<span class="eo-stat-arrow">↗</span>@endif</button>
                <button type="button" class="eo-structure-stat" wire:click="openExplorer('branch')" wire:loading.attr="disabled" @disabled(! $manager)><i class="eo-dot-branch"></i><span>Filiais</span><strong>{{ $number($s['branch']) }}</strong><small>{{ number_format($percentage($s['branch']), 1, ',', '.') }}%</small>@if ($manager)<span class="eo-stat-arrow">↗</span>@endif</button>
                @if ($s['unknown_type'] > 0)
                    <button type="button" class="eo-structure-stat" wire:click="openExplorer('unknown_type')" @disabled(! $manager)><i class="eo-dot-unknown"></i><span>Não informado</span><strong>{{ $number($s['unknown_type']) }}</strong></button>
                @endif
            </div>
            <div class="eo-structure-meter" aria-hidden="true"><span class="eo-dot-matrix" style="width: {{ $percentage($s['matrix']) }}%"></span><span class="eo-dot-branch" style="width: {{ $percentage($s['branch']) }}%"></span><span class="eo-dot-unknown" style="width: {{ $percentage($s['unknown_type']) }}%"></span></div>
        </div>
    </section>
    <footer class="eo-footnote"><span>Base local · sem consultas externas nesta tela</span><span>Indicadores consultados em {{ $s['consulted_at'] }}</span></footer>
            </div>
        </details>
    @else
        <section class="ef32-denied">
            <h2>Base empresarial</h2>
            <p>A exploração completa da base é restrita aos gestores. Você pode consultar sua carteira em Leads.</p>
            <a href="{{ route('leads.index') }}" wire:navigate>Ver minha carteira →</a>
        </section>
    @endif
</div>
