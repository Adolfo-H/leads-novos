{{-- PACOTE 42: CRM sem CNPJ com busca própria e vinculação de registros fora da página inicial. --}}
@php
    $lf36CrmResults = $this->hubSpotOnlyLeads;
    $lf36CrmPage = $lf36CrmResults->getCollection();
    $lf36WithDeals = $lf36CrmPage->filter(static fn ($company) => $company->deals->isNotEmpty())->count();
    $lf36WithContacts = $lf36CrmPage->filter(static fn ($company) => $company->contacts->isNotEmpty())->count();
@endphp
<section class="lf36-crm-view lf42-crm-view" aria-label="CRM sem identificação fiscal">
    <div class="lf36-crm-toolbar">
        <div>
            <h2>Empresas somente no CRM</h2>
            <p>Localize registros do HubSpot que ainda não possuem associação fiscal confirmada.</p>
        </div>
        <button type="button" class="lf36-primary" wire:click="applyHubSpotOnlyView">Voltar à fila</button>
    </div>
    <div class="lf42-crm-find">
        <label for="lf42-crm-search">Localizar empresa para identificar</label>
        <div class="lf42-crm-search-field">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
            <input id="lf42-crm-search" type="search" wire:model.live.debounce.350ms="search"
                   placeholder="Razão social, nome, domínio ou ID da empresa no HubSpot..."
                   autocomplete="off" />
            @if (trim($search) !== '')
                <button type="button" wire:click="$set('search', '')" aria-label="Limpar busca">Limpar</button>
            @endif
        </div>
        <p>O registro pode estar fora da primeira página ou não possuir negócios. Encontre-o abaixo e use <strong>Identificar empresa</strong>. Se o CNPJ ainda não estiver no Prospector, você poderá consultar a Receita no diálogo de identificação.</p>
    </div>
    @if ($commercialActionMessage !== '')
        <div class="lf36-alert is-ok" role="status">{{ $commercialActionMessage }}</div>
    @endif
    @if ($commercialActionError !== '')
        <div class="lf36-alert is-error" role="alert">{{ $commercialActionError }}</div>
    @endif
    <div class="lf36-crm-kpis">
        <div><span>Registros para revisar</span><strong>{{ number_format($lf36CrmResults->total(), 0, ',', '.') }}</strong><small>Total do recorte atual</small></div>
        <div><span>Com negócios</span><strong>{{ $lf36WithDeals }}</strong><small>Na página atual ({{ $lf36CrmPage->count() }} registros)</small></div>
        <div><span>Com contatos</span><strong>{{ $lf36WithContacts }}</strong><small>Na página atual ({{ $lf36CrmPage->count() }} registros)</small></div>
    </div>
    @include('partials.hubspot-unmatched-leads')
    <p class="lf36-crm-disclaimer"><strong>Vinculação segura:</strong> nome e domínio são apenas referências para pesquisa. A associação fiscal exige seleção e confirmação explícitas; o Prospector não inventa CNPJ, ICP ou score.</p>
</section>
