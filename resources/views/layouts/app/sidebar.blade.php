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
                'imports.*',
                'leads.*',
                'profile.edit',
                'security.edit',
                'appearance.edit'
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
            @if (auth()->user()?->isCommercialManager())

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
            request()->routeIs('dashboard') => 'Dashboard',
            request()->routeIs('leads.management') => 'Gestão comercial',
            request()->routeIs('leads.*') => 'Leads',
            request()->routeIs('prospecting.*') => 'Motor de prospecção',
            request()->routeIs('companies.*') => 'Empresas',
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
    >

        <div class="ec-topbar-context">

            <span class="ec-topbar-eyebrow">
                ExportControl
            </span>

            <span class="ec-topbar-title">
                {{ $ecPageLabel }}
            </span>

        </div>

                    @if (request()->routeIs('dashboard') && auth()->user()?->isCommercialManager())
                <form
                    class="ds-v5-topbar-search"
                    role="search"
                    x-data="{ term: '' }"
                    x-on:submit.prevent="window.Livewire?.dispatch('dashboard-global-search', { term: term })"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m16 16 5 5"></path>
                    </svg>
                    <input x-model="term" type="search" maxlength="120"
                        aria-label="Pesquisar empresas por nome ou CNPJ"
                        placeholder="Buscar empresas, CNPJ...">
                    <kbd>Enter</kbd>
                </form>
            @endif

<flux:spacer />

        <div class="ec-topbar-tools">

            {{-- USUARIO --}}
            <flux:dropdown
                position="bottom"
                align="end"
            >

                <flux:button
                    variant="ghost"
                    class="ec-topbar-profile-trigger"
                >

                    <span class="ec-topbar-avatar">
                        {{
                            auth()
                                ->user()
                                ->initials()
                        }}
                    </span>

                    <span class="ec-topbar-profile-name">
                        {{
                            auth()
                                ->user()
                                ->name
                        }}
                    </span>

                    <svg
                        class="ec-topbar-profile-chevron"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        aria-hidden="true"
                    >
                        <path
                            d="m8 10 4 4 4-4"
                        />
                    </svg>

                </flux:button>


                <flux:menu>

                    <div
                        class="
                            flex items-center gap-2
                            px-2 py-2 text-start
                        "
                    >

                        <flux:avatar
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                        />

                        <div
                            class="
                                grid flex-1
                                text-start text-sm
                                leading-tight
                            "
                        >

                            <flux:heading class="truncate">
                                {{ auth()->user()->name }}
                            </flux:heading>

                            <flux:text class="truncate">
                                {{ auth()->user()->email }}
                            </flux:text>

                        </div>

                    </div>


                    <flux:menu.separator />


                    <flux:menu.item
                        :href="route('profile.edit')"
                        icon="cog"
                        wire:navigate
                    >
                        Configurações
                    </flux:menu.item>


                    <form
                        method="POST"
                        action="{{ route('logout') }}"
                        class="w-full"
                    >
                        @csrf

                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            Sair
                        </flux:menu.item>

                    </form>

                </flux:menu>

            </flux:dropdown>

        </div>


    </flux:header>


    {{-- TOPBAR MOBILE --}}
    <flux:header
        class="ec-topbar lg:hidden"
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

        <flux:dropdown
            position="bottom"
            align="end"
        >

            <flux:profile
                :initials="auth()->user()->initials()"
                icon-trailing="chevron-down"
            />

            <flux:menu>

                <div
                    class="
                        flex items-center gap-2
                        px-2 py-2 text-start
                    "
                >

                    <flux:avatar
                        :name="auth()->user()->name"
                        :initials="auth()->user()->initials()"
                    />

                    <div
                        class="
                            grid flex-1
                            text-start text-sm
                            leading-tight
                        "
                    >

                        <flux:heading class="truncate">
                            {{ auth()->user()->name }}
                        </flux:heading>

                        <flux:text class="truncate">
                            {{ auth()->user()->email }}
                        </flux:text>

                    </div>

                </div>

                <flux:menu.separator />

                <flux:menu.item
                    :href="route('profile.edit')"
                    icon="cog"
                    wire:navigate
                >
                    Configurações
                </flux:menu.item>

                <form
                    method="POST"
                    action="{{ route('logout') }}"
                    class="w-full"
                >
                    @csrf

                    <flux:menu.item
                        as="button"
                        type="submit"
                        icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer"
                    >
                        Sair
                    </flux:menu.item>

                </form>

            </flux:menu>

        </flux:dropdown>

    </flux:header>


    {{ $slot }}


    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts

</body>

</html>
