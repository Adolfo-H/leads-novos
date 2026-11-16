{{-- Pacote 37: universo global de negócios HubSpot, sem recorte por responsável.
     Esta visão é autorizada somente para gestão e não altera a fila de empresas. --}}
@if ($this->isCommercialManager())
    @php
        $lf37GlobalStages = collect($this->globalHubSpotStageOptions)
            ->values();
        $lf37GlobalTotal = $lf37GlobalStages->sum('count');
    @endphp
    <section class="lf36-stages lf37-global-stages" aria-label="Etapas globais de todos os negócios do HubSpot">
        <div class="lf36-section-heading lf37-global-heading">
            <div>
                <h2>Etapas HubSpot <span class="lf37-global-tag">Visão geral</span></h2>
                <p>Todos os negócios do espelho HubSpot, de todos os responsáveis e pipelines, inclusive sem CNPJ identificado.</p>
            </div>
            <div class="lf37-global-total" aria-label="Total de negócios HubSpot">
                <strong>{{ number_format($lf37GlobalTotal, 0, ',', '.') }}</strong>
                <span>{{ $lf37GlobalTotal === 1 ? 'negócio' : 'negócios' }} no HubSpot</span>
            </div>
        </div>
        @if ($lf37GlobalStages->isNotEmpty())
            <div class="lf36-stage-grid lf37-global-grid">
                @foreach ($lf37GlobalStages as $stage)
                    <button type="button"
                        class="lf36-stage lf37-global-stage {{ (int) $stage['count'] === 0 ? 'is-empty-stage' : '' }} {{ $globalHubSpotStage === $stage['label'] ? 'is-active' : '' }}"
                        wire:click="applyGlobalHubSpotStageView({{ $loop->index }})"
                        wire:loading.attr="disabled"
                        aria-pressed="{{ $globalHubSpotStage === $stage['label'] ? 'true' : 'false' }}"
                        aria-label="Filtrar negócios do HubSpot na etapa {{ $stage['label'] }}: {{ $stage['count'] }} negócios">
                        <span>{{ $stage['label'] }}</span>
                        <strong>{{ number_format($stage['count'], 0, ',', '.') }}</strong>
                    </button>
                @endforeach
            </div>
            <p class="lf37-global-footer">Contagem de negócios únicos do espelho local sincronizado pelo HubSpot. Os filtros da lista abaixo não alteram esses totais.</p>
        @else
            <div class="lf37-empty-stage">Nenhum negócio disponível no espelho local do HubSpot.</div>
        @endif

        {{-- EC_PROSPECTOR_V45_GLOBAL_STAGE_MOVED: negócios agora listados em Empresas para trabalhar. --}}

    </section>
@endif
