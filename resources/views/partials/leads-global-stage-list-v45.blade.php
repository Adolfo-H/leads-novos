{{-- EC_PROSPECTOR_V49: ícones da fila padrão para etapas globais e pessoais. --}}
{{-- EC_PROSPECTOR_V45: resultados de etapa global no painel Empresas para trabalhar. --}}
{{-- EC_PROSPECTOR_V46: seleção entre páginas e paginação traduzida. --}}
{{-- EC_PROSPECTOR_V47: mesmas colunas, linhas, ações e paginação da fila normal de Leads. --}}
@php
    // EC_PROSPECTOR_V48: exatamente a mesma tabela em ambos os filtros.
    $lf45Deals = $globalHubSpotStage !== ''
        ? $this->globalHubSpotStageDeals
        : $this->personalHubSpotStageDeals;
    $lf48StageName = $globalHubSpotStage !== '' ? $globalHubSpotStage : $personalHubSpotStage;
    $lf45Portal = trim((string) config('services.hubspot.portal_id', ''));
    // Eager loading em lote: mantém performance mesmo com CNPJ e score na grade.
    $lf45Deals->getCollection()->loadMissing([
        'companies.prospectorCompany.sdrScore',
        'companies.prospectorCompany.icpScore',
        'companies.prospectorCompany.hubSpotLead',
        'companies.prospectorCompany.matrix',
    ]);
    // EC_PROSPECTOR_V52: os dois pipelines usam o mesmo recorte do filtro CRM.
    $v52FiscalCompanies = $lf45Deals->getCollection()
        ->flatMap(static fn ($deal) => $deal->companies)
        ->filter(static fn ($hub) => $hub->hasTrustedFiscalLink() && $hub->prospectorCompany !== null)
        ->map(static fn ($hub) => $hub->prospectorCompany)
        ->unique('id')->values();
    $v52StageStatuses = app(\App\Services\LeadCommercialSituationV52Service::class)
        ->forCompanies($v52FiscalCompanies);
    // V46: não desmarca outras páginas ao selecionar/desmarcar a atual.
    $lf46PageDealIds = $lf45Deals->getCollection()
        ->pluck('id')
        ->map(static fn ($id): string => (string) $id)
        ->values()->all();
@endphp

