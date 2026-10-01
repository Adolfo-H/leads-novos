<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Aparência')] class extends Component
{
    //
};
?>

<section class="ec-settings-screen ecui-settings">

    @include('partials.settings-heading')

    <x-pages::settings.layout
        heading="Aparência"
        subheading="Escolha a preferência visual deste navegador."
    >
        <section class="ecui-card ecui-appearance" x-data>
            <header class="ecui-card-head">
                <h3>Tema da interface</h3>
                <p>
                    A escolha é aplicada automaticamente,
                    sem precisar salvar.
                </p>
            </header>

            <fieldset class="ecui-theme-grid">
                <legend class="ecui-sr">
                    Escolha o tema
                </legend>

                @foreach ([
                    ['light', 'Claro', 'Superfícies claras e texto escuro.'],
                    ['dark', 'Escuro', 'Superfícies escuras e contraste suave.'],
                    ['system', 'Sistema', 'Acompanha a preferência do dispositivo.'],
                ] as [$value, $label, $description])
                    <label
                        class="ecui-theme-option"
                        x-bind:class="{ 'is-selected': $flux.appearance === '{{ $value }}' }"
                    >
                        <input
                            type="radio"
                            name="ecui-theme"
                            value="{{ $value }}"
                            x-model="$flux.appearance"
                        >

                        <span
                            class="ecui-theme-preview"
                            data-theme="{{ $value }}"
                            aria-hidden="true"
                        >
                            <span class="ecui-mini-sidebar"></span>

                            <span class="ecui-mini-content">
                                <i></i>
                                <span><b></b><b></b><b></b></span>
                                <em></em>
                                <em></em>
                            </span>
                        </span>

                        <strong>{{ $label }}</strong>
                        <small>{{ $description }}</small>
                    </label>
                @endforeach
            </fieldset>

            <footer class="ecui-appearance-footer">
                <span
                    role="status"
                    x-text="'Preferência: ' + ({ light: 'Claro', dark: 'Escuro', system: 'Sistema' }[$flux.appearance] || 'Sistema')"
                ></span>

                <p>
                    A preferência fica salva neste navegador
                    e é aplicada em toda a interface.
                </p>
            </footer>
        </section>
    </x-pages::settings.layout>

</section>
