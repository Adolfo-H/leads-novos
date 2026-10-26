{{-- EC_PROSPECTOR_V54: mesma grade visual de Leads, mas uma linha por tarefa HubSpot.
     A autorização vem de LeadDueTaskQueueV54Service; não inferir CNPJ por Deal. --}}
@php
    $v54Page = $this->dueHubSpotTasks;
    $v54Records = $v54Page->getCollection();
    $v54Records->loadMissing([
        'companies.prospectorCompany.matrix',
        'companies.prospectorCompany.sdrScore',
        'companies.prospectorCompany.icpScore',
        'companies.prospectorCompany.leadWorkState',
        'deals.companies.prospectorCompany.matrix',
        'deals.companies.prospectorCompany.sdrScore',
        'deals.companies.prospectorCompany.icpScore',
        'deals.companies.prospectorCompany.leadWorkState',
    ]);
    $v54PageIds = $v54Records->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all();
    $v54Portal = trim((string) config('services.hubspot.portal_id', ''));
@endphp

<div class="lf47-unified-stage lf54-task-queue" aria-label="Tarefas individuais do HubSpot por vencimento">
    <div class="lv13-list-head lf47-unified-head" aria-label="Colunas da listagem de tarefas">
        <label class="lv13-check" title="Selecionar tarefas desta página">
            <input type="checkbox" aria-label="Selecionar tarefas desta página"
                   x-bind:checked="@js($v54PageIds).length > 0 && @js($v54PageIds).every(id => dueSelectedIds.includes(id))"
                   x-on:change="const ids = @js($v54PageIds);
                       dueSelectedIds = $event.target.checked
                         ? [...new Set([...dueSelectedIds, ...ids])]
                         : dueSelectedIds.filter(id => !ids.includes(id))">
        </label>
        <span>Empresa / tarefa</span><span>Score / ICP</span><span>Situação comercial</span>
        <span>Etapa HubSpot</span><span>Próxima ação</span><span>Responsável</span><span>Ações</span>
    </div>

    @forelse ($v54Page as $v54Task)
        @php
            $v54Associated = $v54Task->companies->first(static fn ($h): bool => $h->hasTrustedFiscalLink())
                ?? $v54Task->companies->first();
            $v54Deal = $v54Task->deals->first();
            if (! $v54Associated) {
                $v54Associated = $v54Deal?->companies->first(static fn ($h): bool => (bool) ($h->pivot?->is_primary ?? false))
                    ?? $v54Deal?->companies->first();
            }
            $v54Fiscal = $v54Associated?->hasTrustedFiscalLink() ? $v54Associated->prospectorCompany : null;
            $v54Name = trim((string) ($v54Fiscal?->corporate_name ?: $v54Associated?->name ?: ''));
            $v54Name = $v54Name !== '' ? $v54Name : 'Tarefa sem empresa vinculada';
            $v54Score = $v54Fiscal?->sdrScore;
            $v54Icp = $v54Fiscal?->icpScore;
            $v54ScoreNum = $v54Score && is_numeric($v54Score->score)
                ? max(0, min(100, (int) $v54Score->score)) : null;
            $v54DueAt = $v54Task->due_at;
            $v54Expired = $v54DueAt !== null && $v54DueAt->lte(now());
            $v54Title = trim((string) $v54Task->title) ?: 'Tarefa sem título';
            $v54Owner = trim((string) $v54Task->assigned_to);
            $v54Owner = $v54Owner !== '' ? $v54Owner : (auth()->user()?->name ?? 'Responsável não identificado');
            $v54Cnpj = $v54Fiscal?->matrix?->cnpj;
            $v54CanViewFiscal = $v54Fiscal && ($this->isCommercialManager()
                || (int) ($v54Fiscal->leadWorkState?->assigned_user_id ?? 0) === (int) auth()->id());
        @endphp
        <article wire:key="v54-hubspot-task-{{ $v54Task->id }}"
                 class="rf-lead-row lf47-unified-row lf54-task-row"
                 x-bind:class="{'is-selected': dueSelectedIds.includes('{{ $v54Task->id }}')}">
            <div class="lv13-lead-line" x-bind:class="{'is-selected': dueSelectedIds.includes('{{ $v54Task->id }}')}">
                <label class="lv13-check" title="Selecionar tarefa {{ $v54Title }}">
                    <input type="checkbox" value="{{ $v54Task->id }}" x-model="dueSelectedIds"
                           aria-label="Selecionar tarefa {{ $v54Title }}">
                </label>
                <div class="lv13-company">
                    <span class="lv13-company-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($v54Name, 0, 1)) }}</span>
                    <span class="lv13-company-body">
                        @if ($v54CanViewFiscal)
                            <a class="lv13-company-name" href="{{ route('companies.show', $v54Fiscal) }}" wire:navigate>{{ $v54Name }}</a>
                        @else
                            <strong class="lv13-company-name">{{ $v54Name }}</strong>
                        @endif
                        <small title="Tarefa do HubSpot">{{ \Illuminate\Support\Str::limit($v54Title, 64) }}</small>
                        @if ($v54Fiscal)
                            <small>CNPJ {{ $v54Cnpj ? \App\Support\Cnpj::format((string) $v54Cnpj) : $v54Fiscal->cnpj_root }}</small>
                        @else
                            <small>HubSpot · sem CNPJ fiscal confirmado</small>
                        @endif
                    </span>
                </div>
                <div class="lv13-score">
                    @if ($v54ScoreNum !== null)
                        <strong>{{ $v54ScoreNum }}/100</strong>
                        <span class="lv13-score-track"><i style="width: {{ $v54ScoreNum }}%"></i></span>
                    @else
                        <strong>—</strong>
                    @endif
                    <span class="lv13-icp">ICP {{ $v54Icp?->grade ?? '—' }}</span>
                </div>
                <div class="lv13-status">
                    <span class="lv13-pill {{ $v54Expired ? 'lv13-status--refused' : 'lv13-status--waiting' }}">
                        {{ $v54Expired ? 'Atrasada' : 'Vence hoje' }}
                    </span>
                    <small>{{ $v54Expired ? 'Horário já ultrapassado' : 'Prazo ainda não vencido' }}</small>
                </div>
                <div class="lv13-stage">
                    @if ($v54Deal)
                        <span class="lv13-pill lf45-stage-pill">{{ $v54Deal->stage_label ?: 'Negócio HubSpot' }}</span>
                    @else
                        <small>Sem negócio associado</small>
                    @endif
                </div>
                <div class="lv13-next">
                    <strong title="{{ $v54Title }}">{{ \Illuminate\Support\Str::limit($v54Title, 64) }}</strong>
                    @if ($v54DueAt)
                        <time datetime="{{ $v54DueAt->toIso8601String() }}">{{ $v54DueAt->format('d/m/Y H:i') }}</time>
                    @endif
                </div>
                <div class="lv13-owner">
                    <span class="lv13-owner-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($v54Owner, 0, 2)) }}</span>
                    <span class="lv13-owner-name">{{ $v54Owner }}</span>
                </div>
                <div class="lv23-actions lf45-stage-actions" aria-label="Ações da tarefa {{ $v54Title }}">
                    @if ($v54CanViewFiscal)
                        <a href="{{ route('companies.show', $v54Fiscal) }}" wire:navigate
                           class="lf45-stage-action lf49-icon-action lf49-action-dossier" title="Abrir dossiê da empresa">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3.5h7l4 4V20.5H8a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2z"/><path d="M15 3.5v4h4M9 12h7M9 16h6"/></svg>
                            <span>Dossiê</span>
                        </a>
                    @endif
                    @if ($v54Deal && ctype_digit($v54Portal) && ctype_digit((string) $v54Deal->hubspot_id))
                        <a href="{{ 'https://app.hubspot.com/contacts/'.$v54Portal.'/record/0-3/'.$v54Deal->hubspot_id }}"
                           target="_blank" rel="noopener noreferrer"
                           class="lf45-stage-action lf49-icon-action lf49-action-hubspot" title="Abrir negócio vinculado no HubSpot">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 5H19v5.5M19 5l-9 9"/><path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/></svg>
                            <span>HubSpot</span>
                        </a>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <div class="rf-empty-state lf43-empty-state lf45-stage-empty">
            <strong>Nenhuma tarefa aberta nesse período</strong>
            <span>O recorte usa horário de Brasília e tarefas atribuídas ao seu usuário no espelho local do HubSpot.</span>
        </div>
    @endforelse

    @if ($v54Page->hasPages())
        @php
            $v54Current = (int) $v54Page->currentPage();
            $v54Last = (int) $v54Page->lastPage();
            $v54Start = max(1, min($v54Current - 2, $v54Last - 4));
            $v54End = min($v54Last, $v54Start + 4);
        @endphp
        <nav class="rf-pagination lf45-stage-pagination lf46-stage-pagination"
             aria-label="Paginação das tarefas HubSpot">
            <div class="rf-pagination-info" aria-live="polite">
                Exibindo <strong>{{ number_format($v54Page->firstItem() ?? 0, 0, ',', '.') }}</strong>
                a <strong>{{ number_format($v54Page->lastItem() ?? 0, 0, ',', '.') }}</strong>
                de <strong>{{ number_format($v54Page->total(), 0, ',', '.') }}</strong> tarefas
            </div>
            <div class="rf-pagination-buttons" aria-label="Selecionar página de tarefas">
                <button type="button" class="rf-page-btn" wire:click="previousPage('dueTaskPage')"
                        @disabled($v54Page->onFirstPage()) aria-label="Página anterior">‹</button>
                @for ($v54Number = $v54Start; $v54Number <= $v54End; $v54Number++)
                    <button type="button" wire:key="v54-task-page-{{ $v54Number }}"
                            wire:click="gotoPage({{ $v54Number }}, 'dueTaskPage')"
                            class="rf-page-btn {{ $v54Number === $v54Current ? 'is-active' : '' }}"
                            @if ($v54Number === $v54Current) aria-current="page" @endif>{{ $v54Number }}</button>
                @endfor
                <button type="button" class="rf-page-btn" wire:click="nextPage('dueTaskPage')"
                        @disabled(! $v54Page->hasMorePages()) aria-label="Próxima página">›</button>
            </div>
        </nav>
    @endif
</div>
