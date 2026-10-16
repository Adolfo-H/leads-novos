{{-- ExportControl · Campanhas UX V6.2. Navegação acessível, sem JavaScript adicional. --}}
<nav class="ecm6-nav ecm7-nav" aria-label="Navegação do módulo de campanhas">
    <a href="{{ route('campaigns.index') }}" wire:navigate class="{{ request()->routeIs('campaigns.index') ? 'is-active' : '' }}" @if(request()->routeIs('campaigns.index')) aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M8 15h4"/></svg>
        <span>Campanhas</span>
    </a>
    <a href="{{ route('campaigns.replies') }}" wire:navigate class="{{ request()->routeIs('campaigns.replies') ? 'is-active' : '' }}" @if(request()->routeIs('campaigns.replies')) aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v12H6l-3 4V6a2 2 0 0 1 1-2Z"/><path d="M8 9h8M8 13h5"/></svg>
        <span>Caixa de respostas</span>
    </a>
    <a href="{{ route('campaigns.queue') }}" wire:navigate class="{{ request()->routeIs('campaigns.queue') ? 'is-active' : '' }}" @if(request()->routeIs('campaigns.queue')) aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 6h14M7 12h14M7 18h14"/><path d="m3 6 1 1 2-2m-3 7 1 1 2-2m-3 7 1 1 2-2"/></svg>
        <span>Envios e fila</span>
    </a>
    <a href="{{ route('campaigns.settings') }}" wire:navigate class="{{ request()->routeIs('campaigns.settings') ? 'is-active' : '' }}" @if(request()->routeIs('campaigns.settings')) aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="currentColor" stroke="none"/><circle cx="16" cy="17" r="3" fill="currentColor" stroke="none"/></svg>
        <span>Configurações</span>
    </a>
</nav>
