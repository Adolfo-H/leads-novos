{{-- EC_WA_VISUAL_V12: único resumo, evitando os indicadores repetidos da V8 e V11. --}}
@php
    $wa12Metrics = $this->webMetrics;
    $wa12Public = $this->publicSiteMetrics;
    $wa12Total = (int) $summary['total'];
    $wa12Confirmed = (int) $summary['yes'];
    $wa12Review = (int) $summary['review'];
    $wa12Pending = max(0, $wa12Total - $wa12Confirmed - (int) $summary['no']);
    $wa12Valid = (int) $summary['mobile'] + (int) $summary['landline'];
    $wa12Percent = $wa12Valid > 0 ? min(100, round(100 * $wa12Confirmed / $wa12Valid)) : 0;
@endphp
<section class="wa12-overview" aria-label="Situação dos números cadastrados" data-test="wa12-overview">
    <div class="wa12-overview-head">
        <div>
            <span class="wa12-eyebrow">SITUAÇÃO DA SUA BASE</span>
            <h2>Seus números, sem complicação</h2>
            <p>Todos os números em uma só central. Acompanhe o que já foi verificado e trabalhe nos pendentes.</p>
        </div>
        <div class="wa12-progress-info" aria-label="Progresso da confirmação">
            <strong>{{ (int) $wa12Percent }}%</strong>
            <span>dos telefones válidos com WhatsApp confirmado</span>
        </div>
    </div>
    <div class="wa12-metrics" aria-label="Indicadores da base">
        <button type="button" class="wa12-metric" wire:click="quickFilter('all','all')" x-on:click="tab='numbers'" aria-label="Mostrar todos os números">
            <span>Números cadastrados</span><strong>{{ $fmt($wa12Total) }}</strong><small>{{ $fmt((int) $summary['mobile']) }} celulares · {{ $fmt((int) $summary['landline']) }} fixos</small>
        </button>
        <button type="button" class="wa12-metric wa12-metric-green" wire:click="quickFilter('all','yes')" x-on:click="tab='numbers'" aria-label="Mostrar números com WhatsApp">
            <span>Com WhatsApp</span><strong>{{ $fmt($wa12Confirmed) }}</strong><small>Confirmados no Prospector</small>
        </button>
        <button type="button" class="wa12-metric" wire:click="quickFilter('all','unknown')" x-on:click="tab='numbers'" aria-label="Mostrar números a verificar">
            <span>A verificar</span><strong>{{ $fmt($wa12Pending) }}</strong><small>Inclui {{ $fmt($wa12Review) }} para revisar formato</small>
        </button>
        <button type="button" class="wa12-metric" wire:click="quickWebFilter('consulted')" x-on:click="tab='numbers'" aria-label="Mostrar números já consultados pelo Web">
            <span>Consultados via Web</span><strong>{{ $fmt((int) $wa12Metrics['consulted']) }}</strong><small>{{ $fmt((int) $wa12Metrics['never_consulted']) }} nunca consultados</small>
        </button>
    </div>
    <div class="wa12-overview-bottom">
        <div class="wa12-overview-track" role="progressbar" aria-label="Percentual confirmado" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $wa12Percent }}"><span style="width:{{ (int) $wa12Percent }}%"></span></div>
        <div class="wa12-overview-meta">
            <span><b>{{ $fmt($wa12Review) }}</b> precisam de revisão de formato</span>
            <span><b>{{ $fmt((int) ($wa12Public['indexed'] ?? $wa12Public['matched'] ?? 0)) }}</b> telefones do cadastro divulgados em sites</span>
            <details class="wa12-info-details">
                <summary>Como funciona a confirmação?</summary>
                <p><strong>WhatsApp identificado pela integração Web</strong> é um resultado técnico de uma ferramenta não oficial. Links publicados em sites são apenas indícios. Resultados inconclusivos não provam ausência do WhatsApp.</p>
            </details>
        </div>
    </div>
</section>
