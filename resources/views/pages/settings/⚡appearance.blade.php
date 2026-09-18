<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Aparência')] class extends Component
{
    //
};
?>


<section class="ec-settings-screen">

    @include('partials.settings-heading')


    <x-pages::settings.layout
        heading="Aparência"
        subheading="Escolha como o Prospector deve ser exibido."
    >

        <div class="ec-settings-card">

            <div class="ec-settings-card-title">

                <div class="ec-settings-card-icon">

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
                            d="M2 12h2"
                        />

                        <path
                            d="M20 12h2"
                        />
                    </svg>

                </div>

                <div>

                    <h3>
                        Tema da interface
                    </h3>

                    <p>
                        Você pode usar tema claro,
                        escuro ou acompanhar o sistema.
                    </p>

                </div>

            </div>


            <div class="ec-settings-appearance">

                <flux:radio.group
                    x-data
                    variant="segmented"
                    x-model="$flux.appearance"
                >

                    <flux:radio
                        value="light"
                        icon="sun"
                    >
                        Claro
                    </flux:radio>

                    <flux:radio
                        value="dark"
                        icon="moon"
                    >
                        Escuro
                    </flux:radio>

                    <flux:radio
                        value="system"
                        icon="computer-desktop"
                    >
                        Sistema
                    </flux:radio>

                </flux:radio.group>

            </div>


            <div class="ec-settings-info">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                >
                    <circle
                        cx="12"
                        cy="12"
                        r="9"
                    />

                    <path
                        d="M12 11v6"
                    />

                    <path
                        d="M12 7h.01"
                    />
                </svg>

                <span>
                    A opção Sistema acompanha automaticamente
                    o tema configurado no dispositivo.
                </span>

            </div>

        </div>

    </x-pages::settings.layout>

</section>
