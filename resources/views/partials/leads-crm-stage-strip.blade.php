{{-- Faixa estável: a seleção não modifica a largura do título nem dos números. --}}
<section class="rf-stage-strip lv18-pipeline" aria-label="Etapas reais do HubSpot">
    <div class="rf-stage-strip-heading lv18-pipeline-heading">
        <strong>Pipeline HubSpot</strong>
        <span>Etapas com negócios vinculados</span>
    </div>

    <div class="rf-stage-strip-list lv18-pipeline-chips" role="group" aria-label="Filtrar por etapa do HubSpot">
        @forelse ($this->crmStageOptions as $stage)
            @php
                $lv18StageName = (string) $stage['label'];
                $lv18StageKey = mb_strtolower($lv18StageName);
                $lv18StageTone = match (true) {
                    str_contains($lv18StageKey, 'descart'),
                    str_contains($lv18StageKey, 'recus') => 'red',
                    str_contains($lv18StageKey, 'fora') => 'amber',
                    str_contains($lv18StageKey, 'frio') => 'blue',
                    str_contains($lv18StageKey, 'qualific') => 'purple',
                    str_contains($lv18StageKey, 'oportunidade') => 'teal',
                    default => 'neutral',
                };
                $lv18Selected = $crm === 'stage:'.$lv18StageName;
            @endphp
            <button
                type="button"
                class="rf-stage-strip-chip lv13-stage--{{ $lv18StageTone }} lv18-pipeline-chip {{ $lv18Selected ? 'is-active' : '' }}"
                wire:click="applyCrmStageView({{ $loop->index }})"
                aria-pressed="{{ $lv18Selected ? 'true' : 'false' }}"
                title="Filtrar por {{ $lv18StageName }}"
            >
                <span class="lv18-chip-label">{{ $lv18StageName }}</span>
                <strong class="lv18-chip-count">{{ number_format((int) $stage['count'], 0, ',', '.') }}</strong>
            </button>
        @empty
            <span class="rf-stage-strip-empty">Nenhuma etapa disponível na base local.</span>
        @endforelse
    </div>

    <div class="lv18-pipeline-clear-container">
        @if (str_starts_with($crm, 'stage:'))
            <button
                type="button"
                class="lv17-stage-clear lv18-clear-stage"
                wire:click="$set('crm', '')"
                aria-label="Limpar etapa HubSpot selecionada"
            >Limpar</button>
        @else
            <span class="lv17-stage-clear-slot lv18-clear-stage" aria-hidden="true"></span>
        @endif
    </div>
</section>
