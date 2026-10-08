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

            @php
                $lv13StageLabel = mb_strtolower(trim((string) $stage['label']));
                $lv13StageColor = match (true) {
                    str_contains($lv13StageLabel, 'descart'),
                    str_contains($lv13StageLabel, 'recus') => 'red',
                    str_contains($lv13StageLabel, 'fora') => 'amber',
                    str_contains($lv13StageLabel, 'frio') => 'blue',
                    str_contains($lv13StageLabel, 'qualific') => 'purple',
                    str_contains($lv13StageLabel, 'oportunidade') => 'teal',
                    default => 'neutral',
                };
            @endphp
            <button
                type="button"
                wire:click="
                    applyCrmStageView(
                        {{ $loop->index }}
                    )
                "
                class="
                    rf-stage-strip-chip lv13-stage--{{ $lv13StageColor }}
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
