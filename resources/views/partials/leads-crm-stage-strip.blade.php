<section class="rf-stage-strip">

    <div class="rf-stage-strip-heading">

        <div>

            <strong>
                Pipeline HubSpot
            </strong>

            <span>
                Etapas com negócios vinculados
            </span>

        </div>


        @if (
            str_starts_with(
                $crm,
                'stage:'
            )
        )

            <button
                type="button"
                wire:click="$set('crm', '')"
                class="rf-stage-strip-clear"
            >
                Limpar etapa
            </button>

        @endif

    </div>


    <div class="rf-stage-strip-list">

        @forelse (
            $this->crmStageOptions
            as $stage
        )

            <button
                type="button"
                wire:click="
                    applyCrmStageView(
                        {{ $loop->index }}
                    )
                "
                class="
                    rf-stage-strip-chip
                    {{
                        $crm
                            ===
                            'stage:'
                            .$stage['label']
                                ? 'is-active'
                                : ''
                    }}
                "
            >

                <span>
                    {{ $stage['label'] }}
                </span>

                <strong>
                    {{
                        number_format(
                            $stage['count'],
                            0,
                            ',',
                            '.'
                        )
                    }}
                </strong>

            </button>

        @empty

            <span class="rf-stage-strip-empty">
                Nenhum negócio com etapa identificado.
            </span>

        @endforelse

    </div>

</section>
