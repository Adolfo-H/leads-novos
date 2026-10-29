{{-- Dashboard Figma D01 + D01-V: nova ESTRUTURA; sem banner e sem gráficos verticais. --}}
@php
    $ds25Number = static fn ($value): string => number_format((int) $value, 0, ',', '.');
@endphp
<div class="ef29-dashboard" id="ecf29-dashboard" data-dashboard-figma="D01">
@if ($manager && $commercial !== null && $strategic !== null)
        @php
            // A mesma origem de dados utilizada pelos links do Dashboard V23.
            $ds25MetricsService = app(\App\Services\DashboardLeadMetricsV23Service::class);
            $ds25Bundle = $ds25MetricsService->snapshotWithCompanyIds();
            $ds25Metrics = $ds25Bundle['summary'];
            $ds25Deals = $this->myHubSpotStageSummary;
            // EC_PROSPECTOR_V40: contagem individual de TAREFAS vencidas ou para hoje.
            $ds25MyDueTasks = app(\App\Services\DashboardHubSpotDueTasksV40Service::class)
                ->forUser((int) auth()->id());
            $ds25Stages = $ds25Deals['stages'] ?? [];
            $ds25FirstStages = $ds25Stages;
            $ds25RemainingStages = [];
            $ds25StageMax = max([1, ...array_map(static fn (array $stage): int => (int) ($stage['value'] ?? 0), $ds25FirstStages)]);
            $ds25CrmWithoutCnpj = \App\Models\HubSpotCompany::query()
                ->whereNull('company_id')
                ->whereHas('deals', fn ($query) => $query->where('is_closed', false))
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
            // A atribuição já veio do snapshot: não consultar a mesma carteira duas vezes.
            $ds26UnassignedDealIds = array_values(array_diff(
                $ds25Bundle['with_deal_ids'],
                $ds25Bundle['assigned_ids']
            ));
            $ds26UnassignedDealCount = count($ds26UnassignedDealIds);
            // Apenas prioridades altas dentro do mesmo recorte de 90 dias do dashboard.
            $ds26HighStaleCount = \Illuminate\Support\Facades\DB::table('company_sdr_scores')
                ->whereIn('company_id', $ds25Bundle['stale_ids'])
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
                    'title' => 'Aguardando retorno', 'detail' => 'Tarefas abertas vencidas ou com vencimento hoje',
                    'value' => $ds25MyDueTasks['total'], 'tone' => 'amber', 'icon' => 'activity',
                    'url' => route('leads.index', ['dueActionOnly' => 1]),
                ],
            ];

            // Prioridades comerciais: evitar períodos fictícios e números de mockup.
            $ds25Priorities = [
                [
                    'title' => 'Minhas tarefas atrasadas', 'detail' => 'Tarefas abertas do HubSpot cujo horário já passou',
                    'value' => $ds25MyDueTasks['overdue'], 'tone' => 'danger', 'symbol' => '!',
                    'url' => route('leads.index', ['dueActionOnly' => 1, 'queueDuePeriod' => 'overdue']),
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
        @php
            // Figma D01: ordem e desenho reais, sem dados demonstrativos.
            $ef29Stages = array_slice($ds25Stages, 0, 5); // EC_PROSPECTOR_V41: cinco maiores.
            $ef29ExtraStages = array_slice($ds25Stages, 5); // Expande abaixo do grafico, sem scrollbar.
            $ef29StageMax = max([1, ...array_map(static fn (array $row): int => (int) ($row['value'] ?? 0), $ef29Stages)]);
            $ef29TopStates = array_slice($s['states'], 0, 4);
            $ef29TopCodes = array_values(array_filter(
                array_map(static fn (array $row): string => (string) $row['code'], $ef29TopStates),
                static fn (string $code): bool => preg_match('/^[A-Z]{2}$/D', $code) === 1
            ));
            $ef29CompanyByState = $ef29TopCodes !== []
                ? \Illuminate\Support\Facades\DB::table('establishments')
                    ->select('state')
                    ->selectRaw('COUNT(DISTINCT company_id) as companies_total')
                    ->whereIn('state', $ef29TopCodes)
                    ->groupBy('state')
                    ->pluck('companies_total', 'state')
                    ->all()
                : [];
        @endphp

        <header class="ef29-intro">
            <div class="ef29-intro-meta">
                <span>DASHBOARD</span>
                <span class="ef29-tag">Gestor · dados em {{ $s['consulted_at'] }}</span>
            </div>
            <h1>Sua operação, em perspectiva</h1>
            <p>Prioridades comerciais e leitura da carteira local.</p>
            <div class="ef29-intro-actions">
                <button type="button" class="ef29-button ef29-button--light"
                        x-on:click="dashboardTab = 'base'" wire:click="openBase">Explorar base</button>
                <button type="button" class="ef29-button ef29-button--main"
                        wire:click="refreshSummary" wire:loading.attr="disabled"
                        wire:target="refreshSummary">
                    <span wire:loading.remove wire:target="refreshSummary">Atualizar visão</span>
                    <span wire:loading wire:target="refreshSummary">Atualizando…</span>
                </button>
            </div>
        </header>

        <section class="ef29-kpis" aria-label="Indicadores comerciais reais">
            @foreach ($ds25Cards as $card)
                <a href="{{ $card['url'] }}" wire:navigate
                   class="ef29-kpi {{ $loop->last ? 'ef29-kpi--accent' : '' }}"
                   title="{{ $card['description'] }}">
                    <span class="ef29-kpi-title">{{ $loop->index === 1 ? 'Sem responsável' : ($loop->index === 2 ? 'Com negócio HubSpot' : $card['title']) }}</span>
                    <strong class="ef29-kpi-value">{{ $ds25Number($card['value']) }}</strong>
                    <span class="ef29-kpi-caption">{{ $loop->index === 0 ? 'Empresas da carteira comercial' : ($loop->index === 1 ? 'Prontos para distribuição' : 'Empresas com um ou mais negócios') }}</span>
                </a>
            @endforeach
        </section>

        <div class="ef29-layout ef29-layout--first">
            <section class="ef29-card ef29-stages" aria-labelledby="ef29-stages-heading">
                <div class="ef29-card-title">
                    <h2 id="ef29-stages-heading">Meus negócios por etapa</h2>
                    <span title="Total de negócios identificados para seu responsável do HubSpot">{{ $ds25Number($ds25Deals['total'] ?? 0) }} negócios</span>
                </div>
                @if ($ef29Stages !== [])
                    <div class="ef29-bars">
                        @foreach ($ef29Stages as $stage)
                            @php
                                $ef29StageValue = (int) ($stage['value'] ?? 0);
                                $ef29StageWidth = $ef29StageValue > 0 ? max(2, round(100 * $ef29StageValue / $ef29StageMax)) : 0;
                            @endphp
                            <a href="{{ route('leads.index', ['dashboardStage' => $stage['label']]) }}"
                               wire:navigate class="ef29-bar-row"
                               title="Abrir negócios na etapa {{ $stage['label'] }}">
                                <span class="ef29-bar-label">{{ $stage['label'] }}</span>
                                <span class="ef29-bar-track" aria-hidden="true">
                                    <i style="width: {{ $ef29StageWidth }}%"></i>
                                </span>
                                <span class="ef29-bar-count">{{ $ds25Number($ef29StageValue) }} {{ $ef29StageValue === 1 ? 'negócio' : 'negócios' }}</span>
                            </a>
                        @endforeach
                    </div>
                    @if ($ef29ExtraStages !== [])
                        <details class="ef29-extra-stages">
                            <summary>Ver todas as {{ count($ds25Stages) }} etapas do HubSpot</summary>
                            <div class="ef29-bars">
                                @foreach ($ef29ExtraStages as $stage)
                                    @php $ef29Value = (int) ($stage['value'] ?? 0); @endphp
                                    <a href="{{ route('leads.index', ['dashboardStage' => $stage['label']]) }}"
                                       wire:navigate class="ef29-bar-row">
                                        <span class="ef29-bar-label">{{ $stage['label'] }}</span>
                                        <span class="ef29-bar-track" aria-hidden="true"><i style="width: {{ $ef29Value > 0 ? max(2, round(100 * $ef29Value / $ef29StageMax)) : 0 }}%"></i></span>
                                        <span class="ef29-bar-count">{{ $ds25Number($ef29Value) }} negócios</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @else
                    <div class="ef29-empty">Ainda não há negócios vinculados ao seu responsável no HubSpot.</div>
                @endif
            </section>

            <section class="ef29-action-panel" aria-labelledby="ef29-actions-heading">
                <h2 id="ef29-actions-heading">Comece por aqui</h2>
                @php
                    $ef29Focus = [
                        ['title' => 'Minhas tarefas atrasadas', 'value' => $ds25MyDueTasks['overdue'],
                         'note' => 'Retome as tarefas vencidas atribuídas a você.',
                         'url' => route('leads.index', ['dueActionOnly' => 1, 'queueDuePeriod' => 'overdue'])],
                        ['title' => 'Sem responsável', 'value' => $ds25Metrics['unassigned'],
                         'note' => 'Distribua a carteira com prévia de impacto.',
                         'url' => route('leads.index', ['dashboardView' => 'all', 'owner' => 'unassigned'])],
                        ['title' => 'Registros sem CNPJ', 'value' => $ds25CrmWithoutCnpj,
                         'note' => 'Confira a identidade fiscal do CRM.',
                         'url' => route('leads.index', ['hubSpotOnly' => 1])],
                    ];
                @endphp
                <div class="ef29-focus-list">
                    @foreach ($ef29Focus as $action)
                        <a href="{{ $action['url'] }}" wire:navigate class="ef29-focus">
                            <strong>{{ $ds25Number($action['value']) }} {{ mb_strtolower($action['title']) }}</strong>
                            <span>{{ $action['note'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="ef29-layout ef29-layout--second">
            @include('livewire.dashboard-overview.map-panel-v30')

            <section class="ef29-card ef29-states-panel" aria-labelledby="ef29-states-heading">
                <h2 id="ef29-states-heading">Estados em destaque</h2>
                <div class="ef29-table-scroll">
                    <table class="ef29-states-table">
                        <thead><tr><th scope="col">UF</th><th scope="col">Unidades</th><th scope="col">Empresas</th></tr></thead>
                        <tbody>
                            @forelse ($ef29TopStates as $state)
                                <tr>
                                    <td>
                                        <button type="button" wire:click="openExplorer('state', '{{ $state['code'] }}')"
                                                title="Explorar estabelecimentos em {{ $state['code'] }}">
                                            {{ $state['code'] }}
                                        </button>
                                    </td>
                                    <td>{{ $ds25Number($state['total']) }}</td>
                                    <td>{{ $ds25Number($ef29CompanyByState[$state['code']] ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3">Sem unidades com UF identificada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="ef29-states-footnote">Um grupo empresarial pode atuar em mais de uma UF.</p>
            </section>
        </div>

        <section class="ef29-more-actions" aria-labelledby="ef29-more-heading">
            <h2 id="ef29-more-heading">Outras ações rápidas</h2>
            <div class="ef29-more-grid">
                @php
                    $ef29More = [
                        ['tag' => 'Reprospecção', 'title' => 'Parados há 90 dias',
                         'description' => $ds25Number($ds25Metrics['stale90']).' empresas sem movimentação recente.',
                         'url' => route('leads.index', ['dashboardView' => 'stale90'])],
                        ['tag' => 'Descoberta', 'title' => 'Novos sem CRM',
                         'description' => $ds25Number($ds25NewCount).' empresas para iniciar relacionamento.',
                         'url' => route('leads.index', ['crmSituation' => 'new'])],
                        ['tag' => 'Rotina', 'title' => 'Aguardando retorno',
                         'description' => $ds25Number($ds25MyDueTasks['total']).' tarefas abertas vencidas ou para hoje (minha carteira HubSpot).',
                         'url' => route('leads.index', ['dueActionOnly' => 1])],
                    ];
                @endphp
                @foreach ($ef29More as $card)
                    <a href="{{ $card['url'] }}" wire:navigate class="ef29-more-card">
                        <span class="ef29-tag">{{ $card['tag'] }}</span>
                        <strong>{{ $card['title'] }}</strong>
                        <span>{{ $card['description'] }}</span>
                        <em>Ver detalhes <span aria-hidden="true">→</span></em>
                    </a>
                @endforeach
            </div>
        </section>
        <footer class="ef29-footer">ExportControl Prospector · Indicadores consultados em {{ $s['consulted_at'] }} · Dados locais</footer>
    @else
        @php
            // D01-V: somente carteira e negócios do usuário atual; nenhuma métrica da equipe.
            $ef29CurrentUserId = (int) auth()->id();
            $ef29MyDeals = app(\App\Services\DashboardOwnedDealsV23Service::class)
                ->forUser($ef29CurrentUserId);
            $ef29MyStages = array_slice($ef29MyDeals['stages'], 0, 5); // EC_PROSPECTOR_V41 vendedor.
            $ef29MyExtraStages = array_slice($ef29MyDeals['stages'], 5);
            $ef29Max = max([1, ...array_map(static fn (array $stage): int => (int) $stage['value'], $ef29MyStages)]);
            $ef29MyPortfolio = \App\Models\CompanyLeadWorkState::query()
                ->where('assigned_user_id', $ef29CurrentUserId)
                ->count();
            // V55: hora exata do HubSpot; não inclui prazos já vencidos.
            $ef29MyToday = app(\App\Services\DashboardHubSpotDueTasksV40Service::class)
                ->forUser($ef29CurrentUserId)['today'];
            $ef29Overdue = \App\Models\CompanyLeadWorkState::query()
                ->with('company')
                ->where('assigned_user_id', $ef29CurrentUserId)
                ->whereNotNull('next_action_at')
                ->where('next_action_at', '<', now())
                ->orderBy('next_action_at')
                ->first();
            $ef29DueToday = \App\Models\CompanyLeadWorkState::query()
                ->with('company')
                ->where('assigned_user_id', $ef29CurrentUserId)
                ->where('next_action_at', '>=', now())
                ->where('next_action_at', '<', now()->addDay()->startOfDay())
                ->orderBy('next_action_at')
                ->first();
            $ef29New = \App\Models\CompanyLeadWorkState::query()
                ->with('company')
                ->where('assigned_user_id', $ef29CurrentUserId)
                ->whereNull('last_action_at')
                ->whereNull('next_action_at')
                ->orderBy('id')
                ->first();
            $ef29Priorities = [
                ['tag'=>'Atrasado', 'state'=>$ef29Overdue, 'description'=>'Próxima ação com prazo vencido'],
                ['tag'=>'Hoje', 'state'=>$ef29DueToday, 'description'=>'Próxima ação prevista para hoje'],
                ['tag'=>'Novo', 'state'=>$ef29New, 'description'=>'Contato inicial ainda não registrado'],
            ];
        @endphp

        <header class="ef29-intro">
            <div class="ef29-intro-meta">
                <span>DASHBOARD</span>
                <span class="ef29-tag">Vendedor · {{ auth()->user()->name }}</span>
            </div>
            <h1>Sua próxima boa conversa</h1>
            <p>Uma visão pessoal da carteira permitida a você.</p>
            <div class="ef29-intro-actions">
                <a class="ef29-button ef29-button--main"
                   href="{{ route('leads.index', ['owner' => 'mine']) }}" wire:navigate>Abrir minha fila</a>
            </div>
        </header>

        <section class="ef29-kpis" aria-label="Meus indicadores">
            <a class="ef29-kpi" href="{{ route('leads.index', ['owner' => 'mine']) }}" wire:navigate>
                <span class="ef29-kpi-title">Minha carteira</span>
                <strong class="ef29-kpi-value">{{ number_format($ef29MyPortfolio, 0, ',', '.') }}</strong>
                <span class="ef29-kpi-caption">Empresas atribuídas a você</span>
            </a>
            <a class="ef29-kpi" href="{{ route('leads.index', ['dueActionOnly' => 1, 'queueDuePeriod' => 'today']) }}" wire:navigate>
                <span class="ef29-kpi-title">Vencem hoje</span>
                <strong class="ef29-kpi-value">{{ number_format($ef29MyToday, 0, ',', '.') }}</strong>
                <span class="ef29-kpi-caption">Tarefas HubSpot ainda não vencidas</span>
            </a>
            <a class="ef29-kpi ef29-kpi--accent" href="{{ route('leads.index', ['owner' => 'mine', 'dashboardView' => 'with_deal']) }}" wire:navigate>
                <span class="ef29-kpi-title">Meus negócios</span>
                <strong class="ef29-kpi-value">{{ number_format($ef29MyDeals['total'], 0, ',', '.') }}</strong>
                <span class="ef29-kpi-caption">Negócios atribuídos no HubSpot</span>
            </a>
        </section>

        <section class="ef29-today" aria-labelledby="ef29-today-heading">
            <h2 id="ef29-today-heading">Prioridades de hoje</h2>
            <div class="ef29-more-grid">
                @foreach ($ef29Priorities as $priority)
                    @php $ef29Record = $priority['state']; @endphp
                    @if ($ef29Record && $ef29Record->company)
                        <a href="{{ route('companies.show', ['company' => $ef29Record->company->uuid]) }}" wire:navigate class="ef29-more-card">
                            <span class="ef29-tag">{{ $priority['tag'] }}</span>
                            <strong>{{ $ef29Record->company->corporate_name }}</strong>
                            <span>{{ $priority['description'] }}</span>
                            <em>Ver detalhes <span aria-hidden="true">→</span></em>
                        </a>
                    @else
                        <div class="ef29-more-card ef29-more-card--empty">
                            <span class="ef29-tag">{{ $priority['tag'] }}</span>
                            <strong>Nenhum item nesta categoria</strong>
                            <span>{{ $priority['description'] }}</span>
                            <a href="{{ route('leads.index', ['owner' => 'mine']) }}" wire:navigate>Ver minha carteira →</a>
                        </div>
                    @endif
                @endforeach
            </div>
        </section>

        <section class="ef29-card ef29-seller-deals" aria-labelledby="ef29-seller-stages-title">
            <h2 id="ef29-seller-stages-title">Meus negócios por etapa</h2>
            @if ($ef29MyStages !== [])
                <div class="ef29-bars">
                    @foreach ($ef29MyStages as $stage)
                        @php
                            $ef29Count = (int) $stage['value'];
                            $ef29Width = $ef29Count > 0 ? max(2, round(100 * $ef29Count / $ef29Max)) : 0;
                        @endphp
                        <a href="{{ route('leads.index', ['owner'=>'mine','dashboardStage'=>$stage['label']]) }}" wire:navigate class="ef29-bar-row">
                            <span class="ef29-bar-label">{{ $stage['label'] }}</span>
                            <span class="ef29-bar-track" aria-hidden="true"><i style="width: {{ $ef29Width }}%"></i></span>
                            <span class="ef29-bar-count">{{ number_format($ef29Count, 0, ',', '.') }} negócios</span>
                        </a>
                    @endforeach
                </div>
                @if ($ef29MyExtraStages !== [])
                    <details class="ef29-extra-stages">
                        <summary>Ver todas as {{ count($ef29MyDeals['stages']) }} etapas do HubSpot</summary>
                        <div class="ef29-bars">
                            @foreach ($ef29MyExtraStages as $stage)
                                @php
                                    $ef29Value = (int) ($stage['value'] ?? 0);
                                    $ef29Width = $ef29Value > 0 ? max(2, round(100 * $ef29Value / $ef29Max)) : 0;
                                @endphp
                                <a href="{{ route('leads.index', ['owner' => 'mine', 'dashboardStage' => $stage['label']]) }}"
                                   wire:navigate class="ef29-bar-row">
                                    <span class="ef29-bar-label">{{ $stage['label'] }}</span>
                                    <span class="ef29-bar-track" aria-hidden="true"><i style="width: {{ $ef29Width }}%"></i></span>
                                    <span class="ef29-bar-count">{{ number_format($ef29Value, 0, ',', '.') }} negócios</span>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endif
            @else
                <p class="ef29-empty">Ainda não há negócios associados a seu responsável HubSpot.</p>
            @endif
        </section>
        <section class="ef29-seller-note">
            <span aria-hidden="true">✓</span>
            <p><strong>Seu contexto de trabalho</strong><br>Você vê apenas empresas autorizadas da sua carteira. Indicadores globais e administração ficam disponíveis ao gestor.</p>
        </section>
        <footer class="ef29-footer">ExportControl Prospector · Dados reais da sua carteira</footer>
    @endif
</div>
