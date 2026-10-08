{{--
    ExportControl Prospector / Dashboard Estratégico V5
    Dados reais do sistema; sem cifras financeiras ou tendências inventadas.
    A seção antiga da base empresarial permanece no Blade pai.
--}}
@php
    $dsNumber = static fn ($value): string => number_format((int) $value, 0, ',', '.');
@endphp

<div class="ds-v5" aria-label="Visão geral comercial">
    <header class="ds-v5-hero">
        <img
            class="ds-v5-globe"
            src="{{ asset('images/dashboard/globe-hero.webp') }}"
            alt=""
            aria-hidden="true"
            loading="eager"
        >

        <div class="ds-v5-hero-copy">
            <h1>Dashboard estratégico</h1>
            <p>Visão geral de inteligência comercial e exportação para gerar novas oportunidades.</p>
        </div>
    </header>

    @if ($manager && $commercial !== null && $strategic !== null)
        @php
            $dsCards = [
                [
                    'label' => 'Leads em operação',
                    'value' => $commercial['active_total'],
                    'detail' => '',
                    'icon' => 'activity',
                    'tone' => 'teal',
                    'href' => route('leads.index', ['dashboardView' => 'active']),
                    'hint' => 'Empresas elegíveis pelo score SDR ou com negócio HubSpot vinculado, com acompanhamento ativo. Indicador de toda a equipe.',
                ],
                [
                    'label' => 'Leads sem responsável',
                    'value' => $commercial['unassigned_total'],
                    'detail' => '',
                    'icon' => 'company',
                    'tone' => 'blue',
                    'href' => route('leads.index', ['owner' => 'unassigned', 'dashboardView' => 'active']),
                    'hint' => 'Leads ativos ainda sem vendedor atribuído no Prospector.',
                ],
                [
                    'label' => 'Leads com negócio HubSpot',
                    'value' => $commercial['opportunities_total'],
                    'detail' => '',
                    'icon' => 'check',
                    'tone' => 'mint',
                    'href' => route('leads.index', ['dashboardView' => 'with_deal']),
                    'hint' => 'Empresas ativas com ID de negócio HubSpot vinculado. Não é a quantidade de negócios distintos.',
                ],
            ];

            $dsPipeline = $this->myHubSpotStageSummary;
            $dsPipelineBars = $dsPipeline['stages'] ?? [];
            $dsPipelineMax = max([1, ...array_column($dsPipelineBars, 'value')]);
            $dsUfMax = max([1, ...array_column($s['states'], 'total')]);

            $dsAlerts = [
                [
                    'title' => 'Follow-ups atrasados',
                    'description' => 'Retornos comerciais fora do prazo',
                    'value' => $commercial['overdue_total'],
                    'tone' => 'danger',
                    'symbol' => '!',
                    'url' => route('leads.index', ['workStatus' => 'waiting', 'followUp' => 'overdue']),
                ],
                [
                    'title' => 'Leads sem responsável',
                    'description' => '',
                    'value' => $commercial['unassigned_total'],
                    'tone' => 'warning',
                    'symbol' => '△',
                    'url' => route('leads.index', ['owner' => 'unassigned']),
                ],
                [
                    'title' => 'Contatos parados',
                    'description' => 'Leads sem interação no prazo operacional',
                    'value' => $commercial['stale_total'],
                    'tone' => 'blue',
                    'symbol' => '↗',
                    'url' => route('leads.management'),
                ],
            ];
        @endphp

        <section class="ds-v5-kpis" aria-label="Indicadores estratégicos">
            @foreach ($dsCards as $card)
                <a
                    href="{{ $card['href'] }}"
                    wire:navigate
                    title="{{ $card['hint'] }}"
                    class="ds-v5-kpi ds-v5-kpi--{{ $card['tone'] }}"
                >
                    <span class="ds-v5-kpi-icon" aria-hidden="true">
                        @include('livewire.dashboard-overview.icon', ['name' => $card['icon']])
                    </span>
                    <span class="ds-v5-kpi-body">
                        <span class="ds-v5-kpi-title">{{ $card['label'] }}</span>
                        <strong class="ds-v5-kpi-value">{{ $dsNumber($card['value']) }}</strong>

                    </span>
                </a>
            @endforeach
        </section>

        <div class="ds-v5-grid ds-v5-grid--top">
            <section class="ds-v5-panel" aria-labelledby="ds-v6-deals-title">
                <header class="ds-v5-panel-head">
                    <div>
                        <h2 id="ds-v6-deals-title">Meus negócios por etapa</h2>
                        <p>Etapas do HubSpot ligadas às empresas da minha carteira.</p>
                    </div>
                    <span class="ds-v5-quiet-chip">
                        {{ $dsNumber($dsPipeline['total'] ?? 0) }} negócios
                    </span>
                </header>

                @if ($dsPipelineBars !== [])
                    <div class="ds-v5-chart ds-v6-chart" aria-label="Distribuição real dos meus negócios por etapa do HubSpot">
                        <div class="ds-v5-chart-y" aria-hidden="true">
                            <span>{{ $dsNumber($dsPipelineMax) }}</span>
                            <span>{{ $dsNumber(round($dsPipelineMax * .75)) }}</span>
                            <span>{{ $dsNumber(round($dsPipelineMax * .5)) }}</span>
                            <span>{{ $dsNumber(round($dsPipelineMax * .25)) }}</span>
                            <span>0</span>
                        </div>
                        <div class="ds-v5-chart-plot ds-v6-chart-plot"
                             style="--ds-stage-columns: {{ count($dsPipelineBars) }}">
                            @foreach ($dsPipelineBars as $bar)
                                <a href="{{ route('leads.index', ['owner' => 'mine', 'dashboardStage' => $bar['label'] === 'Outras etapas' ? '__others__' : $bar['label']]) }}"
                                   wire:navigate
                                   class="ds-v5-chart-column ds-v10-chart-link"
                                   aria-label="Ver empresas dos meus negócios na etapa {{ $bar['label'] }}"
                                   title="Abrir empresas da etapa {{ $bar['label'] }} ({{ $dsNumber($bar['value']) }} negócios)">
                                    <strong class="ds-v5-chart-value">{{ $dsNumber($bar['value']) }}</strong>
                                    <div class="ds-v5-chart-bar-wrap">
                                        <span class="ds-v5-chart-bar ds-v5-chart-bar--{{ $bar['tone'] }}"
                                              style="height: {{ max(4, round($bar['value'] / $dsPipelineMax * 100)) }}%"></span>
                                    </div>
                                    <span class="ds-v5-chart-label">{{ $bar['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="ds-v6-empty">
                        Nenhum negócio do HubSpot identificado nas empresas atribuídas à sua carteira.
                        Confira os vínculos e os responsáveis dos leads.
                    </div>
                @endif
            </section>

            <section class="ds-v5-panel" aria-labelledby="ds-v5-top-title">
                <header class="ds-v5-panel-head">
                    <div>
                        <h2 id="ds-v5-top-title">Top oportunidades por CNAE</h2>
                        <p>Atividades com mais empresas elegíveis para prospecção.</p>
                    </div>
                    <a class="ds-v5-panel-button" href="{{ route('leads.index') }}" wire:navigate>Ver leads <span aria-hidden="true">→</span></a>
                </header>
                <div class="ds-v5-ranking">
                    <div class="ds-v5-ranking-head" aria-hidden="true">
                        <span>#</span><span>CNAE / Atividade</span><span>Empresas</span>
                    </div>
                    @forelse ($strategic['cnaes'] as $leader)
                        <div class="ds-v5-rank-row">
                            <span class="ds-v5-rank-num ds-v5-rank-num--{{ min($loop->iteration, 5) }}">{{ $loop->iteration }}</span>
                            <span class="ds-v5-rank-name" title="{{ $leader['description'] }}">
                                <strong>{{ \Illuminate\Support\Str::limit($leader['description'], 47) }}</strong>
                                <small>CNAE {{ $leader['code'] }}</small>
                            </span>
                            <strong class="ds-v5-rank-count">{{ $dsNumber($leader['companies']) }}</strong>
                        </div>
                    @empty
                        <p class="ds-v5-empty">Nenhuma atividade com leads elegíveis encontrada. O ranking aparecerá após a qualificação.</p>
                    @endforelse
                </div>
                <p class="ds-v5-note"></p>
            </section>
        </div>

        <div class="ds-v5-grid ds-v5-grid--bottom">
            <section class="ds-v5-panel" aria-labelledby="ds-v5-uf-title">
                <header class="ds-v5-panel-head">
                    <div>
                        <h2 id="ds-v5-uf-title">Concentração por estado</h2>
                        <p>Distribuição dos estabelecimentos cadastrados.</p>
                    </div>
                    <button type="button" class="ds-v5-panel-button" x-on:click="dashboardTab = 'base'">Ver base <span aria-hidden="true">→</span></button>
                </header>
                @php
                    // Mapeia TODOS os estados; o ranking ao lado continua mostrando o Top 5.
                    $dsUfNames = [
                        'AC' => 'Acre',
                        'AL' => 'Alagoas',
                        'AM' => 'Amazonas',
                        'AP' => 'Amapá',
                        'BA' => 'Bahia',
                        'CE' => 'Ceará',
                        'DF' => 'Distrito Federal',
                        'ES' => 'Espírito Santo',
                        'GO' => 'Goiás',
                        'MA' => 'Maranhão',
                        'MG' => 'Minas Gerais',
                        'MS' => 'Mato Grosso do Sul',
                        'MT' => 'Mato Grosso',
                        'PA' => 'Pará',
                        'PB' => 'Paraíba',
                        'PE' => 'Pernambuco',
                        'PI' => 'Piauí',
                        'PR' => 'Paraná',
                        'RJ' => 'Rio de Janeiro',
                        'RN' => 'Rio Grande do Norte',
                        'RO' => 'Rondônia',
                        'RR' => 'Roraima',
                        'RS' => 'Rio Grande do Sul',
                        'SC' => 'Santa Catarina',
                        'SE' => 'Sergipe',
                        'SP' => 'São Paulo',
                        'TO' => 'Tocantins',
                    ];
                    $dsRawStates = $this->stateMapCounts;
                    $dsTotalEstablishments = max(1, (int) $s['establishments']);
                    $dsMapTooltips = [];
                    foreach ($dsUfNames as $dsUfCode => $dsUfName) {
                        $dsCount = (int) ($dsRawStates[$dsUfCode] ?? 0);
                        $dsMapTooltips[$dsUfCode] = [
                            'name' => $dsUfName,
                            'total' => $dsNumber($dsCount),
                            'unit' => $dsCount === 1 ? 'estabelecimento' : 'estabelecimentos',
                            'percentage' => number_format($dsCount * 100 / $dsTotalEstablishments, 1, ',', '.').'%',
                        ];
                    }
                @endphp
                <div class="ds-v5-geo">
                    @include('livewire.dashboard-overview.map-interactive-v7', ['dsMapTooltips' => $dsMapTooltips])
                    <div class="ds-v5-states">
                        @forelse ($s['states'] as $state)
                            @php
                                $dsPct = $s['establishments'] > 0
                                    ? round($state['total'] / $s['establishments'] * 100, 1)
                                    : 0;
                                $dsRelative = round($state['total'] / $dsUfMax * 100);
                            @endphp
                            <button
                                type="button"
                                class="ds-v5-state"
                                wire:click="openExplorer('state', '{{ $state['code'] }}')"
                                wire:loading.attr="disabled"
                                @disabled(! $manager)
                            >
                                <span class="ds-v5-state-idx">{{ $loop->iteration }}</span>
                                <span class="ds-v5-state-code">{{ $state['code'] === '__unknown__' ? '—' : $state['code'] }}</span>
                                <strong>{{ $dsNumber($state['total']) }}</strong>
                                <span class="ds-v5-state-percentage">{{ number_format($dsPct, 1, ',', '.') }}%</span>
                                <span class="ds-v5-state-track" aria-hidden="true"><i style="width: {{ $dsRelative }}%"></i></span>
                            </button>
                        @empty
                            <p class="ds-v5-empty">Importe estabelecimentos para visualizar a distribuição.</p>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="ds-v5-panel" aria-label="Atenção operacional" aria-labelledby="ds-v5-risks-title">
                <header class="ds-v5-panel-head">
                    <div>
                        <h2 id="ds-v5-risks-title">Riscos e oportunidades</h2>
                        <p>Pendências comerciais reais que precisam de acompanhamento.</p>
                    </div>
                    <a class="ds-v5-panel-button" href="{{ route('leads.management') }}" wire:navigate>Ver todos <span aria-hidden="true">→</span></a>
                </header>
                <div class="ds-v5-alert-list">
                    @foreach ($dsAlerts as $alert)
                        <a href="{{ $alert['url'] }}" wire:navigate class="ds-v5-alert ds-v5-alert--{{ $alert['tone'] }}">
                            <span class="ds-v5-alert-icon" aria-hidden="true">{{ $alert['symbol'] }}</span>
                            <span class="ds-v5-alert-label">{{ $alert['title'] }}</span>
                            <span class="ds-v5-alert-description">{{ $alert['description'] }}</span>
                            <strong class="ds-v5-alert-qty">{{ $dsNumber($alert['value']) }}</strong>
                            <span class="ds-v5-alert-arrow" aria-hidden="true">›</span>
                        </a>
                    @endforeach
                </div>
                <p class="ds-v5-note"></p>
            </section>
        </div>

        <footer class="ds-v5-footer ds-v5-footer--minimal">
            <span>
                Base consultada em {{ $s['consulted_at'] }}
                <span class="ds-v5-live-dot" aria-hidden="true"></span>
                Dados locais
                <button type="button" wire:click="refreshSummary" wire:loading.attr="disabled" title="Atualizar indicadores" aria-label="Atualizar indicadores">↻</button>
            </span>
        </footer>
    @else
        <section class="ds-v5-seller">
            <div>
                <h2>Sua carteira comercial</h2>
                <p>Os dados gerenciais são restritos aos gestores. Acesse seus leads e acompanhe os retornos.</p>
            </div>
            <a href="{{ route('leads.index') }}" wire:navigate>Minha fila de leads →</a>
        </section>
    @endif
</div>
