{{-- EC_SIMPLE_NAV_PACK20 / EC_CAMPAIGNS_CORE_V72: somente as três áreas operacionais. --}}
<nav class="ecm6-nav ecm7-nav ec69-campaign-nav ec72-core-nav" aria-label="Navegação do módulo de campanhas" data-ec-simple-nav data-ec72-core-nav="true">
    <a href="{{ route('campaigns.index') }}" wire:navigate
       class="{{ request()->routeIs('campaigns.index') ? 'is-active' : '' }}"
       @if(request()->routeIs('campaigns.index')) aria-current="page" @endif>
        <span>Campanhas</span>
    </a>
    <a href="{{ route('campaigns.replies') }}" wire:navigate
       class="{{ request()->routeIs('campaigns.replies') ? 'is-active' : '' }}"
       @if(request()->routeIs('campaigns.replies')) aria-current="page" @endif>
        <span>Respostas</span>
    </a>
    <a href="{{ route('campaigns.settings') }}" wire:navigate
       class="{{ request()->routeIs('campaigns.settings') ? 'is-active' : '' }}"
       @if(request()->routeIs('campaigns.settings')) aria-current="page" @endif>
        <span>Configurações gerais</span>
    </a>
</nav>
