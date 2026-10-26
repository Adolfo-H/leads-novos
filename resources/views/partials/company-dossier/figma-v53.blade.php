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
    $e53Direct = (bool) ($e53Export?->direct_confirmed ?? false);
    $e53Signal = $e53Direct ? 'Sinal direto' : (($e53Export?->direct_status === 'positive') ? 'Indícios diretos' : 'Não confirmado');
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
@endphp

<div class="e53-figma" data-ec-dossier-figma="E05-E09">
    <div class="e53-crumb"><a href="{{ route('companies.index') }}" wire:navigate>EMPRESAS</a><span x-show="dossierTab==='commercial' && dossierView==='summary'">/ Dossiê</span></div>

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
                <a x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak class="e53-button e53-button--outline" href="{{ route('companies.edit', $company) }}" wire:navigate>Editar empresa</a>
                <button x-show="dossierTab === 'commercial' && dossierView === 'summary'" x-cloak type="button" class="e53-button e53-button--primary" x-on:click="e53ResearchOpen=true">Pesquisar exportações</button>
                @can('createHubSpotOpportunity', $company)
                <button x-show="dossierTab === 'commercial' && dossierView === 'crm'" x-cloak type="button" class="e53-button e53-button--primary" wire:click="createHubSpotOpportunity" wire:loading.attr="disabled" wire:target="createHubSpotOpportunity">Criar oportunidade</button>
                @endcan
                <a x-show="dossierTab === 'company'" x-cloak class="e53-button e53-button--outline" href="{{ route('companies.edit', $company) }}" wire:navigate>Editar empresa</a>
                <a x-show="dossierTab === 'company'" x-cloak class="e53-button e53-button--primary" href="{{ route('companies.branches.create', $company) }}" wire:navigate>Adicionar filial</a>
                @can('manageData', $company)
                <button x-show="dossierTab === 'cnaes'" x-cloak type="button" class="e53-button e53-button--primary" x-on:click="e53AddCnaeOpen=true">Adicionar CNAE</button>
                @endcan
            </div>
        </div>
        <div class="e53-hero-side" x-show="dossierTab === 'commercial' && dossierView === 'summary'">
            <span class="e53-chip e53-chip--green">{{ $e53Matrix?->registration_status === 'ATIVA' ? 'Ativa' : ($e53Matrix?->registration_status ?: 'Situação não informada') }}</span>
            <small>Dados da base local</small>
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

    <footer class="e53-footer">
        <span>ExportControl Prospector · Dados locais e vínculos confirmados</span>
        <button type="button" x-on:click="showAdvancedDossier = !showAdvancedDossier" x-bind:aria-expanded="showAdvancedDossier ? 'true' : 'false'">
            <span x-text="showAdvancedDossier ? 'Ocultar funcionalidades avançadas' : 'Ver funcionalidades avançadas'">Ver funcionalidades avançadas</span> <span aria-hidden="true">↗</span>
        </button>
    </footer>

    @include('partials.company-dossier.figma-modals-v53')
</div>
