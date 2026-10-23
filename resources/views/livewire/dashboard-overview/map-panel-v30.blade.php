{{--
    EXPORTCONTROL — Mapa do Brasil V30
    Um único mapa geográfico real, com todas as 27 UFs interativas.
    Os dados são os mesmos do Dashboard: $ds25StatesMap, $dsMapTooltips e $s.
    Não consulta serviços externos, não altera a base e não cria métricas artificiais.
--}}
@php
    $ef30MaxStateCount = 1;
    foreach ($ds25StateNames as $ef30Code => $ef30Name) {
        $ef30MaxStateCount = max($ef30MaxStateCount, (int) ($ds25StatesMap[$ef30Code] ?? 0));
    }

    $ef30Palette = [
        0 => '#dce7ec',
        1 => '#b4ddd6',
        2 => '#77c9b9',
        3 => '#2ca99a',
        4 => '#077b72',
    ];
    $ef30Descriptions = [
        'Sem registros',
        'Até 25%',
        '26–50%',
        '51–75%',
        '76–100%',
    ];
@endphp

<section
    class="ef29-card ef29-map-panel ef30-map-panel"
    aria-labelledby="ef30-map-title"
    x-data="{ expanded: false }"
    x-bind:class="{ 'ef30-map-panel--expanded': expanded }"
    x-on:keydown.escape.window="expanded = false"
>
    <div class="ef30-map-heading">
        <div class="ef30-map-heading-copy">
            <span class="ef30-map-eyebrow">VISÃO TERRITORIAL</span>
            <h2 id="ef30-map-title">Presença da carteira por estado</h2>
            <p>Mapa geográfico do Brasil · selecione um estado para explorar os estabelecimentos.</p>
        </div>
        <button
            type="button"
            class="ef30-map-zoom"
            x-on:click="expanded = !expanded"
            x-bind:aria-expanded="expanded.toString()"
            aria-controls="ef30-map-canvas"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M3 16v3a2 2 0 0 0 2 2h3M21 16v3a2 2 0 0 1-2 2h-3"/>
            </svg>
            <span x-text="expanded ? 'Fechar mapa ampliado' : 'Ver mapa geográfico interativo'">Ver mapa geográfico interativo</span>
        </button>
    </div>

    {{-- Gradiente de intensidade: classe CSS calculada dos estabelecimentos reais por UF.
         Como o SVG já tem os paths de cada estado, não precisamos replicá-los. --}}
    <style>
        @foreach ($ds25StateNames as $ef30Code => $ef30Name)
            @php
                $ef30Quantity = (int) ($ds25StatesMap[$ef30Code] ?? 0);
                $ef30Level = $ef30Quantity === 0
                    ? 0
                    : max(1, min(4, (int) ceil(4 * $ef30Quantity / $ef30MaxStateCount)));
            @endphp
            .ef29-dashboard .ef30-map-panel .ds-v7-state[data-uf="{{ $ef30Code }}"] {
                fill: {{ $ef30Palette[$ef30Level] }} !important;
            }
        @endforeach
    </style>

    <div id="ef30-map-canvas" class="ef30-map-canvas" aria-label="Mapa geográfico interativo do Brasil">
        <div class="ef30-map-art">
            @include('livewire.dashboard-overview.map-interactive-v7', ['dsMapTooltips' => $dsMapTooltips])
        </div>
    </div>

    <div class="ef30-map-bottom">
        <div class="ef30-map-guidance">
            <span class="ef30-map-guidance-dot" aria-hidden="true"></span>
            Passe o mouse ou use o teclado para consultar a UF; clique para abrir os registros.
        </div>
        <div class="ef30-map-count">
            <strong>{{ $ds25Number($s['establishments']) }}</strong>
            <span>estabelecimentos na base</span>
        </div>
    </div>

    <div class="ef30-map-legend" aria-label="Intensidade de estabelecimentos por estado em relação à UF com maior quantidade">
        <strong>Concentração relativa por estado</strong>
        <div class="ef30-map-legend-items">
            @foreach ($ef30Palette as $ef30Level => $ef30Color)
                <span class="ef30-map-legend-item">
                    <i style="background-color: {{ $ef30Color }}" aria-hidden="true"></i>
                    {{ $ef30Descriptions[$ef30Level] }}
                </span>
            @endforeach
        </div>
        <p>Faixas calculadas a partir da UF com mais estabelecimentos cadastrados. Sem registros não significa ausência de empresas no estado.</p>
    </div>
</section>
