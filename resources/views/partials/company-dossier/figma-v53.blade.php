{{-- EC_PROSPECTOR_E53: remodelagem do Dossiê fiel à estrutura E05–E09 do ZIP Figma.
     Uma única consulta de dados locais; não inventar resultados, não chamar API no render.
     As ações de escrita seguem os métodos e Policies já existentes no Volt. --}}
@php
    $company->loadMissing([
        'leadWorkState.assignedUser',
        'hubSpotCompanies.commercialDeals',
        'hubSpotCompanies.contacts',
        'hubSpotCompanies.tasks',
        'hubSpotCompanies.activities',
        'hubSpotCompanies.commercialDeals.tasks',
    ]);
    $e53Matrix = $this->matrix;
    $e53Op = $this->groupOperationalSummary;
    $e53Contacts = $this->groupContactSummary;
    $e53Cnaes = $this->groupCnaes;
    $e53HubCompanies = $company->hubSpotCompanies;
    $e53Deals = $e53HubCompanies->flatMap(fn ($hub) => $hub->commercialDeals)->unique('id')->values();
    $e53Tasks = $e53HubCompanies->flatMap(fn ($hub) => $hub->tasks)
        ->concat($e53Deals->flatMap(fn ($deal) => $deal->tasks))->unique('id')->values();
    $e53OpenTasks = $e53Tasks->filter(fn ($task) => (bool) $task->is_open && $task->completed_at === null)
        ->sortBy(fn ($task) => $task->due_at?->getTimestamp() ?? PHP_INT_MAX)->values();
    $e53NextTask = $e53OpenTasks->first();
    $e53HubActivities = $e53HubCompanies->flatMap(fn ($hub) => $hub->activities)
        ->filter(fn ($activity) => ! (bool) $activity->is_deleted)->unique('id')->values();
    $e53Timeline = $company->leadActivities->map(fn ($activity) => [
        'at' => $activity->occurred_at,
        'title' => $activity->title ?: 'Atividade comercial',
        'description' => $activity->description ?: '',
        'origin' => $activity->source ?: 'Prospector',
    ])->concat($e53HubActivities->map(fn ($activity) => [
        'at' => $activity->occurred_at,
        'title' => $activity->title ?: 'Atividade do HubSpot',
        'description' => $activity->description ?: '',
        'origin' => 'HubSpot',
    ]))->sortByDesc(fn ($activity) => $activity['at']?->getTimestamp() ?? 0)->unique(
        fn ($activity) => (string) ($activity['at']?->toIso8601String() ?? '').'|'.(string) $activity['title']
    )->values();
    $e53Crm = $company->crmCheck;
    $e53Lead = $company->hubSpotLead;
    $e53CrmOverride = app(\App\Services\LeadCommercialSituationV52Service::class)
        ->forCompanies(collect([$company]))[(int) $company->id] ?? null;
    $e53Status = match (true) {
        $e53CrmOverride === 'client', $e53Crm?->status === 'client' => 'Cliente',
        $e53CrmOverride === 'known' => 'Conhecido',
        $e53CrmOverride === 'reprospecting', $e53Crm?->status === 'prospected' => 'Reprospecção',
        $e53Lead?->work_status === 'future' => 'Oportunidade futura',
        $e53Lead?->work_status === 'converted' => 'Cliente',
        $e53Crm?->status === 'opportunity' => 'Oportunidade',
        $e53Crm?->status === 'known' => 'Conhecido',
        $e53Crm?->status === 'not_found' => 'Novo',
        default => 'Não classificado',
    };
    $e53Score = $company->sdrScore;
    $e53Icp = $company->icpScore;
    $e53Export = $company->exportIntelligence;
    $e53ExportSummary = $this->exportResearchSummary;
    $e53ExportTitle = is_array($e53ExportSummary) && filled($e53ExportSummary['headline'] ?? null)
        ? (string) $e53ExportSummary['headline']
        : (filled($e53Export?->overall_summary) ? (string) $e53Export->overall_summary : 'Sem pesquisa concluída');
    // DOSSIE_EXPORTACAO_V58: leitura comercial derivada das fontes reais, não de um status isolado.
    $e58Dossier = app(\App\Services\CompanyExportDossierV58Service::class)->build($company);
    $e53Signal = $e58Dossier['status'];
    $e53LastActivity = $e53Lead?->last_activity_at ?? $e53Crm?->last_contacted_at
        ?? $e53HubCompanies->pluck('last_activity_at')->filter()->sortDesc()->first();
    $e53Owner = $company->leadWorkState?->assignedUser?->name
        ?? $e53HubCompanies->pluck('owner_name')->filter()->first() ?? 'Não atribuído';
    $e53EmailItems = collect($e53Contacts['emails'] ?? [])->values();
    $e53PhoneItems = collect($e53Contacts['phones'] ?? [])->values();
    $e53ResearchStatus = (string) ($e53Export?->research_status ?? 'idle');
    $e53ResearchRunning = in_array($e53ResearchStatus, ['queued', 'processing'], true);
    $e53ResearchConfigured = $this->exportResearchConfigured();
    $e53ResearchEligibility = $this->exportResearchEligibility;
    $e53HubSpotCompanyUrl = $this->hubSpotCompanyUrl();
