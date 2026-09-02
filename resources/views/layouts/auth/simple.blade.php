<!DOCTYPE html>

<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
>

<head>
    @include('partials.head')
</head>

<body class="ec-auth-body">

    <main class="ec-auth-shell">

        <div class="ec-auth-background"></div>

        <div class="ec-auth-brand-corner">

            <img
                src="{{ asset('images/brand/pngexportcontrol.png') }}"
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


        <div class="ec-auth-card">

            <div class="ec-auth-logo">

                <img
                    src="{{ asset('images/brand/pngexportcontrol.png') }}"
                    alt="ExportControl"
                >

                <div>

                    <strong>
                        EXPORTCONTROL
                    </strong>

                    <span>
                        Prospector
                    </span>

                </div>

            </div>


            {{ $slot }}

        </div>


        <div class="ec-auth-footer">
            ExportControl • Inteligência Comercial
        </div>

    </main>


    @persist('toast')

        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>

    @endpersist

    @fluxScripts

</body>

</html>
