<!DOCTYPE html>

<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"

>

<head>
    @include('partials.head')
</head>

<body
    class="
        ec-app-body
        {{
            request()->routeIs(
                'dashboard',
                'prospecting.*',
                'companies.*',
                'contacts.*',
                'campaigns.*',
                'imports.*',
                'leads.*',
                'profile.edit',
                'security.edit',
                'appearance.edit',
                'access.requests.*'
            )
                ? 'ec-dashboard-mode'
                : ''
        }}
    "
>

    {{-- SIDEBAR DESKTOP / MOBILE --}}
    <flux:sidebar
        sticky
        collapsible="mobile"
        class="ec-sidebar"
    >

        <flux:sidebar.header class="ec-sidebar-header">

            <x-app-logo
                :sidebar="true"
                href="{{ route('dashboard') }}"
                wire:navigate
            />

            <flux:sidebar.collapse
                class="lg:hidden"
            />

        </flux:sidebar.header>

        <flux:sidebar.nav>

            {{-- AREA PRINCIPAL --}}
            <flux:sidebar.group
                heading="Principal"
                class="ec-sidebar-group grid"
            >

                <flux:sidebar.item
                    icon="home"
                    :href="route('dashboard')"
                    :current="request()->routeIs('dashboard')"
                    wire:navigate
                    class="ec-nav-item"
                >
                    Dashboard
                </flux:sidebar.item>

                <flux:sidebar.item
                    icon="user-group"
                    :href="route('leads.index')"
                    :current="request()->routeIs('leads.index')"
                    wire:navigate
                    class="ec-nav-item"
                >
                    Leads
                </flux:sidebar.item>

                @if (auth()->user()?->isCommercialManager())

                    <flux:sidebar.item
                        icon="chart-bar"
                        :href="route('leads.management')"
                        :current="request()->routeIs('leads.management')"
                        wire:navigate
                        class="ec-nav-item"
                    >
                        Gestão Comercial
                    </flux:sidebar.item>

                @endif

            </flux:sidebar.group>

            {{-- RECURSOS EXCLUSIVOS DA GESTAO --}}
            @if (auth()->user()?->isCommercialManager() || auth()->user()?->isCommercialSeller())

                <flux:sidebar.group
                    heading="Prospecção"
                    class="ec-sidebar-group grid"
                >

                    <flux:sidebar.item
                        icon="magnifying-glass"
                        :href="route('prospecting.index')"
                        :current="request()->routeIs('prospecting.*')"
                        wire:navigate
                        class="ec-nav-item"
                    >
                        Motor de Prospecção
                    </flux:sidebar.item>

                    <flux:sidebar.item
                        icon="building-office"
                        :href="route('companies.index')"
                        :current="request()->routeIs('companies.*')"
                        wire:navigate
                        class="ec-nav-item"
                    >
                        Empresas
                    </flux:sidebar.item>

                </flux:sidebar.group>

                <flux:sidebar.group
                    heading="Dados"
                    class="ec-sidebar-group grid"
                >

                    <flux:sidebar.item
                        icon="arrow-up-tray"
                        :href="route('imports.index')"
                        :current="request()->routeIs('imports.*')"
                        wire:navigate
                        class="ec-nav-item"
                    >
                        Importações
                    </flux:sidebar.item>

                </flux:sidebar.group>

            @endif


            {{-- CENTRAL DE CONTATOS EXPORTCONTROL --}}
            @if (auth()->user()?->isCommercialManager() || auth()->user()?->isCommercialSeller())
                <flux:sidebar.group heading="Relacionamento" class="ec-sidebar-group grid">
                    <flux:sidebar.item
                        icon="user-group"
                        :href="route('contacts.index')"
                        :current="request()->routeIs('contacts.*')"
                        wire:navigate
                        class="ec-nav-item"
                    >
                        Contatos
                    </flux:sidebar.item>
                    {{-- EC_EMAIL_CAMPAIGNS_V1_NAV --}}
                    <flux:sidebar.item
                        icon="envelope"
                        :href="route('campaigns.index')"
                        :current="request()->routeIs('campaigns.*')"
                        wire:navigate
                        class="ec-nav-item"
                    >
                        Campanhas de E-mail
                    </flux:sidebar.item>
                </flux:sidebar.group>
            @endif

            {{-- EC_ACCESS_V24 - administrar novos acessos --}}
            @if (\App\Http\Controllers\Access\AccessAdminController::isApprover(auth()->user()))
                <flux:sidebar.group heading="Administração" class="ec-sidebar-group grid">
                    <flux:sidebar.item icon="user-plus"
                        :href="route('access.requests.index')"
                        :current="request()->routeIs('access.requests.*')"
                        wire:navigate class="ec-nav-item">
                        Solicitações de acesso
                    </flux:sidebar.item>
                </flux:sidebar.group>
            @endif
        </flux:sidebar.nav>

        <flux:spacer />




        <div class="ec-sidebar-footer">






            <flux:sidebar.nav>

                <flux:sidebar.item
                    icon="cog-6-tooth"
                    :href="route('profile.edit')"
                    :current="request()->routeIs('profile.edit')"
                    wire:navigate
                    class="ec-nav-item"
                >
                    Configurações
                </flux:sidebar.item>

            </flux:sidebar.nav>


        </div>

    </flux:sidebar>


    @php
        $ecPageLabel = match (true) {
            request()->routeIs('access.requests.*') => 'Solicitações de acesso',
            request()->routeIs('dashboard') => 'Dashboard',
            request()->routeIs('leads.management') => 'Gestão comercial',
            request()->routeIs('leads.*') => 'Leads',
            request()->routeIs('prospecting.*') => 'Motor de prospecção',
            request()->routeIs('companies.*') => 'Empresas',
            request()->routeIs('contacts.*') => 'Contatos',
            request()->routeIs('campaigns.*') => 'Campanhas',
            request()->routeIs('imports.*') => 'Importações',
            request()->routeIs(
                'profile.edit',
                'security.edit',
                'appearance.edit'
            ) => 'Configurações',
            default => 'Prospector',
        };
    @endphp

    {{-- TOPBAR DESKTOP --}}
    <flux:header
        class="ec-topbar hidden lg:flex"
        data-ec-prospecting-topbar="{{ request()->routeIs('prospecting.*') ? 'v636' : '' }}"
    >

        <div class="ec-topbar-context">

            @unless (request()->routeIs('leads.index', 'leads.management', 'companies.show', 'prospecting.*', 'access.requests.*'))
                <span class="ec-topbar-eyebrow">
                    ExportControl
                </span>
            @endunless

            <span class="ec-topbar-title">
                {{ $ecPageLabel }}
            </span>

        </div>

                    {{-- EC_GLOBAL_COMPANY_SEARCH_V1: único campo compartilhado (Desktop). --}}
        <x-global-company-search />

        <flux:spacer />

        <div class="ec-topbar-tools">

            {{-- USUARIO --}}
            {{-- DOSSIE_UI_HUBSPOT_V592: menu desktop independente do tema escuro do Flux. --}}
