<!DOCTYPE html>

<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
>

<head>
    @include('partials.head')
</head>

<body class="ec-auth-body">

    @if (
        request()->routeIs(
            'login',
            'password.request',
            'password.confirm'
        )
    )

        <main class="ec-auth-login-page">

            <div
                class="ec-auth-login-background"
                aria-hidden="true"
            ></div>

            <div
                class="ec-auth-login-overlay"
                aria-hidden="true"
            ></div>


            <div class="ec-auth-login-layout">

                {{-- TEXTO LATERAL --}}
                <section
                    class="ec-auth-login-copy"
                    aria-label="ExportControl Prospector"
                >

                    <span
                        class="ec-auth-login-accent"
                        aria-hidden="true"
                    ></span>

                    <h1>
                        <strong>
                            Inteligência
                        </strong>

                        <span>
                            para o comércio
                            <br>
                            exterior
                        </span>
                    </h1>

                    <p>
                        Exportação, importação e
                        <br>
                        oportunidades com clareza.
                    </p>

                </section>


                {{-- LOGIN --}}
                <section
                    class="
                        ec-auth-card
                        ec-auth-card-login
                    "
                >

                    <div class="ec-auth-logo">

                        <img
                            src="{{
                                asset(
                                    'images/brand/pngexportcontrol.png'
                                )
                            }}"
                            alt="ExportControl"
                        >

                        <div>

                            <strong>
                                EXPORTCONTROL
                            </strong>

                            <span>
                                PROSPECTOR
                            </span>

                        </div>

                    </div>

                    {{ $slot }}

                </section>

            </div>

        </main>

    @else

        {{-- Outras páginas de autenticação --}}
        <main class="ec-auth-shell">

            <div class="ec-auth-background"></div>

            <div class="ec-auth-card">

                <div class="ec-auth-logo">

                    <img
                        src="{{
                            asset(
                                'images/brand/pngexportcontrol.png'
                            )
                        }}"
                        alt="ExportControl"
                    >

                    <div>
                        <strong>
                            EXPORTCONTROL
                        </strong>

                        <span>
                            PROSPECTOR
                        </span>
                    </div>

                </div>

                {{ $slot }}

            </div>

        </main>

    @endif


    @persist('toast')

        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>

    @endpersist

    @fluxScripts

</body>

</html>
