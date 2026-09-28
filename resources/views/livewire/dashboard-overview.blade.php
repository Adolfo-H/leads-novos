{{-- Dashboard v3: indicadores e explorador da base. --}}
<div class="ec-overview" x-data
     x-on:overview-explorer-opened.window="$nextTick(() => { const panel = $el.querySelector('[data-overview-explorer]'); if (panel) { panel.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); panel.focus({ preventScroll: true }); } })"
     x-on:overview-explorer-closed.window="$nextTick(() => { const button = $el.querySelector('[data-dataset=&quot;' + $event.detail.dataset + '&quot;]'); if (button) button.focus(); })">
    @php
        $s = $this->summary;
        $manager = $this->canExplore;
        $number = static fn ($value) => number_format((int) $value, 0, ',', '.');
        $percentage = static fn ($value) => $s['establishments'] > 0 ? round($value / $s['establishments'] * 100, 1) : 0;
        $cards = [
            ['key' => 'companies', 'label' => 'Empresas', 'icon' => 'company', 'note' => 'CNPJs raiz cadastrados', 'action' => 'Ver empresas', 'tone' => 'blue'],
            ['key' => 'establishments', 'label' => 'Estabelecimentos', 'icon' => 'establishment', 'note' => 'Matrizes e filiais na base', 'action' => 'Ver estabelecimentos', 'tone' => 'cyan'],
            ['key' => 'active', 'label' => 'Estabelecimentos ativos', 'icon' => 'check', 'note' => number_format($percentage($s['active']), 1, ',', '.').'% dos estabelecimentos', 'action' => 'Ver somente ativos', 'tone' => 'green'],
            ['key' => 'cnaes', 'label' => 'CNAEs vinculados', 'icon' => 'activity', 'note' => 'Atividades presentes na base', 'action' => 'Explorar atividades', 'tone' => 'purple'],
        ];
    @endphp

    <header class="eo-header">
        <div>
            <h1>Visão geral</h1>
            <p>Explore a base empresarial e encontre seu próximo ponto de partida.</p>
        </div>
        <div class="eo-header-actions">
            <button class="eo-button eo-button-quiet" type="button" wire:click="refreshSummary" wire:loading.attr="disabled">
                @include('livewire.dashboard-overview.icon', ['name' => 'refresh'])
                <span wire:loading.remove wire:target="refreshSummary">Atualizar</span>
                <span wire:loading wire:target="refreshSummary">Atualizando…</span>
            </button>
            @if ($manager)
                <a class="eo-button eo-button-primary" href="{{ route('prospecting.index') }}" wire:navigate>
                    @include('livewire.dashboard-overview.icon', ['name' => 'plus'])
                    Nova prospecção
                </a>
            @else
                <a class="eo-button eo-button-primary" href="{{ route('leads.index') }}" wire:navigate>Minha fila de leads</a>
            @endif
        </div>
    </header>

    <div class="eo-loading" role="status" wire:loading.delay>
        <span class="eo-loading-dot"></span> Carregando registros…
    </div>
    <div class="eo-offline" wire:offline>Sem conexão. Os dados exibidos podem estar desatualizados.</div>

    <section class="eo-kpis" aria-label="Indicadores da base">
        @foreach ($cards as $card)
            <button type="button" class="eo-kpi eo-tone-{{ $card['tone'] }}" data-dataset="{{ $card['key'] }}"
                    wire:click="openExplorer('{{ $card['key'] }}')" wire:loading.attr="disabled" @disabled(! $manager)
                    aria-label="{{ $card['action'] }}: {{ $number($s[$card['key']]) }}">
                <span class="eo-kpi-top">
                    <span class="eo-icon">@include('livewire.dashboard-overview.icon', ['name' => $card['icon']])</span>
                    <span class="eo-kpi-label">{{ $card['label'] }}</span>
                </span>
                <strong class="eo-kpi-value">{{ $number($s[$card['key']]) }}</strong>
                <span class="eo-kpi-note">{{ $card['note'] }}</span>
                @if ($manager)
                    <span class="eo-kpi-link">{{ $card['action'] }} @include('livewire.dashboard-overview.icon', ['name' => 'arrow'])</span>
                @endif
            </button>
        @endforeach
    </section>

    @if ($dataset !== '' && $manager)
        @php
            $records = $this->records;
        @endphp
        <section class="eo-panel eo-explorer" data-overview-explorer tabindex="-1" aria-labelledby="eo-explorer-title">
            <div class="eo-panel-head">
                <div>
                    <span class="eo-eyebrow">REGISTROS DA BASE</span>
                    <h2 id="eo-explorer-title">{{ $this->explorerTitle }}</h2>
                    <p>{{ $number($records->total()) }} registros{{ $search !== '' ? ' encontrados nesta pesquisa' : ' neste filtro' }}.</p>
                </div>
                <button type="button" class="eo-button eo-button-quiet" wire:click="closeExplorer">
                    @include('livewire.dashboard-overview.icon', ['name' => 'close']) Fechar
                </button>
            </div>
            <div class="eo-search-wrap">
                <label class="eo-search" for="eo-search">
                    @include('livewire.dashboard-overview.icon', ['name' => 'search'])
                    <span class="eo-sr-only">Pesquisar nos registros</span>
                    <input id="eo-search" type="search" wire:model.live.debounce.350ms="search" maxlength="120"
                           placeholder="{{ $dataset === 'cnaes' ? 'Buscar código ou descrição do CNAE…' : ($dataset === 'companies' ? 'Buscar empresa ou CNPJ…' : 'Buscar empresa, CNPJ ou município…') }}">
                </label>
            </div>
            <div class="eo-table-scroll" wire:loading.class="eo-is-loading" wire:target="search, nextPage, previousPage">
                <table class="eo-table">
                    @if ($dataset === 'cnaes')
                        <thead><tr><th scope="col">CNAE</th><th scope="col">Atividade</th><th scope="col" class="eo-right">Estabelecimentos</th><th scope="col"><span class="eo-sr-only">Ação</span></th></tr></thead>
                        <tbody>
                        @forelse ($records as $row)
                            <tr wire:key="eo-cnae-{{ $row->id }}">
                                <td class="eo-mono">{{ $row->code }}</td>
                                <td class="eo-name">{{ $row->description ?: 'Descrição não informada' }}</td>
                                <td class="eo-right">{{ $number($row->establishments_count) }}</td>
                                <td><button class="eo-text-link" type="button" wire:click="openExplorer('cnae', '{{ $row->code }}')">Ver registros →</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="eo-empty">Nenhum CNAE encontrado neste filtro.</td></tr>
                        @endforelse
                        </tbody>
                    @elseif ($dataset === 'companies')
                        <thead><tr><th scope="col">Empresa</th><th scope="col">CNPJ raiz</th><th scope="col" class="eo-right">Estabelecimentos</th><th scope="col"><span class="eo-sr-only">Ação</span></th></tr></thead>
                        <tbody>
                        @forelse ($records as $row)
                            <tr wire:key="eo-company-{{ $row->id }}">
                                <td class="eo-name">{{ $row->corporate_name }}</td>
                                <td class="eo-mono">{{ $row->cnpj_root }}</td>
                                <td class="eo-right">{{ $number($row->establishments_count) }}</td>
                                <td><a class="eo-text-link" href="{{ route('companies.show', ['company' => $row->company_uuid]) }}" wire:navigate>Abrir dossiê →</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="eo-empty">Nenhuma empresa encontrada neste filtro.</td></tr>
                        @endforelse
                        </tbody>
                    @else
                        <thead><tr><th scope="col">Empresa / CNPJ</th><th scope="col">Localização</th><th scope="col">Situação</th><th scope="col">Contato cadastral</th><th scope="col"><span class="eo-sr-only">Ação</span></th></tr></thead>
                        <tbody>
                        @forelse ($records as $row)
                            <tr wire:key="eo-establishment-{{ $row->id }}">
                                <td><span class="eo-name">{{ $row->corporate_name }}</span><small class="eo-mono">{{ \App\Support\Cnpj::format($row->cnpj) }} · {{ $row->type === 'matrix' ? 'Matriz' : ($row->type === 'branch' ? 'Filial' : 'Tipo não informado') }}</small></td>
                                <td>{{ $row->municipality_name ?: 'Não informada' }}<small>{{ $row->state ?: 'Sem UF' }}</small></td>
                                <td><span class="eo-status {{ $row->registration_status === 'ATIVA' ? 'eo-status-active' : '' }}">{{ $row->registration_status ?: 'Não informada' }}</span></td>
                                <td class="eo-contact">{{ trim($row->email ?? '') !== '' ? $row->email : 'Sem e-mail' }}<small>{{ trim($row->phone_1 ?? '') !== '' ? $row->phone_1 : (trim($row->phone_2 ?? '') !== '' ? $row->phone_2 : 'Sem telefone') }}</small></td>
                                <td><a class="eo-text-link" href="{{ route('companies.show', ['company' => $row->company_uuid]) }}" wire:navigate>Abrir dossiê →</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="eo-empty">Nenhum estabelecimento encontrado neste filtro.</td></tr>
                        @endforelse
                        </tbody>
                    @endif
                </table>
            </div>
            <footer class="eo-pagination">
                <span>{{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} de {{ $number($records->total()) }}</span>
                <div>
                    <button type="button" class="eo-button eo-button-quiet" wire:click="previousPage('overviewPage')" wire:loading.attr="disabled" @disabled($records->onFirstPage())>← Anterior</button>
                    <span>Página {{ $records->currentPage() }} de {{ $records->lastPage() }}</span>
                    <button type="button" class="eo-button eo-button-quiet" wire:click="nextPage('overviewPage')" wire:loading.attr="disabled" @disabled(! $records->hasMorePages())>Próxima →</button>
                </div>
            </footer>
        </section>
    @endif

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
        <div class="eo-structure-heading"><h2 id="eo-structure-title">Estrutura empresarial</h2><p>CNPJs completos, separados por tipo.</p></div>
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
