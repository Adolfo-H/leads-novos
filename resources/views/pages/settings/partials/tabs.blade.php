{{-- CONFIGURACOES_FIGMA_V63: abas com rotas existentes. --}}
<nav class="ec-cfg-tabs" aria-label="Seções das configurações da conta">
    @foreach ([
        ['profile.edit', 'Perfil'],
        ['security.edit', 'Segurança'],
        ['appearance.edit', 'Aparência'],
    ] as [$routeName, $tabName])
        <a href="{{ route($routeName) }}"
           wire:navigate
           @if(request()->routeIs($routeName)) aria-current="page" @endif
           class="ec-cfg-tab {{ request()->routeIs($routeName) ? 'is-active' : '' }}">
            {{ $tabName }}
        </a>
    @endforeach
</nav>
