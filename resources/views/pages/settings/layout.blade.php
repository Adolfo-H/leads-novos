<div class="ec-settings-layout">

    <nav
        class="ec-settings-nav"
        aria-label="Configurações"
    >

        <a
            href="{{ route('profile.edit') }}"
            wire:navigate
            class="{{
                request()->routeIs('profile.edit')
                    ? 'is-active'
                    : ''
            }}"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
            >
                <circle
                    cx="12"
                    cy="8"
                    r="3"
                />

                <path
                    d="
                        M5 21
                        c0-4.5
                        2.8-7
                        7-7
                        s7 2.5
                        7 7
                    "
                />
            </svg>

            <span>
                Perfil
            </span>
        </a>


        <a
            href="{{ route('security.edit') }}"
            wire:navigate
            class="{{
                request()->routeIs('security.edit')
                    ? 'is-active'
                    : ''
            }}"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
            >
                <path
                    d="
                        M12 3
                        19 6
                        v5
                        c0 5-3 8-7 10
                        -4-2-7-5-7-10
                        V6Z
                    "
                />

                <path
                    d="m9 12 2 2 4-5"
                />
            </svg>

            <span>
                Segurança
            </span>
        </a>


        <a
            href="{{ route('appearance.edit') }}"
            wire:navigate
            class="{{
                request()->routeIs('appearance.edit')
                    ? 'is-active'
                    : ''
            }}"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
            >
                <circle
                    cx="12"
                    cy="12"
                    r="4"
                />

                <path
                    d="M12 2v2"
                />

                <path
                    d="M12 20v2"
                />

                <path
                    d="m4.93 4.93 1.42 1.42"
                />

                <path
                    d="m17.65 17.65 1.42 1.42"
                />

                <path
                    d="M2 12h2"
                />

                <path
                    d="M20 12h2"
                />

                <path
                    d="m4.93 19.07 1.42-1.42"
                />

                <path
                    d="m17.65 6.35 1.42-1.42"
                />
            </svg>

            <span>
                Aparência
            </span>
        </a>

    </nav>


    <section class="ec-settings-content">

        <header class="ec-settings-content-header">

            <h2>
                {{ $heading ?? '' }}
            </h2>

            <p>
                {{ $subheading ?? '' }}
            </p>

        </header>


        <div class="ec-settings-content-body">
            {{ $slot }}
        </div>

    </section>

</div>
