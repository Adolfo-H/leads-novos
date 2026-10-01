    {{-- CNAES --}}
    <section
        x-show="
            dossierTab
            === 'cnaes'
        "
        x-cloak
        x-transition.opacity.duration.120ms
        class="
            ec-detail-panel
            ec-cnae-panel
            ec-dossier-tab-panel
        "
    >

        <div class="ec-detail-header ec-cnae-header">

            <div>

                <h2 class="ec-detail-title">
                    CNAEs da matriz
                </h2>

                <p class="ec-detail-description">
                    Cadastro manual dos CNAEs específicos da matriz.
                </p>

            </div>

            <button
                type="button"
                wire:click="toggleCnaeForm"
                class="{{
                    $showCnaeForm
                        ? 'ec-button-secondary'
                        : 'ec-button-primary'
                }}"
            >

                @if ($showCnaeForm)

                    Cancelar

                @else

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        class="size-4"
                    >
                        <path d="M12 5v14M5 12h14" />
                    </svg>

                    Adicionar CNAE

                @endif

            </button>

        </div>


        {{-- FORMULÁRIO CNAE --}}
        @if ($showCnaeForm)

            <div class="ec-cnae-form">

                <form
                    wire:submit="addCnae"
                    class="space-y-4"
                >

                    <div class="grid gap-4 md:grid-cols-3">

                        <div>

                            <label
                                for="newCnaeCode"
                                class="ec-field-label"
                            >
                                Código CNAE
                            </label>

                            <input
                                id="newCnaeCode"
                                wire:model.blur="newCnaeCode"
                                placeholder="4622200"
                                maxlength="20"
                                class="ec-input"
                            >

                            @error('newCnaeCode')

                                <p class="ec-field-error">
                                    {{ $message }}
                                </p>

                            @enderror

                        </div>


                        <div class="md:col-span-2">

                            <label
                                for="newCnaeDescription"
                                class="ec-field-label"
                            >
                                Descrição
                            </label>

                            <input
                                id="newCnaeDescription"
                                wire:model.blur="newCnaeDescription"
                                placeholder="Ex.: Comércio atacadista de soja"
                                class="ec-input"
                            >

                            @error('newCnaeDescription')

                                <p class="ec-field-error">
                                    {{ $message }}
                                </p>

                            @enderror

                        </div>

                    </div>


                    <label class="ec-checkbox-row">

                        <input
                            type="checkbox"
                            wire:model="newCnaePrimary"
                            class="ec-checkbox"
                        >

                        <span>

                            <strong>
                                CNAE principal
                            </strong>

                            <small>
                                Definir esta atividade como principal da matriz.
                            </small>

                        </span>

                    </label>


                    <div class="flex justify-end">

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="addCnae"
                            class="ec-button-primary"
                        >

                            <span
                                wire:loading.remove
                                wire:target="addCnae"
                            >
                                Salvar CNAE
                            </span>

                            <span
                                wire:loading
                                wire:target="addCnae"
                            >
                                Salvando...
                            </span>

                        </button>

                    </div>

                </form>

            </div>

        @endif


        <div class="ec-cnae-content">

            {{-- PRINCIPAL --}}
            @if ($this->primaryCnae)

                <div class="ec-cnae-block">

                    <div class="ec-cnae-block-label">
                        CNAE principal
                    </div>

                    <div class="ec-cnae-primary">

                        <div>

                            <div class="flex flex-wrap items-center gap-2">

                                <span class="ec-cnae-main-code">
                                    {{ $this->primaryCnae->code }}
                                </span>

                                <span class="ec-primary-badge">
                                    Principal
                                </span>

                            </div>

                            <p class="ec-cnae-main-description">
                                {{
                                    $this->primaryCnae->description
                                    ?: 'Sem descrição'
                                }}
                            </p>

                        </div>

                        <button
                            type="button"
                            wire:click="removeCnae({{ $this->primaryCnae->id }})"
                            wire:confirm="Deseja realmente remover este CNAE da matriz?"
                            class="ec-danger-action"
                        >
                            Remover
                        </button>

                    </div>

                </div>

            @else

                <div class="ec-cnae-empty">

                    <div class="ec-empty-title">
                        Nenhum CNAE principal definido
                    </div>

                    <p class="ec-empty-description">
                        Adicione um CNAE ou torne um CNAE secundário o principal.
                    </p>

                </div>

            @endif


            {{-- SECUNDÁRIOS --}}
            @if ($this->secondaryCnaes->isNotEmpty())

                <div class="ec-cnae-block">

                    <div class="ec-cnae-block-label">
                        CNAEs secundários
                    </div>

                    <div class="ec-cnae-list">

                        @foreach ($this->secondaryCnaes as $cnae)

                            <div
                                @if (
                                    $loop->index
                                    >= 6
                                )
                                    x-show="
                                        expandedMatrixCnaes
                                    "
                                    x-cloak
                                @endif

                                wire:key="cnae-secondary-{{ $cnae->id }}"
                                class="ec-cnae-row"
                            >

                                <div>

                                    <div class="ec-cnae-row-code">
                                        {{ $cnae->code }}
                                    </div>

                                    <div class="ec-cnae-row-description">
                                        {{
                                            $cnae->description
                                            ?: 'Sem descrição'
                                        }}
                                    </div>

                                </div>


                                <div class="ec-cnae-actions">

                                    <button
                                        type="button"
                                        wire:click="makeCnaePrimary({{ $cnae->id }})"
                                        class="ec-link-action"
                                    >
                                        Tornar principal
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="removeCnae({{ $cnae->id }})"
                                        wire:confirm="Deseja remover este CNAE da matriz?"
                                        class="ec-danger-action"
                                    >
                                        Remover
                                    </button>

                                </div>

                            </div>

                        @endforeach

                    </div>


                    @if (
                        $this
                            ->secondaryCnaes
                            ->count()
                        > 6
                    )

                        <div
                            class="
                                ec-show-more-bar
                            "
                        >

                            <button
                                type="button"
                                class="
                                    ec-show-more-button
                                "
                                @click="
                                    expandedMatrixCnaes =
                                        ! expandedMatrixCnaes
                                "
                            >

                                <span
                                    x-show="
                                        ! expandedMatrixCnaes
                                    "
                                >
                                    Ver todos os
                                    {{
                                        $this
                                            ->secondaryCnaes
                                            ->count()
                                    }}
                                    CNAEs secundários
                                </span>

                                <span
                                    x-show="
                                        expandedMatrixCnaes
                                    "
                                    x-cloak
                                >
                                    Mostrar menos
                                </span>

                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    :class="{
                                        'is-expanded':
                                            expandedMatrixCnaes
                                    }"
                                >
                                    <path
                                        d="m6 9 6 6 6-6"
                                    />
                                </svg>

                            </button>

                        </div>

                    @endif

                </div>

            @endif


            @if (
                ! $this->primaryCnae
                && $this->secondaryCnaes->isEmpty()
                && ! $showCnaeForm
            )

                <div class="mt-4 text-center">

                    <button
                        type="button"
                        wire:click="toggleCnaeForm"
                        class="ec-link-action"
                    >
                        + Cadastrar primeiro CNAE
                    </button>

                </div>

            @endif

        </div>

    </section>
