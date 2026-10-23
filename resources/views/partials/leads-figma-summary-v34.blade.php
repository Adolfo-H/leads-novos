{{-- Pacote 34 / L01-L04: métricas reais das mesmas consultas da fila. --}}
<section class="lf34-kpis" aria-label="Indicadores reais da fila comercial">
    <button type="button" class="lf34-kpi {{ ! $dueActionOnly && $owner !== 'mine' && $crmSituation !== 'new' ? 'is-active' : '' }}"
        wire:click="clearFilters" wire:loading.attr="disabled">
        <span>Leads no recorte</span>
        <strong>{{ number_format($this->leads->total(), 0, ',', '.') }}</strong>
        <small>Registros com os filtros atuais</small>
    </button>
    <button type="button" class="lf34-kpi" wire:click="applyFigmaFollowUp('overdue')" wire:loading.attr="disabled">
        <span>Atrasados</span>
        <strong>{{ number_format($this->v17DueBreakdown['overdue'], 0, ',', '.') }}</strong>
        <small>Retornos vencidos da operação</small>
    </button>
    <button type="button" class="lf34-kpi" wire:click="applyFigmaFollowUp('today')" wire:loading.attr="disabled">
        <span>Vencem hoje</span>
        <strong>{{ number_format($this->v17DueBreakdown['today'], 0, ',', '.') }}</strong>
        <small>Próximos acompanhamentos</small>
    </button>
    @if ($this->isCommercialManager())
        <button type="button" class="lf34-kpi" wire:click="applyOwnerView('unassigned')" wire:loading.attr="disabled">
            <span>Sem responsável</span>
            <strong>{{ number_format($this->unassignedCount, 0, ',', '.') }}</strong>
            <small>Atribuição pendente no Prospector</small>
        </button>
    @else
        <button type="button" class="lf34-kpi" wire:click="applyV15TopCard('mine')" wire:loading.attr="disabled">
            <span>Minha carteira</span>
            <strong>{{ number_format($this->myLeadsCount, 0, ',', '.') }}</strong>
            <small>Somente os leads atribuídos a você</small>
        </button>
    @endif
</section>