<div class="lf45-stage-inline lf47-unified-stage" aria-label="Empresas filtradas por etapa do HubSpot">
    {{-- Colunas idênticas às da listagem habitual (V13/V36.4). --}}
    <div class="lv13-list-head lf47-unified-head" aria-label="Colunas da listagem de leads">
        <label class="lv13-check lf45-check" title="Selecionar ou desmarcar somente os negócios desta página">
            <input type="checkbox" aria-label="Selecionar negócios desta página"
                   x-bind:checked="@js($lf46PageDealIds).length > 0 && @js($lf46PageDealIds).every(id => stageSelectedIds.includes(id))"
                   x-on:change="const ids = @js($lf46PageDealIds);
                                 stageSelectedIds = $event.target.checked
                                   ? [...new Set([...stageSelectedIds, ...ids])]
                                   : stageSelectedIds.filter(id => !ids.includes(id))">
        </label>
        <span>Empresa</span><span>Score / ICP</span><span>Situação comercial</span>
        <span>Etapa HubSpot</span><span>Próxima ação</span>
        <span>Responsável</span><span>Ações</span>
    </div>

    @forelse ($lf45Deals as $lf45Deal)
        @php
            // A Company Primary é preferida. Vínculo HubSpot NÃO implica CNPJ fiscal.
            $lf45Company = $lf45Deal->companies->first(
                static fn ($item): bool => (bool) ($item->pivot?->is_primary ?? false)
            ) ?? $lf45Deal->companies->first();
            $lf47Fiscal = $lf45Company?->hasTrustedFiscalLink()
                ? $lf45Company->prospectorCompany : null;
            $lf47Name = trim((string) ($lf45Company?->name ?: $lf47Fiscal?->corporate_name ?: $lf45Deal->name ?: ''));
            $lf47Name = $lf47Name !== '' ? $lf47Name : 'Negócio sem empresa associada';
            $lf47Score = $lf47Fiscal?->sdrScore;
            $lf47Icp = $lf47Fiscal?->icpScore;
            $lf47HubSpotLead = $lf47Fiscal?->hubSpotLead;
            $lf47WorkStatus = (string) ($lf47HubSpotLead?->work_status ?? '');
            $v52StageStatus = $lf47Fiscal
                ? ($v52StageStatuses[(int) $lf47Fiscal->id] ?? $lf47WorkStatus)
                : $lf47WorkStatus;
            $lf47Owner = trim((string) ($lf45Deal->owner_name ?? ''));
            $lf47Owner = $lf47Owner !== '' ? $lf47Owner : 'Não informado';
            $lf47Due = $lf47HubSpotLead?->last_task_due_at;
            $lf47ScoreValue = $lf47Score && is_numeric($lf47Score->score)
                ? min(100, max(0, (int) $lf47Score->score)) : null;
            $lf47Cnpj = $lf47Fiscal?->matrix?->cnpj;
        @endphp
        <article wire:key="lf45-deal-{{ $lf45Deal->id }}"
                 class="rf-lead-row lf45-stage-row lf47-unified-row"
                 x-bind:class="{'is-selected': stageSelectedIds.includes('{{ $lf45Deal->id }}')}">
            <div class="lv13-lead-line"
                 x-bind:class="{'is-selected': stageSelectedIds.includes('{{ $lf45Deal->id }}')}">
                <label class="lv13-check lf45-check" title="Selecionar {{ $lf47Name }}">
                    <input type="checkbox" value="{{ $lf45Deal->id }}" x-model="stageSelectedIds"
                           aria-label="Selecionar negócio de {{ $lf47Name }}">
                </label>

                <div class="lv13-company lf45-stage-company">
                    <span class="lv13-company-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($lf47Name, 0, 1)) }}</span>
                    <span class="lv13-company-body">
                        @if ($lf47Fiscal)
                            <a href="{{ route('companies.show', $lf47Fiscal) }}" wire:navigate class="lv13-company-name">{{ $lf47Name }}</a>
                        @else
                            <strong class="lv13-company-name">{{ $lf47Name }}</strong>
                        @endif
                        <small title="Negócio do HubSpot">Negócio: {{ $lf45Deal->name ?: $lf45Deal->hubspot_id }}</small>
                        @if ($lf47Fiscal)
                            <small class="is-linked">
                                {{ $lf47Cnpj ? 'CNPJ '.\App\Support\Cnpj::format((string) $lf47Cnpj) : 'Raiz CNPJ '.$lf47Fiscal->cnpj_root }}
                            </small>
                        @else
                            <small class="is-unlinked">{{ $lf45Company ? 'CNPJ não identificado' : 'Sem empresa associada ao negócio' }}</small>
                        @endif
                    </span>
                </div>

                <div class="lv13-score">
                    @if ($lf47ScoreValue !== null)
                        <strong>{{ $lf47ScoreValue }}/100</strong>
                        <span class="lv13-score-track"><i style="width: {{ $lf47ScoreValue }}%"></i></span>
                    @else
                        <strong>—</strong>
                    @endif
                    <span class="lv13-icp">ICP {{ $lf47Icp?->grade ?? '—' }}</span>
                </div>

                <div class="lv13-status">
                    @if ($v52StageStatus !== '')
                        <span class="lv13-pill lv13-status--{{ $v52StageStatus }}">{{ $this->workStatusLabel($v52StageStatus) }}</span>
                    @elseif ($lf47Fiscal)
                        <span class="lv13-pill lf47-linked-pill">CNPJ identificado</span>
                    @else
                        <span class="lv13-pill lf47-unlinked-pill">Sem vínculo fiscal</span>
                    @endif
                    <small>{{ $lf47Fiscal ? 'Empresa vinculada ao Prospector' : 'Dados comerciais do HubSpot' }}</small>
                </div>

                <div class="lv13-stage">
                    <span class="lv13-pill lf45-stage-pill">{{ $lf48StageName }}</span>
                </div>

                <div class="lv13-next">
                    @if ($lf47Due)
                        <strong>Prazo de tarefa</strong>
                        <time datetime="{{ $lf47Due->toIso8601String() }}">{{ $lf47Due->format('d/m/Y H:i') }}</time>
                    @else
                        <strong>—</strong>
                        <small>Sem prazo registrado</small>
                    @endif
                </div>

                <div class="lv13-owner">
                    <span class="lv13-owner-avatar" aria-hidden="true">{{ $lf47Owner === 'Não informado' ? '—' : mb_strtoupper(mb_substr($lf47Owner, 0, 2)) }}</span>
                    <span class="lv13-owner-name">{{ $lf47Owner }}</span>
                </div>

                <div class="lv23-actions lf45-stage-actions" aria-label="Ações comerciais de {{ $lf47Name }}">
                    @foreach ($lf45Deal->companies as $lf45Associated)
                        @if ($lf45Associated->hasTrustedFiscalLink() && $lf45Associated->prospectorCompany)
                            <a href="{{ route('companies.show', $lf45Associated->prospectorCompany) }}" wire:navigate
                               class="lf45-stage-action lf45-stage-action--dossier lf49-icon-action lf49-action-dossier" title="Abrir dossiê da empresa">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M8 3.5h7l4 4V20.5H8a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2z"/>
                                    <path d="M15 3.5v4h4M9 12h7M9 16h6"/>
                                </svg>
                                <span>Dossiê</span>
                            </a>
                        @elseif ($lf45Associated->company_id === null && $this->isCommercialManager())
                            <button type="button" class="lf45-stage-action lf43-stage-identity-actions"
                                    wire:click="openCompanyLink({{ (int) $lf45Associated->id }})"
                                    wire:loading.attr="disabled" wire:target="openCompanyLink({{ (int) $lf45Associated->id }})"
                                    title="Identificar CNPJ de {{ $lf45Associated->name ?: 'empresa sem CNPJ' }}">Identificar empresa</button>
                        @endif
                    @endforeach
                    @if (ctype_digit($lf45Portal) && ctype_digit((string) $lf45Deal->hubspot_id))
                        <a href="{{ 'https://app.hubspot.com/contacts/'.$lf45Portal.'/record/0-3/'.$lf45Deal->hubspot_id }}"
                           target="_blank" rel="noopener noreferrer" class="lf45-stage-action lf45-stage-action--hubspot lf49-icon-action lf49-action-hubspot"
                           title="Abrir negócio no HubSpot">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M13.5 5H19v5.5M19 5l-9 9"/>
                                <path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>
                            </svg>
                            <span>HubSpot</span>
                        </a>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <div class="rf-empty-state lf43-empty-state lf45-stage-empty">
            <strong>Nenhum negócio encontrado nesta etapa</strong>
            <span>Os dados são do espelho local HubSpot. Confira a sincronização se houver negócios novos.</span>
        </div>
    @endforelse

    {{-- EC_PROSPECTOR_V46: paginação em português, agora no padrão habitual da fila. --}}
    @if ($lf45Deals->hasPages())
        @php
            $lf46Current = (int) $lf45Deals->currentPage();
            $lf46Last = (int) $lf45Deals->lastPage();
            $lf46Start = max(1, min($lf46Current - 2, $lf46Last - 4));
            $lf46End = min($lf46Last, $lf46Start + 4);
        @endphp
        <nav class="rf-pagination lf45-stage-pagination lf46-stage-pagination"
             aria-label="Paginação dos negócios desta etapa">
            <div class="rf-pagination-info lf46-page-info" aria-live="polite">
                Exibindo <strong>{{ number_format($lf45Deals->firstItem() ?? 0, 0, ',', '.') }}</strong>
                a <strong>{{ number_format($lf45Deals->lastItem() ?? 0, 0, ',', '.') }}</strong>
                de <strong>{{ number_format($lf45Deals->total(), 0, ',', '.') }}</strong> negócios
            </div>
            <div class="rf-pagination-buttons lf46-page-controls" aria-label="Selecionar página">
                <button type="button" class="rf-page-btn lf46-page-nav" wire:click="previousPage('globalStageDealsPage')"
                        aria-label="Página anterior" @disabled($lf45Deals->onFirstPage())>‹</button>
                @if ($lf46Start > 1)
                    <button type="button" wire:click="gotoPage(1, 'globalStageDealsPage')"
                            class="rf-page-btn lf46-page-number" aria-label="Ir para página 1">1</button>
                    @if ($lf46Start > 2)<span class="lf46-page-ellipsis" aria-hidden="true">…</span>@endif
                @endif
                @for ($lf46Page = $lf46Start; $lf46Page <= $lf46End; $lf46Page++)
                    <button type="button" wire:key="lf47-stage-page-{{ $lf46Page }}"
                            wire:click="gotoPage({{ $lf46Page }}, 'globalStageDealsPage')"
                            class="rf-page-btn lf46-page-number {{ $lf46Page === $lf46Current ? 'is-current is-active' : '' }}"
                            aria-label="Ir para página {{ $lf46Page }}"
                            @if ($lf46Page === $lf46Current) aria-current="page" @endif>{{ $lf46Page }}</button>
                @endfor
                @if ($lf46End < $lf46Last)
                    @if ($lf46End < $lf46Last - 1)<span class="lf46-page-ellipsis" aria-hidden="true">…</span>@endif
                    <button type="button" wire:click="gotoPage({{ $lf46Last }}, 'globalStageDealsPage')"
                            class="rf-page-btn lf46-page-number" aria-label="Ir para página {{ $lf46Last }}">{{ $lf46Last }}</button>
                @endif
                <button type="button" class="rf-page-btn lf46-page-nav" wire:click="nextPage('globalStageDealsPage')"
                        aria-label="Próxima página" @disabled(! $lf45Deals->hasMorePages())>›</button>
            </div>
        </nav>
    @endif
</div>