<div class="e592-account-root"
     x-data="{ open: false }"
     x-on:keydown.escape.window="open = false"
     x-on:click.outside="open = false">
    <button type="button"
            class="e592-account-trigger"
            aria-label="Abrir menu do usuário"
            aria-haspopup="menu"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            x-on:click="open = !open">
        <span class="e592-account-avatar">{{ auth()->user()->initials() }}</span>
        <span class="e592-account-name">{{ auth()->user()->name }}</span>
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div class="e592-account-panel ec-topbar-account-menu"
         x-cloak
         x-show="open"
         x-transition.opacity.duration.120ms
         role="menu"
         aria-label="Opções da conta">
        <div class="e592-account-header ec-profile-menu-header">
            <span class="e592-account-avatar e592-account-avatar--large">{{ auth()->user()->initials() }}</span>
            <span class="e592-account-identity">
                <strong class="ec-profile-menu-name">{{ auth()->user()->name }}</strong>
                <small class="ec-profile-menu-email">{{ auth()->user()->email }}</small>
            </span>
        </div>
        <div class="e592-account-divider" aria-hidden="true"></div>
        <a href="{{ route('profile.edit') }}" wire:navigate
           class="e592-account-item ec-profile-menu-item"
           x-on:click="open = false" role="menuitem">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="M19.4 15a2 2 0 0 0 .4 2.2l.1.1-2 2-.1-.1a2 2 0 0 0-2.2-.4 2 2 0 0 0-1.3 1.8V21h-2.8v-.4a2 2 0 0 0-1.3-1.8 2 2 0 0 0-2.2.4l-.1.1-2-2 .1-.1A2 2 0 0 0 6.4 15a2 2 0 0 0-1.8-1.3H4v-2.8h.6A2 2 0 0 0 6.4 9.6 2 2 0 0 0 6 7.4l-.1-.1 2-2 .1.1A2 2 0 0 0 10.2 5a2 2 0 0 0 1.3-1.8V3h2.8v.2A2 2 0 0 0 15.6 5a2 2 0 0 0 2.2.4l.1-.1 2 2-.1.1a2 2 0 0 0-.4 2.2 2 2 0 0 0 1.8 1.3h.3v2.8h-.3a2 2 0 0 0-1.8 1.3Z"/></svg>
            <span>Configurações</span>
        </a>
        <form method="POST" action="{{ route('logout') }}" class="e592-account-logout-form">
            @csrf
            <button type="submit" class="e592-account-item e592-account-signout ec-profile-menu-item ec-profile-menu-logout" role="menuitem" data-test="logout-button">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                <span>Sair</span>
            </button>
        </form>
    </div>
