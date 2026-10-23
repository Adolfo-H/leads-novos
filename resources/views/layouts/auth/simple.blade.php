<!doctype html>
<html lang="pt-BR">
<head>
    @include('partials.head')
    {{-- EC_LOGIN_HERO_V27_START --}}
    <style>
        /* Foto do porto + mapa de conexoes. Fundo das paginas de autenticacao. */
        .ec24-auth .ec24-brand {
            isolation: isolate;
            background: #041b2e !important;
        }
        .ec24-auth .ec24-brand-layer {
            position: absolute !important;
            inset: 0 !important;
            display: block !important;
            visibility: visible !important;
            background-image:
                linear-gradient(90deg, rgba(2, 15, 30, .61) 0%, rgba(2, 15, 30, .27) 54%, rgba(2, 15, 30, .02) 100%),
                url('{{ asset("images/brand/login-hero-v27.webp") }}?v=27') !important;
            background-position: center center !important;
            background-size: cover !important;
            background-repeat: no-repeat !important;
            opacity: 1 !important;
            z-index: 0 !important;
            pointer-events: none !important;
        }
        /* Desativa as circunferencias e o globo antigos que encobriam a foto. */
        .ec24-auth .ec24-brand::before,
        .ec24-auth .ec24-brand::after {
            display: none !important;
        }
        .ec24-auth .ec24-brand-content {
            position: relative !important;
            z-index: 1 !important;
        }
        @media (max-width: 760px) {
            .ec24-auth .ec24-brand-layer {
                background-position: center 36% !important;
            }
        }
    </style>
    {{-- EC_LOGIN_HERO_V27_END --}}
</head>
<body class="ec24-auth-body">
<main class="ec24-auth" aria-label="Acesso ao ExportControl Prospector">
    <aside class="ec24-brand" aria-label="ExportControl Prospector">
        <div class="ec24-brand-layer" aria-hidden="true"></div>
        <div class="ec24-brand-content">
            <a class="ec24-brand-logo" href="{{ route('home') }}" aria-label="ExportControl Prospector - início">
                <img src="{{ asset('images/brand/pngexportcontrol.png') }}" alt="" width="54" height="54" />
                <span><b>EXPORTCONTROL</b><small>Prospector</small></span>
            </a>
            <div class="ec24-brand-statement">
                <span class="ec24-brand-kicker">PROSPECÇÃO COM CONTEXTO</span>
                <h1>Inteligência que conecta sua operação ao mundo.</h1>
                <p>Dados empresariais, prioridades comerciais e relacionamentos conectados em um só lugar.</p>
                <div class="ec24-brand-features">
                    <div><span class="ec24-feature-symbol"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7a2 2 0 0 1 2-2h5v16M12 9h5a2 2 0 0 1 2 2v10"/><path d="M8 9h1M8 13h1M8 17h1M15 13h1M15 17h1"/></svg></span><p><b>Encontre empresas</b><small>Identifique oportunidades com dados confiáveis.</small></p></div>
                    <div><span class="ec24-feature-symbol"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20v-1.4a4.6 4.6 0 0 0-4.6-4.6H7.6A4.6 4.6 0 0 0 3 18.6V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M18 13a3.5 3.5 0 0 1 3 3.5V20M16.5 4.2a3.5 3.5 0 0 1 0 6.6"/></svg></span><p><b>Construa relacionamentos</b><small>Conecte-se aos contatos certos com contexto.</small></p></div>
                    <div><span class="ec24-feature-symbol"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="13" r="9"/><circle cx="11" cy="13" r="5"/><path d="m11 13 9-9M16 4h4v4"/></svg></span><p><b>Prepare campanhas</b><small>Planeje sua próxima abordagem comercial.</small></p></div>
                </div>
            </div>
            <div class="ec24-brand-footer">ExportControl · Inteligência comercial</div>
        </div>
    </aside>
    <section class="ec24-auth-main">
        <div class="ec24-auth-top">
            @if (request()->routeIs('login'))
                <span>Não tem acesso?</span>
                <a class="ec24-outline" href="{{ route('access.request.create') }}">Solicitar acesso</a>
            @else
                <a class="ec24-top-back" href="{{ route('login') }}">← Voltar para login</a>
            @endif
        </div>
        <div class="ec24-auth-card">{{ $slot }}</div>
        <p class="ec24-auth-privacy">Acesso protegido · ExportControl Prospector</p>
    </section>
</main>
@persist('toast')
    <flux:toast.group>
        <flux:toast />
    </flux:toast.group>
@endpersist
@fluxScripts
</body>
</html>
