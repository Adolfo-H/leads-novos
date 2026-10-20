{{-- EC_SIMPLE_NAV_PACK20: ferramentas avançadas em Configurações. --}}
<nav class="ecm6-nav ecm7-nav" aria-label="Navegação do módulo de campanhas" data-ec-simple-nav>
    <a href="{{ route('campaigns.index') }}" wire:navigate class="{{ request()->routeIs('campaigns.index') ? 'is-active' : '' }}" @if(request()->routeIs('campaigns.index')) aria-current="page" @endif>
        <span>Campanhas</span>
    </a>
    <a href="{{ route('campaigns.replies') }}" wire:navigate class="{{ request()->routeIs('campaigns.replies') ? 'is-active' : '' }}" @if(request()->routeIs('campaigns.replies')) aria-current="page" @endif>
        <span>Respostas</span>
    </a>
    <a href="{{ route('campaigns.settings') }}" wire:navigate class="{{ request()->routeIs('campaigns.settings') || request()->routeIs('campaigns.queue') || request()->routeIs('campaigns.production.*') || request()->routeIs('campaigns.intelligence.*') ? 'is-active' : '' }}" @if(request()->routeIs('campaigns.settings') || request()->routeIs('campaigns.queue') || request()->routeIs('campaigns.production.*') || request()->routeIs('campaigns.intelligence.*')) aria-current="page" @endif>
        <span>Configurações gerais</span>
    </a>
</nav>
