@props(['heading' => '', 'subheading' => ''])

<div class="ecui-layout">
    <nav class="ecui-tabs" aria-label="Configurações da conta">
        @foreach ([
            ['profile.edit', 'Perfil', 'Dados pessoais'],
            ['security.edit', 'Segurança', 'Senha e autenticação'],
            ['appearance.edit', 'Aparência', 'Preferências visuais'],
        ] as [$route, $label, $description])
            <a
                href="{{ route($route) }}"
                wire:navigate
                aria-current="{{ request()->routeIs($route) ? 'page' : 'false' }}"
            >
                <strong>{{ $label }}</strong>
                <span>{{ $description }}</span>
            </a>
        @endforeach
    </nav>

    <section class="ecui-content">
        <header class="ecui-section-head">
            <h2>{{ $heading }}</h2>
            <p>{{ $subheading }}</p>
        </header>

        <div class="ecui-content-body">
            {{ $slot }}
        </div>
    </section>
</div>
