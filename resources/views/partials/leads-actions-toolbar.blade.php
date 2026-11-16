{{-- Leads V12: componentes isolados das regras visuais legadas. --}}
<section class="lv12-action-center" aria-label="Foco do dia">
    <h2 class="lv12-visually-hidden">Foco do dia — O que precisa da sua atenção agora</h2>

    <div class="lv12-card-grid">
        <button type="button"
                wire:click="applyFollowUpView('overdue')"
                class="lv12-card lv12-card--red {{ $followUp === 'overdue' ? 'is-active' : '' }}">
            <span class="lv12-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2.8 19a1.5 1.5 0 0 0 1.3 2.2h15.8a1.5 1.5 0 0 0 1.3-2.2L12 3Z"/><path d="M12 9v5M12 17.5h.01"/></svg>
            </span>
            <span class="lv12-card-copy">
                <span class="lv12-card-title">Atrasados</span>
                <strong class="lv12-card-value">{{ number_format($this->overdueCount, 0, ',', '.') }}</strong>
                <span class="lv12-card-caption">Já passaram do prazo</span>
            </span>
            <span class="lv12-card-chevron" aria-hidden="true">›</span>
            <span class="lv12-visually-hidden">Follow-ups atrasados</span>
        </button>

        <button type="button"
                wire:click="applyFollowUpView('today')"
                class="lv12-card lv12-card--blue {{ $followUp === 'today' ? 'is-active' : '' }}">
            <span class="lv12-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg>
            </span>
            <span class="lv12-card-copy">
                <span class="lv12-card-title">Vencem hoje</span>
                <strong class="lv12-card-value">{{ number_format($this->dueTodayCount, 0, ',', '.') }}</strong>
                <span class="lv12-card-caption">Requerem atenção hoje</span>
            </span>
            <span class="lv12-card-chevron" aria-hidden="true">›</span>
        </button>

        <button type="button"
                wire:click="applyDailyView"
                class="lv12-card lv12-card--cyan {{ $dailyView === 'today' ? 'is-active' : '' }}">
            <span class="lv12-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="3"/><path d="M2 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M17 14a5 5 0 0 1 5 5v1"/></svg>
            </span>
            <span class="lv12-card-copy">
                <span class="lv12-card-title">Minha fila</span>
                <strong class="lv12-card-value">{{ number_format($this->dailyQueueCount, 0, ',', '.') }}</strong>
                <span class="lv12-card-caption">Leads para trabalhar</span>
            </span>
            <span class="lv12-card-chevron" aria-hidden="true">›</span>
            <span class="lv12-visually-hidden">Minha fila hoje</span>
        </button>

        <button type="button"
                wire:click="applyQuickView('new')"
                class="lv12-card lv12-card--mint {{ $workStatus === 'new' ? 'is-active' : '' }}">
            <span class="lv12-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            </span>
            <span class="lv12-card-copy">
                <span class="lv12-card-title">Novos</span>
                <strong class="lv12-card-value">{{ number_format($this->newCount, 0, ',', '.') }}</strong>
                <span class="lv12-card-caption">Sem interação comercial</span>
            </span>
            <span class="lv12-card-chevron" aria-hidden="true">›</span>
            <span class="lv12-visually-hidden">Novos para abordar</span>
        </button>
    </div>

    <details class="lv12-extra-actions">
        <summary>Mais atalhos <span aria-hidden="true">⌄</span></summary>
        <div class="lv12-extra-list">
            <button type="button" wire:click="clearFilters">Na operação <strong>{{ number_format($this->operationalCount, 0, ',', '.') }}</strong></button>
            <button type="button" wire:click="applyQuickView('contacting')">Em contato <strong>{{ number_format($this->contactingCount, 0, ',', '.') }}</strong></button>
            <button type="button" wire:click="applyFollowUpView('unscheduled')">Aguardando sem prazo <strong>{{ number_format($this->unscheduledCount, 0, ',', '.') }}</strong></button>
            <button type="button" wire:click="applyStaleView">Contatos parados <strong>{{ number_format($this->staleCount, 0, ',', '.') }}</strong></button>
            <button type="button" wire:click="applyReprospectingReadyView">Reprospecção pronta <strong>{{ number_format($this->reprospectingReadyCount, 0, ',', '.') }}</strong></button>
            @if ($this->isCommercialManager())
                <button type="button" wire:click="applyOwnerView('mine')">Minha carteira <strong>{{ number_format($this->myLeadsCount, 0, ',', '.') }}</strong></button>
                <button type="button" wire:click="applyOwnerView('unassigned')">Sem responsável <strong>{{ number_format($this->unassignedCount, 0, ',', '.') }}</strong></button>
            @endif
        </div>
    </details>
</section>