@endphp

<div class="e53-figma" data-ec-dossier-figma="E05-E09">
    <div class="e53-crumb">
    {{-- DOSSIE_NAVEGACAO_ICP_V60 BACK: rota segura com fallback caso não haja origem. --}}
    <a href="{{ route('leads.index') }}" class="e60-back-to-leads" data-ec-return-leads
       title="Voltar para a listagem de Leads preservando filtros e posição"
       aria-label="Voltar aos leads com os filtros anteriores">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m15 18-6-6 6-6"/><path d="M20 12H9"/>
        </svg>
        <span>Voltar aos leads</span>
    </a>
<a href="{{ route('companies.index') }}" wire:navigate>EMPRESAS</a><span x-show="dossierTab==='commercial' && dossierView==='summary'">/ Dossiê</span></div>

    <header class="e53-hero">
        <div class="e53-hero-main">
            <h1 x-show="dossierTab === 'commercial' && dossierView === 'crm'" x-cloak>Relacionamento no HubSpot</h1>
            <h1 x-show="dossierTab === 'commercial' && dossierView === 'history'" x-cloak>Histórico, tarefas e evidências</h1>
            <h1 x-show="dossierTab === 'company' || (dossierTab === 'commercial' && dossierView === 'summary')" x-cloak>{{ $company->corporate_name }}</h1>
            <h1 x-show="dossierTab === 'cnaes'" x-cloak>Atividades econômicas</h1>
            <p x-show="dossierTab === 'commercial' && dossierView === 'summary'">
                {{ $e53Matrix?->cnpj ? \App\Support\Cnpj::format($e53Matrix->cnpj) : $company->cnpj_root }}
                · {{ $e53Matrix?->type === 'matrix' ? 'Matriz' : 'Unidade' }}
                @if ($e53Matrix?->municipality_name) · {{ $e53Matrix->municipality_name }}/{{ $e53Matrix->state }} @endif
            </p>
            <p x-show="dossierTab === 'commercial' && dossierView === 'crm'">{{ $company->corporate_name }} · vínculos, negócios e reprospecção registrados no HubSpot.</p>
            <p x-show="dossierTab === 'commercial' && dossierView === 'history'">{{ $company->corporate_name }} · contexto para sua próxima conversa comercial.</p>
            <p x-show="dossierTab === 'company'">Estrutura empresarial, contatos cadastrais e unidades.</p>
            <p x-show="dossierTab === 'cnaes'">CNAEs do estabelecimento de referência · matriz {{ $e53Matrix?->cnpj ? \App\Support\Cnpj::format($e53Matrix->cnpj) : $company->cnpj_root }}.</p>
            <div class="e53-hero-actions">
                <button x-show="dossierTab === 'commercial' && dossierView !== 'summary'" x-cloak type="button" class="e53-button e53-button--outline" x-on:click="dossierView='summary'">Voltar ao dossiê</button>
                @can('manageData', $company)
                <a x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak class="e53-button e53-button--outline" href="{{ route('companies.edit', $company) }}" wire:navigate>Editar empresa</a>
                @endcan
                <button x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak type="button" class="e53-button e53-button--primary" x-on:click="e53ResearchOpen=true">Pesquisar exportações</button>
                @if ($e53HubSpotCompanyUrl)
                <a x-show="dossierTab === 'commercial' && dossierView === 'crm'" x-cloak class="e53-button e53-button--outline" href="{{ $e53HubSpotCompanyUrl }}" target="_blank" rel="noopener noreferrer">Abrir empresa no HubSpot ↗</a>
                @endif
                @can('createHubSpotOpportunity', $company)
                    @if (($this->hubSpotManualOpportunityState)['can_queue'])
                    <button x-show="dossierTab === 'commercial' && dossierView === 'crm'" x-cloak type="button" class="e53-button e53-button--primary" wire:click="createHubSpotOpportunity" wire:loading.attr="disabled" wire:target="createHubSpotOpportunity">{{ in_array(($this->hubSpotManualOpportunityState)['status'], ['failed', 'partial'], true) ? 'Tentar novamente' : 'Criar oportunidade no HubSpot' }}</button>
                    @endif
                @endcan
                @can('manageData', $company)
                <a x-show="dossierTab === 'company'" x-cloak class="e53-button e53-button--outline" href="{{ route('companies.edit', $company) }}" wire:navigate>Editar empresa</a>
                <a x-show="dossierTab === 'company'" x-cloak class="e53-button e53-button--primary" href="{{ route('companies.branches.create', $company) }}" wire:navigate>Adicionar filial</a>
                @endcan
                @can('manageData', $company)
                <button x-show="dossierTab === 'cnaes'" x-cloak type="button" class="e53-button e53-button--primary" x-on:click="e53AddCnaeOpen=true">Adicionar CNAE</button>
                @endcan
            </div>
        </div>
                {{-- DOSSIE_UI_HUBSPOT_V591 preservado; DOSSIE_UI_HUBSPOT_V592: atalho HubSpot e cartão cadastral são elementos separados. --}}
        @if ($e53HubSpotCompanyUrl)
            <a class="e591-hubspot-link e592-hubspot-icon"
               href="{{ $e53HubSpotCompanyUrl }}"
               x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak
               target="_blank" rel="noopener noreferrer"
               title="Abrir no HubSpot"
               aria-label="Abrir no HubSpot em nova aba">
                {{-- Ícone HubSpot · Font Awesome Free 6.7.2, CC BY 4.0; crédito no LEIA-ME. --}}
                <svg class="e592-hubspot-mark" viewBox="0 0 512 512" width="26" height="26" fill="currentColor" aria-hidden="true">
                    <path d="M267.4 211.6c-25.1 23.7-40.8 57.3-40.8 94.6 0 29.3 9.7 56.3 26 78L203.1 434c-4.4-1.6-9.1-2.5-14-2.5-10.8 0-20.9 4.2-28.5 11.8-7.6 7.6-11.8 17.8-11.8 28.6s4.2 20.9 11.8 28.5c7.6 7.6 17.8 11.6 28.5 11.6 10.8 0 20.9-3.9 28.6-11.6 7.6-7.6 11.8-17.8 11.8-28.5 0-4.2-.6-8.2-1.9-12.1l50-50.2c22 16.9 49.4 26.9 79.3 26.9 71.9 0 130-58.3 130-130.2 0-65.2-47.7-119.2-110.2-128.7V116c17.5-7.4 28.2-23.8 28.2-42.9 0-26.1-20.9-47.9-47-47.9S311.2 47 311.2 73.1c0 19.1 10.7 35.5 28.2 42.9v61.2c-15.2 2.1-29.6 6.7-42.7 13.6-27.6-20.9-117.5-85.7-168.9-124.8 1.2-4.4 2-9 2-13.8C129.8 23.4 106.3 0 77.4 0 48.6 0 25.2 23.4 25.2 52.2c0 28.9 23.4 52.3 52.2 52.3 9.8 0 18.9-2.9 26.8-7.6l163.2 114.7zm89.5 163.6c-38.1 0-69-30.9-69-69s30.9-69 69-69 69 30.9 69 69-30.9 69-69 69z"/>
                </svg>
                <span class="sr-only">Abrir no HubSpot</span>
            </a>
        @endif
        <div class="e53-hero-side e592-status-card" x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak>
            <div class="e591-legal-state">
                <span class="e53-chip e53-chip--green">{{ $e53Matrix?->registration_status === 'ATIVA' ? 'Ativa' : ($e53Matrix?->registration_status ?: 'Situação não informada') }}</span>

            </div>
        </div>
    </header>

    <nav class="e53-tabs" aria-label="Abas do dossiê empresarial">
        <button type="button" x-on:click="setDossierTab('commercial')" x-bind:class="{'is-active':dossierTab==='commercial'}" x-bind:aria-current="dossierTab==='commercial' ? 'page' : null">Comercial</button>
        <button type="button" x-on:click="setDossierTab('company')" x-bind:class="{'is-active':dossierTab==='company'}" x-bind:aria-current="dossierTab==='company' ? 'page' : null">Empresa &amp; grupo ({{ $e53Op['total'] ?? 0 }})</button>
        <button type="button" x-on:click="setDossierTab('cnaes')" x-bind:class="{'is-active':dossierTab==='cnaes'}" x-bind:aria-current="dossierTab==='cnaes' ? 'page' : null">CNAEs ({{ ($this->primaryCnae ? 1 : 0) + $this->secondaryCnaes->count() }})</button>
    </nav>

    @include('partials.company-dossier.figma-summary-v53')
    @include('partials.company-dossier.figma-crm-v53')
    @include('partials.company-dossier.figma-history-v53')
    @include('partials.company-dossier.figma-group-v53')
    @include('partials.company-dossier.figma-cnaes-v53')


    @include('partials.company-dossier.figma-modals-v53')
</div>
