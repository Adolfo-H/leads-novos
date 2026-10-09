{{--
    EXPORTCONTROL — Dashboard Estratégico V26 — layout fiel à referência, dados reais.
    Dados: serviços reais V23, sem valores fictícios nem tendências inferidas.
    Parent: resources/views/livewire/dashboard-overview.blade.php.
    Este componente não altera o explorer, as permissões ou a sidebar.
--}}
@php
    $ds25Number = static fn ($value): string => number_format((int) $value, 0, ',', '.');
@endphp

<div class="ds25 ds26" aria-label="Visão estratégica da operação comercial">
    <header class="ds25-hero ds26-hero">
        <img class="ds25-globe" src="{{ asset('images/dashboard/globe-hero.webp') }}"
             alt="" aria-hidden="true" loading="eager">
        <div class="ds26-orbit" aria-hidden="true"></div>
        <div class="ds25-hero-shade" aria-hidden="true"></div>
        <div class="ds25-hero-content">
            <h1>Dashboard estratégico</h1>
            <p>Transforme dados em oportunidades reais de exportação.</p>
        </div>
        <div class="ds26-hero-motto" aria-hidden="true">
            <span>MAIS MERCADOS</span>
            <span>MAIS OPORTUNIDADES</span>
            <span>MAIS RESULTADOS</span>
        </div>
    </header>

    @if ($manager && $commercial !== null && $strategic !== null)
        @php
            // A mesma origem de dados utilizada pelos links do Dashboard V23.
            $ds25MetricsService = app(\App\Services\DashboardLeadMetricsV23Service::class);
            $ds25Metrics = $ds25MetricsService->snapshot();
            $ds25Deals = $this->myHubSpotStageSummary;
            $ds25Stages = $ds25Deals['stages'] ?? [];
            $ds25FirstStages = array_slice($ds25Stages, 0, 7);
            $ds25RemainingStages = array_slice($ds25Stages, 7);
            $ds25StageMax = max([1, ...array_map(static fn (array $stage): int => (int) ($stage['value'] ?? 0), $ds25FirstStages)]);
            $ds25CrmWithoutCnpj = \App\Models\HubSpotCompany::query()
                ->whereNull('company_id')
                ->whereHas('deals', fn ($query) => $query->where('is_closed', false))
                ->count();
            $ds25Ids = $ds25MetricsService->leadCompanyIds();
            $ds25MyLeads = \Illuminate\Support\Facades\DB::table('company_lead_work_states')
                ->whereIn('company_id', $ds25Ids)
                ->where('assigned_user_id', auth()->id())
                ->count();

            // Novo = sem HubSpot/atividade, seguindo exatamente o filtro de Leads V15.
            // Usar o serviço evita indicadores diferentes entre Dashboard e Leads.
            $ds25NewQuery = \App\Models\Company::query()
                ->select('companies.*')
                ->leftJoin('company_sdr_scores as sdr', 'sdr.company_id', '=', 'companies.id')
                ->leftJoin('company_hubspot_leads as work', 'work.company_id', '=', 'companies.id')
                ->where(function ($query): void {
                    $query->where('sdr.is_eligible', true)
                        ->orWhereNotNull('work.hubspot_deal_id');
                });
            $ds25NewCount = app(\App\Services\LeadCrmSituationService::class)
                ->apply($ds25NewQuery, 'new')->count('companies.id');

            // Metricas derivadas dos MESMOS IDs que alimentam os atalhos do V23.
            // Sem atribuição local = sem responsável no Prospector.
            $ds26AssignedIds = \Illuminate\Support\Facades\DB::table('company_lead_work_states')
                ->whereNotNull('assigned_user_id')
                ->whereIn('company_id', $ds25Ids)
                ->pluck('company_id')->map(static fn ($id): int => (int) $id)->all();
            $ds26UnassignedDealIds = array_values(array_diff(
                $ds25MetricsService->withDealCompanyIds(),
                $ds26AssignedIds
            ));
            $ds26UnassignedDealCount = count($ds26UnassignedDealIds);
            // Apenas prioridades altas dentro do mesmo recorte de 90 dias do dashboard.
            $ds26HighStaleCount = \Illuminate\Support\Facades\DB::table('company_sdr_scores')
                ->whereIn('company_id', $ds25MetricsService->staleCompanyIds())
                ->where('priority', 'high')
                ->where('is_eligible', true)
                ->count();

            $ds25Cards = [
                [
                    'title' => 'Leads cadastrados', 'value' => $ds25Metrics['leads'],
                    'detail' => 'Empresas disponíveis para atuação comercial', 'icon' => 'activity',
                    'tone' => 'teal', 'url' => route('leads.index', ['dashboardView' => 'all']),
                    'description' => 'Empresas elegíveis ou com negócios identificados no HubSpot.',
                ],
                [
                    'title' => 'Leads sem responsável', 'value' => $ds25Metrics['unassigned'],
                    'detail' => 'Distribua e aumente a produtividade do time', 'icon' => 'company',
                    'tone' => 'blue', 'url' => route('leads.index', ['dashboardView' => 'all', 'owner' => 'unassigned']),
                    'description' => 'Leads da base sem responsável atribuído no Prospector.',
                ],
                [
                    'title' => 'Leads com negócio HubSpot', 'value' => $ds25Metrics['with_deal'],
                    'detail' => 'Empresas com negócio identificado no CRM', 'icon' => 'check',
                    'tone' => 'mint', 'url' => route('leads.index', ['dashboardView' => 'with_deal']),
                    'description' => 'Empresas vinculadas a pelo menos um negócio, não quantidade de negócios.',
                ],
            ];

            // Ações rápidas: números do Prospector, cada uma com destino filtrado.
            $ds25Actions = [
                [
                    'title' => 'Leads sem responsável', 'detail' => 'Distribua os leads entre os membros da equipe',
                    'value' => $ds25Metrics['unassigned'], 'tone' => 'danger', 'icon' => 'company',
                    'url' => route('leads.index', ['dashboardView' => 'all', 'owner' => 'unassigned']),
                ],
                [
                    'title' => 'Contatos parados há 90 dias', 'detail' => 'Reative oportunidades sem interação recente',
                    'value' => $ds25Metrics['stale90'], 'tone' => 'blue', 'icon' => 'refresh',
                    'url' => route('leads.index', ['dashboardView' => 'stale90']),
                ],
                [
                    'title' => 'Novos para prospectar', 'detail' => 'Empresas ainda sem presença comercial no HubSpot',
                    'value' => $ds25NewCount, 'tone' => 'teal', 'icon' => 'plus',
                    'url' => route('leads.index', ['crmSituation' => 'new']),
                ],
                [
                    'title' => 'CRM sem CNPJ', 'detail' => 'Identificação fiscal pendente',
                    'value' => $ds25CrmWithoutCnpj, 'tone' => 'violet', 'icon' => 'activity',
                    'url' => route('leads.index', ['hubSpotOnly' => 1]),
                ],
                [
                    'title' => 'Aguardando retorno', 'detail' => 'Leads que precisam de acompanhamento',
                    'value' => $commercial['waiting_total'], 'tone' => 'amber', 'icon' => 'activity',
                    'url' => route('leads.index', ['workStatus' => 'waiting']),
                ],
            ];

            // Prioridades comerciais: evitar períodos fictícios e números de mockup.
            $ds25Priorities = [
                [
                    'title' => 'Follow-ups atrasados', 'detail' => 'Tarefas abertas cujo vencimento já passou',
                    'value' => $ds25Metrics['overdue'], 'tone' => 'danger', 'symbol' => '!',
                    'url' => route('leads.index', ['dashboardView' => 'overdue']),
                ],
                [
                    'title' => 'Leads aguardando contato', 'detail' => 'Empresas ainda não abordadas no CRM',
                    'value' => $ds25NewCount, 'tone' => 'amber', 'symbol' => '+',
                    'url' => route('leads.index', ['crmSituation' => 'new']),
                ],
                [
                    'title' => 'Negócios sem responsável local', 'detail' => 'Empresas com negócio e sem dono no Prospector',
                    'value' => $ds26UnassignedDealCount, 'tone' => 'blue', 'symbol' => '♙',
                    'url' => route('leads.index', ['dashboardView' => 'with_deal', 'owner' => 'unassigned']),
                ],
                [
                    'title' => 'Alta prioridade sem atividade recente', 'detail' => 'Leads de prioridade alta parados há 90 dias',
                    'value' => $ds26HighStaleCount, 'tone' => 'teal', 'symbol' => '★',
                    'url' => route('leads.index', ['dashboardView' => 'stale90', 'priority' => 'high']),
                ],
            ];

            $ds25StateNames = [
                'AC' => 'Acre', 'AL' => 'Alagoas', 'AM' => 'Amazonas',
                'AP' => 'Amapá', 'BA' => 'Bahia', 'CE' => 'Ceará',
                'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
                'GO' => 'Goiás', 'MA' => 'Maranhão', 'MG' => 'Minas Gerais',
                'MS' => 'Mato Grosso do Sul', 'MT' => 'Mato Grosso',
                'PA' => 'Pará', 'PB' => 'Paraíba', 'PE' => 'Pernambuco',
                'PI' => 'Piauí', 'PR' => 'Paraná', 'RJ' => 'Rio de Janeiro',
                'RN' => 'Rio Grande do Norte', 'RO' => 'Rondônia',
                'RR' => 'Roraima', 'RS' => 'Rio Grande do Sul',
                'SC' => 'Santa Catarina', 'SE' => 'Sergipe',
                'SP' => 'São Paulo', 'TO' => 'Tocantins',
            ];
            $ds25StatesMap = $this->stateMapCounts;
            $ds25TotalEstablishments = max(1, (int) $s['establishments']);
            $dsMapTooltips = [];
            foreach ($ds25StateNames as $ds25Code => $ds25Name) {
                $ds25Count = (int) ($ds25StatesMap[$ds25Code] ?? 0);
                $dsMapTooltips[$ds25Code] = [
                    'name' => $ds25Name,
                    'total' => $ds25Number($ds25Count),
                    'unit' => $ds25Count === 1 ? 'estabelecimento' : 'estabelecimentos',
                    'percentage' => number_format($ds25Count / $ds25TotalEstablishments * 100, 1, ',', '.').'%',
                ];
            }
            $ds25StateMax = max([1, ...array_map(static fn (array $row): int => (int) $row['total'], $s['states'])]);
        @endphp

        <section class="ds25-kpis" aria-label="Indicadores da operação comercial">
            @foreach ($ds25Cards as $card)
                <a class="ds25-kpi ds25-kpi--{{ $card['tone'] }}"
                   href="{{ $card['url'] }}" wire:navigate title="{{ $card['description'] }}">
                    <span class="ds25-kpi-icon" aria-hidden="true">
                        @include('livewire.dashboard-overview.icon', ['name' => $card['icon']])
                    </span>
                    <span class="ds25-kpi-info">
                        <span class="ds25-kpi-title">{{ $card['title'] }}</span>
                        <strong class="ds25-kpi-number">{{ $ds25Number($card['value']) }}</strong>
                        <span class="ds25-kpi-detail">{{ $card['detail'] }}</span>
                    </span>
                    <span class="ds25-kpi-arrow" aria-hidden="true">›</span>
                </a>
            @endforeach
        </section>

        <div class="ds25-layout ds25-layout-main">
            <section class="ds25-panel ds25-deals" aria-labelledby="ds25-deals-title"
                     x-data="{showOtherStages: false}">
                <div class="ds25-panel-heading">
                    <span class="ds25-panel-symbol ds25-symbol-teal" aria-hidden="true">
                        @include('livewire.dashboard-overview.icon', ['name' => 'activity'])
                    </span>
                    <div class="ds25-panel-heading-copy">
                        <h2 id="ds25-deals-title">Meus negócios por etapa</h2>
                        <p>Acompanhe os seus negócios em todas as etapas do HubSpot.</p>
                    </div>
                    <span class="ds25-counter" title="Todas as etapas e datas disponíveis">{{ $ds25Number($ds25Deals['total'] ?? 0) }} negócios · todas as datas</span>
                </div>

                @if ($ds25FirstStages !== [])
                    <div class="ds25-stages" aria-label="Etapas dos meus negócios no HubSpot">
                        @foreach ($ds25FirstStages as $stage)
                            @php
                                $ds25Value = (int) ($stage['value'] ?? 0);
                                $ds25Ratio = $ds25StageMax > 0 ? $ds25Value / $ds25StageMax * 100 : 0;
                                $ds25Color = ['teal', 'blue', 'violet', 'steel', 'mint', 'azure', 'plum'][$loop->index % 7];
                            @endphp
                            <a href="{{ route('leads.index', ['dashboardStage' => $stage['label']]) }}" wire:navigate
                               class="ds25-stage ds25-stage--{{ $ds25Color }}"
                               aria-label="Ver negócios na etapa {{ $stage['label'] }}: {{ $ds25Number($ds25Value) }}"
                               title="{{ $stage['label'] }} · {{ $ds25Number($ds25Value) }} negócios">
                                <strong class="ds25-stage-quantity">{{ $ds25Number($ds25Value) }}</strong>
                                <span class="ds25-stage-column"><span style="height: {{ max(5, round($ds25Ratio)) }}%"></span></span>
                                <span class="ds25-stage-label">{{ $stage['label'] }}</span>
                            </a>
                        @endforeach
                    </div>

                    @if ($ds25RemainingStages !== [])
                        <div class="ds25-extra-stages">
                            <div class="ds25-extra-stages-head">
                                <div class="ds25-extra-stages-info">
                                    <span class="ds25-extra-stages-dot" aria-hidden="true"></span>
                                    <span>Outras etapas do funil</span>
                                    <span class="ds25-extra-stages-total">{{ count($ds25RemainingStages) }}</span>
                                </div>
                                <button
                                    type="button"
                                    class="ds25-stage-expand"
                                    x-on:click="showOtherStages = !showOtherStages"
                                    x-bind:aria-expanded="showOtherStages ? 'true' : 'false'"
                                    aria-controls="ds25-other-stages"
                                >
                                    <span x-text="showOtherStages ? 'Recolher etapas' : 'Mostrar {{ count($ds25RemainingStages) }} etapas'"></span>
                                    <svg
                                        class="ds25-stage-chevron"
                                        x-bind:class="{ 'is-open': showOtherStages }"
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="15"
                                        height="15"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </div>

                            <div
                                id="ds25-other-stages"
                                class="ds25-other-stages"
                                x-show="showOtherStages"
                                x-transition.opacity.duration.150ms
                                x-cloak
                            >
                                @foreach ($ds25RemainingStages as $stage)
                                    <a
                                        href="{{ route('leads.index', ['dashboardStage' => $stage['label']]) }}"
                                        wire:navigate
                                        class="ds25-other-stage-item"
                                        aria-label="Ver negócios na etapa {{ $stage['label'] }}: {{ $ds25Number($stage['value']) }}"
                                    >
                                        <span class="ds25-other-stage-dot" aria-hidden="true"></span>
                                        <span class="ds25-other-stage-name">{{ $stage['label'] }}</span>
                                        <strong class="ds25-other-stage-count">{{ $ds25Number($stage['value']) }}</strong>
                                        <span class="ds25-other-stage-arrow" aria-hidden="true">↗</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <div class="ds25-empty">Nenhum negócio foi identificado para seu responsável do HubSpot. Confira a atribuição no CRM.</div>
                @endif
            </section>

            <section class="ds25-panel ds25-actions" aria-labelledby="ds25-actions-title">
                <div class="ds25-panel-heading">
                    <span class="ds25-panel-symbol ds25-symbol-blue" aria-hidden="true">
                        @include('livewire.dashboard-overview.icon', ['name' => 'plus'])
                    </span>
                    <div class="ds25-panel-heading-copy">
                        <h2 id="ds25-actions-title">Ações rápidas</h2>
                        <p>Atalhos para o que mais importa na rotina comercial.</p>
                    </div>
                </div>

                <div class="ds25-actions-list">
                    @foreach ($ds25Actions as $action)
                        <a href="{{ $action['url'] }}" wire:navigate class="ds25-action ds25-action--{{ $action['tone'] }}">
                            <span class="ds25-action-icon" aria-hidden="true">
                                @include('livewire.dashboard-overview.icon', ['name' => $action['icon']])
                            </span>
                            <span class="ds25-action-copy">
                                <strong>{{ $action['title'] }}</strong>
                                <small>{{ $action['detail'] }}</small>
                            </span>
                            <strong class="ds25-action-number">{{ $ds25Number($action['value']) }}</strong>
                            <span class="ds25-action-arrow" aria-hidden="true">›</span>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="ds25-layout ds25-layout-bottom">
            <section class="ds25-panel ds25-map-panel" aria-labelledby="ds25-map-title">
                <div class="ds25-panel-heading">
                    <span class="ds25-panel-symbol ds25-symbol-blue" aria-hidden="true">
                        @include('livewire.dashboard-overview.icon', ['name' => 'pin'])
                    </span>
                    <div class="ds25-panel-heading-copy">
                        <h2 id="ds25-map-title">Concentração por estado</h2>
                        <p>Distribuição dos estabelecimentos cadastrados na base.</p>
                    </div>
                    <button type="button" class="ds25-panel-link" x-on:click="dashboardTab = 'base'">Ver base <span aria-hidden="true">→</span></button>
                </div>
                <div class="ds25-map-content">
                    @include('livewire.dashboard-overview.map-interactive-v7', ['dsMapTooltips' => $dsMapTooltips])
                    <div class="ds25-states">
                        @forelse ($s['states'] as $state)
                            @php
                                $ds25StatePercent = $s['establishments'] > 0
                                    ? ($state['total'] / $s['establishments'] * 100) : 0;
                                $ds25StateWidth = round($state['total'] / $ds25StateMax * 100);
                            @endphp
                            <button type="button" class="ds25-state" wire:click="openExplorer('state', '{{ $state['code'] }}')"
                                    wire:loading.attr="disabled" title="{{ $state['code'] }}: {{ $ds25Number($state['total']) }} estabelecimentos ({{ number_format($ds25StatePercent, 1, ',', '.') }}%)">
                                <span class="ds26-state-rank" aria-hidden="true">{{ $loop->iteration }}</span>
                                <span class="ds25-state-uf">{{ $state['code'] }}</span>
                                <strong>{{ $ds25Number($state['total']) }}</strong>
                                <span class="ds25-state-pct">{{ number_format($ds25StatePercent, 1, ',', '.') }}%</span>
                                <span class="ds25-state-track"><i style="width: {{ $ds25StateWidth }}%"></i></span>
                            </button>
                        @empty
                            <p class="ds25-empty">Ainda não há estabelecimentos com UF cadastrada.</p>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="ds25-panel ds25-priorities" aria-labelledby="ds25-priorities-title">
                <div class="ds25-panel-heading">
                    <span class="ds25-panel-symbol ds25-symbol-amber" aria-hidden="true">!</span>
                    <div class="ds25-panel-heading-copy">
                        <h2 id="ds25-priorities-title">Prioridades comerciais</h2>
                        <p>Foque no que pode gerar mais resultados nos próximos dias.</p>
                    </div>
                    <a href="{{ route('leads.index', ['dashboardView' => 'all']) }}" wire:navigate class="ds25-panel-link">Ver todos <span aria-hidden="true">→</span></a>
                </div>
                <div class="ds25-priorities-list">
                    @foreach ($ds25Priorities as $item)
                        <a href="{{ $item['url'] }}" wire:navigate class="ds25-priority ds25-priority--{{ $item['tone'] }}">
                            <span class="ds25-priority-icon" aria-hidden="true">{{ $item['symbol'] }}</span>
                            <span class="ds25-priority-copy">
                                <strong>{{ $item['title'] }}</strong>
                                <small>{{ $item['detail'] }}</small>
                            </span>
                            <strong class="ds25-priority-number">{{ $ds25Number($item['value']) }}</strong>
                            <span class="ds25-priority-arrow" aria-hidden="true">›</span>
                        </a>
                    @endforeach
                </div>

            </section>
        </div>

        <footer class="ds25-footer">
            <span>Indicadores consultados em {{ $s['consulted_at'] }}</span>
            <span class="ds25-footer-dot" aria-hidden="true"></span>
            <span>Dados locais</span>
            <button type="button" class="ds26-footer-refresh" wire:click="refreshSummary" wire:loading.attr="disabled" title="Atualizar indicadores" aria-label="Atualizar indicadores">↻</button>
        </footer>
    @else
        <section class="ds25-seller">
            <div>
                <h2>Minha fila de leads</h2>
                <p>Abra os leads atribuídos a você para acompanhar os próximos contatos.</p>
            </div>
            <a href="{{ route('leads.index', ['owner' => 'mine']) }}" wire:navigate>Ver minha carteira →</a>
        </section>
    @endif
</div>
