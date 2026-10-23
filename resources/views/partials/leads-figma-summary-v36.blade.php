{{-- PACOTE 42: mesmos indicadores em todos os filtros; clicar somente refina a lista. --}}
<section class="lf36-kpis lf42-kpis" aria-label="Indicadores reais da fila de Leads">
    <button type="button" class="lf36-kpi {{ ! $dueActionOnly && $owner === '' && $crmSituation === '' && $globalHubSpotStage === '' && $personalHubSpotStage === '' ? 'is-accent' : '' }}"
            wire:click="clearFilters" wire:loading.attr="disabled" aria-label="Ver todos os leads">
        <span>{{ ($globalHubSpotStage !== '' || $personalHubSpotStage !== '') ? 'Negócios na etapa' : 'Leads no recorte' }}</span>
        <strong>{{ number_format($globalHubSpotStage !== '' ? $this->globalHubSpotStageDeals->total() : ($personalHubSpotStage !== '' ? $this->personalHubSpotStageDeals->total() : $this->leads->total()), 0, ',', '.') }}</strong>
        <small>{{ ($globalHubSpotStage !== '' || $personalHubSpotStage !== '') ? 'Negócios reais do HubSpot filtrados pela etapa' : 'Empresas que atendem aos filtros atuais' }}</small>
    </button>
    <button type="button" class="lf36-kpi {{ $dueActionOnly && $queueDuePeriod === 'overdue' ? 'is-accent' : '' }}"
            wire:click="applyFigmaFollowUp('overdue')" wire:loading.attr="disabled"
            aria-pressed="{{ $dueActionOnly && $queueDuePeriod === 'overdue' ? 'true' : 'false' }}">
        <span>Atrasados</span>
        <strong>{{ number_format($this->v17DueBreakdown['overdue'], 0, ',', '.') }}</strong>
        <small>Tarefas da carteira já vencidas</small>
    </button>
    <button type="button" class="lf36-kpi {{ $dueActionOnly && $queueDuePeriod === 'today' ? 'is-accent' : '' }}"
            wire:click="applyFigmaFollowUp('today')" wire:loading.attr="disabled"
            aria-pressed="{{ $dueActionOnly && $queueDuePeriod === 'today' ? 'true' : 'false' }}">
        <span>Vencem hoje</span>
        <strong>{{ number_format($this->v17DueBreakdown['today'], 0, ',', '.') }}</strong>
        <small>Tarefas abertas previstas para hoje</small>
    </button>
    @if ($this->isCommercialManager())
        <button type="button" class="lf36-kpi {{ $owner === 'unassigned' ? 'is-accent' : '' }}"
                wire:click="applyFigmaUnassigned" wire:loading.attr="disabled"
                aria-pressed="{{ $owner === 'unassigned' ? 'true' : 'false' }}">
            <span>Sem responsável</span>
            <strong>{{ number_format($this->unassignedCount, 0, ',', '.') }}</strong>
            <small>Empresas ainda sem atribuição</small>
        </button>
    @else
        <button type="button" class="lf36-kpi {{ $owner === 'mine' ? 'is-accent' : '' }}"
                wire:click="applyV15TopCard('mine')" wire:loading.attr="disabled">
            <span>Minha carteira</span>
            <strong>{{ number_format($this->myLeadsCount, 0, ',', '.') }}</strong>
            <small>Empresas atribuídas a você</small>
        </button>
    @endif
</section>
