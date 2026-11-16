{{-- V15: indicadores que reutilizam as mesmas regras da listagem, sem séries fictícias. --}}
<section class="lv15-summary" aria-label="Atalhos da operação comercial">
    <div class="lv15-card-grid">
        <button type="button"
                wire:click="applyV15TopCard('due')"
                wire:loading.attr="disabled"
                class="lv15-card lv15-card--due {{ $dueActionOnly ? 'is-active' : '' }}"
                title="Tarefas ainda abertas, vencidas ou previstas para hoje">
            <span class="lv15-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="5" width="18" height="16" rx="2"/>
                    <path d="M7 3v4M17 3v4M3 10h18M12 13v4M12 19h.01"/>
                </svg>
            </span>
            <span class="lv15-card-body">
                <span class="lv15-card-label">Atrasados / Vencem hoje</span>
                <strong class="lv15-card-value">{{ number_format($this->v15DueCount, 0, ',', '.') }}</strong>
                @if (($this->hubSpotRealtimeHealth['status'] ?? 'healthy') !== 'healthy')
                    <small class="lv18-task-stale" title="O espelho do HubSpot pode não refletir tarefas concluídas online.">
                        Atenção: integração com alerta. Quantidade baseada nos dados locais.
                    </small>
                @endif
                <small class="lv17-due-caption">
                    {{ number_format($this->v17DueBreakdown['overdue'], 0, ',', '.') }} atrasadas
                    · {{ number_format($this->v17DueBreakdown['today'], 0, ',', '.') }} vencem hoje
                </small>
            </span>
            <span class="lv15-card-arrow" aria-hidden="true">›</span>
        </button>

        <button type="button"
                wire:click="applyV15TopCard('mine')"
                wire:loading.attr="disabled"
                class="lv15-card lv15-card--mine {{ $owner === 'mine' ? 'is-active' : '' }}"
                title="Leads atribuídos a você como responsável">
            <span class="lv15-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 9h4M19 7v4"/>
                </svg>
            </span>
            <span class="lv15-card-body">
                <span class="lv15-card-label">Minha fila</span>
                <strong class="lv15-card-value">{{ number_format($this->myLeadsCount, 0, ',', '.') }}</strong>
                <small>Leads sob minha responsabilidade</small>
            </span>
            <span class="lv15-card-arrow" aria-hidden="true">›</span>
        </button>

        <button type="button"
                wire:click="applyV15TopCard('new')"
                wire:loading.attr="disabled"
                class="lv15-card lv15-card--new {{ $crmSituation === 'new' ? 'is-active' : '' }}"
                title="Leads que ainda não têm presença ou atividade comercial no HubSpot">
            <span class="lv15-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
            </span>
            <span class="lv15-card-body">
                <span class="lv15-card-label">Novos para prospectar</span>
                <strong class="lv15-card-value">{{ number_format($this->v15NewCount, 0, ',', '.') }}</strong>
                <small>Sem presença comercial no HubSpot</small>
            </span>
            <span class="lv15-card-arrow" aria-hidden="true">›</span>
        </button>
    </div>
</section>