</div>

        </div>


    </flux:header>


        <!-- EC_TOPBAR_V31 -->
    {{-- DOSSIE_UI_HUBSPOT_V591: layout claro também no mobile --}}
{{-- TOPBAR MOBILE --}}
    <flux:header
        class="ec-topbar lg:hidden"
        data-ec-prospecting-topbar="{{ request()->routeIs('prospecting.*') ? 'v636' : '' }}"
    >

        <flux:sidebar.toggle
            icon="bars-2"
            inset="left"
        />

        <div class="ml-2">

            <span class="ec-topbar-title">
                {{ $ecPageLabel }}
            </span>

        </div>

        <flux:spacer />

        {{-- DOSSIE_UI_HUBSPOT_V592: o mesmo menu claro no celular. --}}
<div class="e592-account-root e592-account-root--mobile"
     x-data="{ open: false }"
     x-on:keydown.escape.window="open = false"
     x-on:click.outside="open = false">
    <button type="button"
            class="e592-account-trigger e592-mobile-profile-trigger"
            aria-label="Abrir menu do usuário"
            aria-haspopup="menu"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            x-on:click="open = !open">
        <span class="e592-account-avatar">{{ auth()->user()->initials() }}</span>
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div class="e592-account-panel ec-topbar-account-menu e591-account-menu-mobile"
         x-cloak
         x-show="open"
         x-transition.opacity.duration.120ms
         role="menu"
         aria-label="Opções da conta">
        <div class="e592-account-header ec-profile-menu-header">
            <span class="e592-account-avatar e592-account-avatar--large">{{ auth()->user()->initials() }}</span>
            <span class="e592-account-identity">
                <strong class="ec-profile-menu-name">{{ auth()->user()->name }}</strong>
                <small class="ec-profile-menu-email">{{ auth()->user()->email }}</small>
            </span>
        </div>
        <div class="e592-account-divider" aria-hidden="true"></div>
        <a href="{{ route('profile.edit') }}" wire:navigate class="e592-account-item ec-profile-menu-item" role="menuitem" x-on:click="open = false">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.5 1.5M17.6 17.6l1.5 1.5M19.1 4.9l-1.5 1.5M6.4 17.6l-1.5 1.5"/></svg>
            <span>Configurações</span>
        </a>
        <form method="POST" action="{{ route('logout') }}" class="e592-account-logout-form">
            @csrf
            <button type="submit" class="e592-account-item e592-account-signout ec-profile-menu-item ec-profile-menu-logout" role="menuitem" data-test="logout-button">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                <span>Sair</span>
            </button>
        </form>
    </div>
</div>

    </flux:header>

    {{-- EC_GLOBAL_COMPANY_SEARCH_V1: busca disponível também no mobile. --}}
    <div class="ec-global-search-mobile-row lg:hidden">
        <x-global-company-search :mobile="true" />
    </div>



    {{ $slot }}


    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts

</body>

</html>
