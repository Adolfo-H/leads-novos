@props(['mobile' => false])

@php
    /**
     * Um único campo de busca no layout compartilhado.
     *
     * Dashboard/Leads/Empresas mantêm seus respectivos listeners Livewire.
     * Nas demais telas, a busca abre o diretório autorizado via GET.
     * Telas novas herdam automaticamente esse fallback.
     */
    $manager = auth()->user()?->isCommercialManager() === true;

    $searchEvent = match (true) {
        request()->routeIs('dashboard') && $manager => 'dashboard-global-search',
        request()->routeIs('leads.index') => 'leads-topbar-search',
        request()->routeIs('companies.index') && $manager => 'companies-topbar-search',
        default => null,
    };

    $destination = match (true) {
        request()->routeIs('leads.management') => 'leads.index',
        request()->routeIs('contacts.*') && $manager => 'contacts.index',
        $manager => 'companies.index',
        default => 'leads.index',
    };

    $queryKey = $destination === 'contacts.index' ? 'q' : 'search';
    $queryValue = request()->query($queryKey, '');
    $initialSearch = is_string($queryValue) ? mb_substr($queryValue, 0, 120) : '';
@endphp

<form
    class="ds-v5-topbar-search ec-global-search {{ $mobile ? 'ec-global-search--mobile' : 'ec-global-search--desktop' }}"
    role="search"
    method="GET"
    action="{{ route($destination) }}"
    data-ec-global-search="{{ $mobile ? 'mobile' : 'desktop' }}"
    @if (! $mobile && request()->routeIs('leads.management'))
        data-ecmg-topbar-search="v255"
    @endif
    @if (! $mobile && request()->routeIs('companies.index'))
        data-eccl-topbar-search="v261"
    @endif
    x-data="{ term: @js($initialSearch) }"
    @if ($searchEvent !== null)
        x-on:submit.prevent="window.Livewire?.dispatch(@js($searchEvent), { term: term.trim().slice(0, 120) })"
    @endif
>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <circle cx="11" cy="11" r="7" />
        <path d="m16 16 5 5" />
    </svg>

    <input
        type="search"
        name="{{ $queryKey }}"
        x-model="term"
        maxlength="120"
        autocomplete="off"
        aria-label="Buscar empresas por nome, CNPJ ou razão social"
        placeholder="Buscar empresas, CNPJ..."
    >

    <kbd aria-hidden="true">Enter</kbd>
</form>
