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
                    'label' => 'Carteira ativa',
                    'value' => $commercial['active_total'],
                    'detail' => 'Leads ativos na operação comercial',
                    'icon' => 'activity',
                    'tone' => 'teal',
                    'href' => route('leads.index'),
                ],
                [
                    'label' => 'Leads sem responsável',
                    'value' => $commercial['unassigned_total'],
                    'detail' => 'Empresas aguardando distribuição',
                    'icon' => 'company',
                    'tone' => 'blue',
                    'href' => route('leads.index', ['owner' => 'unassigned']),
                ],
                [
                    'label' => 'Negócios no HubSpot',
                    'value' => $commercial['opportunities_total'],
                    'detail' => 'Negócios vinculados ao Prospector',
                    'icon' => 'check',
                    'tone' => 'mint',
                    'href' => route('leads.management'),
                ],
            ];

            $dsPipelineBars = [
                [
                    'label' => 'Novos',
                    'value' => $commercial['new_total'] ?? $commercial['new_leads_total'] ?? 0,
                    'tone' => 'teal',
                ],
                [
                    'label' => 'Em contato',
                    'value' => $commercial['contacting_total'] ?? $commercial['in_contact_total'] ?? $commercial['contact_total'] ?? 0,
                    'tone' => 'blue',
                ],
                [
                    'label' => 'Aguardando retorno',
                    'value' => $commercial['waiting_total'] ?? $commercial['waiting_follow_up_total'] ?? 0,
                    'tone' => 'violet',
                ],
                [
                    'label' => 'Oportunidade futura',
                    'value' => $commercial['future_total'] ?? $commercial['future_opportunity_total'] ?? 0,
                    'tone' => 'steel',
                ],
            ];

            // max() precisa de um array ao trabalhar com séries potencialmente vazias.
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
                    'description' => 'Empresas aguardando distribuição',
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
                    class="ds-v5-kpi ds-v5-kpi--{{ $card['tone'] }}"
                >
                    <span class="ds-v5-kpi-icon" aria-hidden="true">
                        @include('livewire.dashboard-overview.icon', ['name' => $card['icon']])
                    </span>
                    <span class="ds-v5-kpi-body">
                        <span class="ds-v5-kpi-title">{{ $card['label'] }}</span>
                        <strong class="ds-v5-kpi-value">{{ $dsNumber($card['value']) }}</strong>
                        <small>{{ $card['detail'] }}</small>
                    </span>
                    <svg class="ds-v5-kpi-ornament" viewBox="0 0 110 45" fill="none" aria-hidden="true">
                        <path d="M2 36C14 34 15 22 28 27S45 12 57 17 76 9 86 12 99 4 108 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M2 36C14 34 15 22 28 27S45 12 57 17 76 9 86 12 99 4 108 5V44H2Z" fill="currentColor" opacity="0.08"/>
                    </svg>
                    <span class="ds-v5-kpi-sub">Indicador atual · sem série histórica</span>
                </a>
            @endforeach
        </section>

        <div class="ds-v5-grid ds-v5-grid--top">
            <section class="ds-v5-panel" aria-labelledby="ds-v5-export-title">
                <header class="ds-v5-panel-head">
                    <div>
                        <h2 id="ds-v5-export-title">Minha carteira comercial</h2>
                        <p>Status comerciais atuais da carteira.</p>
                    </div>
                    <span class="ds-v5-quiet-chip">Carteira atual</span>
                </header>

                <div class="ds-v5-chart" aria-label="Distribuição da carteira comercial">
                    <div class="ds-v5-chart-y" aria-hidden="true">
                        <span>{{ $dsNumber($dsPipelineMax) }}</span>
                        <span>{{ $dsNumber(round($dsPipelineMax * .75)) }}</span>
                        <span>{{ $dsNumber(round($dsPipelineMax * .5)) }}</span>
                        <span>{{ $dsNumber(round($dsPipelineMax * .25)) }}</span>
                        <span>0</span>
                    </div>
                    <div class="ds-v5-chart-plot">
                        @foreach ($dsPipelineBars as $bar)
                            <div class="ds-v5-chart-column">
                                <strong class="ds-v5-chart-value">{{ $dsNumber($bar['value']) }}</strong>
                                <div class="ds-v5-chart-bar-wrap">
                                    <span
                                        class="ds-v5-chart-bar ds-v5-chart-bar--{{ $bar['tone'] }}"
                                        style="height: {{ $bar['value'] > 0 ? max(4, round($bar['value'] / $dsPipelineMax * 100)) : 0 }}%"
                                    ></span>
                                </div>
                                <span class="ds-v5-chart-label">{{ $bar['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <p class="ds-v5-note">Painel comercial da carteira ativa. Se quiser depois, podemos trocar essas colunas por Descarte, Oportunidade, Lead qualificado e Lead frio.</p>
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
                <p class="ds-v5-note">Contagem de empresas distintas com score SDR elegível; sem estimativas financeiras.</p>
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
                <div class="ds-v5-geo">
                    <img
                        src="{{ asset('images/dashboard/brazil-map-v5.webp') }}"
                        alt="Mapa ilustrativo do Brasil com divisas dos estados"
                        class="ds-v5-map"
                        loading="lazy"
                    >
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
                <p class="ds-v5-note">Alertas comerciais do Prospector; não representam um diagnóstico tributário.</p>
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
