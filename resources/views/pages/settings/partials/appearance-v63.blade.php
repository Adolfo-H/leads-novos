{{-- CONFIGURACOES_FIGMA_V63 — escolha já existente do Flux; não cria outro armazenamento. --}}
<section class="ec-cfg-v63 ec-cfg-appearance" data-ec-settings-figma="U03-V63" x-data="{}">
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
            ['light', 'Claro', 'Superfícies claras, textos em navy e destaques em teal.'],
            ['dark', 'Escuro', 'Superfícies navy e contraste ajustado para ambientes com menos luz.'],
            ['system', 'Sistema', 'Usa automaticamente o modo claro ou escuro configurado no dispositivo.'],
        ] as [$theme, $themeLabel, $themeDescription])
            <label class="ec-cfg-theme-card"
                   x-bind:class="{ 'is-selected': $flux.appearance === @js($theme) }">
                <input type="radio" name="ec-cfg-theme" value="{{ $theme }}" x-model="$flux.appearance"
                       class="ec-cfg-theme-input">
                <span class="ec-cfg-pill" x-show="$flux.appearance === @js($theme)" x-cloak>Selecionado</span>
                <strong>{{ $themeLabel }}</strong>
                <span>{{ $themeDescription }}</span>
                <span class="ec-cfg-link">Selecionar tema →</span>
            </label>
        @endforeach
    </fieldset>

    <section class="ec-cfg-card ec-cfg-theme-control" aria-labelledby="ec-cfg-appearance-control-title">
        <h2 id="ec-cfg-appearance-control-title" class="ec-cfg-card-heading">Tema do aplicativo</h2>
        <label class="ec-cfg-field" for="ec-cfg-appearance-select">Aparência</label>
        <select id="ec-cfg-appearance-select" class="ec-cfg-theme-select" x-model="$flux.appearance">
            <option value="light">Claro</option>
            <option value="dark">Escuro</option>
            <option value="system">Sistema</option>
        </select>
        <p class="ec-cfg-small-helper">Sistema acompanha o tema do Windows/macOS. Em dispositivo escuro, todo o Prospector ficará escuro; em dispositivo claro, ficará claro.</p>
    </section>

    <div class="ec-cfg-note is-neutral" role="status">
        <span class="ec-cfg-note-icon" aria-hidden="true">✓</span>
        <div>
            <strong>Aplicado na hora</strong>
            <p>Claro e Escuro ajustam as telas do Prospector. Sistema acompanha a aparência do dispositivo. A preferência é salva neste navegador.</p>
        </div>
    </div>
    <footer class="ec-cfg-foot">ExportControl Prospector · Configurações da conta</footer>
</section>
