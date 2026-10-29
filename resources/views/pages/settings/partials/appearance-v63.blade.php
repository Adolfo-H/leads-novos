{{-- EC_APPEARANCE_V65_A243: Claro, Sistema institucional e Escuro. --}}
<section class="ec-cfg-v63 ec-cfg-appearance" data-ec-settings-figma="U03-V63" x-data="{ theme: window.EcAppearanceV65.get() }" x-on:ec-appearance-changed.window="theme = $event.detail.mode">
    <header class="ec-cfg-hero">
        <span class="ec-cfg-kicker">CONTA</span>
        <h1>Deixe o espaço do seu jeito.</h1>
        <p>Escolha a aparência para trabalhar com mais conforto.</p>
        <div class="ec-cfg-hero-actions">
            <a href="{{ route('profile.edit') }}" wire:navigate class="ec-cfg-btn ec-cfg-btn-outline">Voltar ao perfil</a>
        </div>
    </header>

    @include('pages.settings.partials.tabs-v63')

    <div class="ec-cfg-section-title">Aparência</div>
    <fieldset class="ec-cfg-theme-grid" aria-label="Escolha o tema">
        <legend class="ec-cfg-sr">Aparência da aplicação</legend>
        @foreach([
            ['light', 'Claro', 'Tudo claro: navegação branca, conteúdos brancos e destaques em teal.'],
            ['dark', 'Escuro', 'Navegação e áreas de trabalho escuras com contraste ajustado.'],
            ['system', 'Sistema', 'Visual institucional: navegação azul-marinho e conteúdo claro.'],
        ] as [$theme, $themeLabel, $themeDescription])
            <label class="ec-cfg-theme-card"
                   x-bind:class="{ 'is-selected': theme === @js($theme) }">
                <input type="radio" name="ec-cfg-theme" value="{{ $theme }}" x-model="theme" x-on:change="window.EcAppearanceV65.set($event.target.value)"
                       class="ec-cfg-theme-input">
                <span class="ec-cfg-pill" x-show="theme === @js($theme)" x-cloak>Selecionado</span>
                <strong>{{ $themeLabel }}</strong>
                <span>{{ $themeDescription }}</span>
                <span class="ec-cfg-link">Selecionar tema →</span>
            </label>
        @endforeach
    </fieldset>

    <section class="ec-cfg-card ec-cfg-theme-control" aria-labelledby="ec-cfg-appearance-control-title">
        <h2 id="ec-cfg-appearance-control-title" class="ec-cfg-card-heading">Tema do aplicativo</h2>
        <label class="ec-cfg-field" for="ec-cfg-appearance-select">Aparência</label>
        <select id="ec-cfg-appearance-select" class="ec-cfg-theme-select" x-model="theme" x-on:change="window.EcAppearanceV65.set($event.target.value)">
            <option value="light">Claro</option>
            <option value="dark">Escuro</option>
            <option value="system">Sistema</option>
        </select>
        <p class="ec-cfg-small-helper">Sistema mantém a barra lateral azul e as áreas de trabalho claras. Claro deixa também a navegação branca. Escuro aplica o visual escuro.</p>
    </section>

    <div class="ec-cfg-note is-neutral" role="status">
        <span class="ec-cfg-note-icon" aria-hidden="true">✓</span>
        <div>
            <strong>Aplicado na hora</strong>
            <p>Claro, Sistema e Escuro mudam a estrutura visual imediatamente. A preferência é salva neste navegador.</p>
        </div>
    </div>
    <footer class="ec-cfg-foot">ExportControl Prospector · Configurações da conta</footer>
</section>
