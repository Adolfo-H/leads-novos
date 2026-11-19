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
{{-- EC_WA_KPI_NATIVE_GET_V11: atalhos de filtro via HTTP nativo, sem Livewire. --}}
<style id="ec-wa-kpi-native-get-v11">
    .wpr.wa12 .wa12-metrics a.wa12-metric {text-decoration:none;}
    .wpr.wa12 .wa12-metrics a.wa12-metric.is-filter-active {border-color:#0ca897;box-shadow:0 0 0 2px rgba(12,168,151,.12);}
    .wpr.wa12 .wa12-metrics a.wa12-metric:focus-visible {outline:2px solid #0c9a88;outline-offset:2px;}
</style>
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
        <a class="wa12-metric {{ $status === 'all' && $webFilter === 'all' && $kind === 'all' && $search === '' ? 'is-filter-active' : '' }}" href="{{ route('contacts.whatsapp', ['tipo' => 'all', 'situacao' => 'all', 'origem' => 'all', 'consulta' => 'all', 'publico' => 'all', 'porPagina' => $pageSize]) }}#wa8-lista" aria-label="Mostrar todos os números">
            <span>Números cadastrados</span><strong>{{ $fmt($wa12Total) }}</strong><small>{{ $fmt((int) $summary['mobile']) }} celulares · {{ $fmt((int) $summary['landline']) }} fixos</small>
        </a>
        <a class="wa12-metric wa12-metric-green {{ $status === 'yes' && $webFilter === 'all' && $kind === 'all' ? 'is-filter-active' : '' }}" href="{{ route('contacts.whatsapp', ['situacao' => 'yes', 'porPagina' => $pageSize]) }}#wa8-lista" aria-label="Mostrar números com WhatsApp">
            <span>Com WhatsApp</span><strong>{{ $fmt($wa12Confirmed) }}</strong><small>Confirmados no Prospector</small>
        </a>
        <a class="wa12-metric {{ $status === 'unknown' && $webFilter === 'all' && $kind === 'all' ? 'is-filter-active' : '' }}" href="{{ route('contacts.whatsapp', ['situacao' => 'unknown', 'porPagina' => $pageSize]) }}#wa8-lista" aria-label="Mostrar números a verificar">
            <span>A verificar</span><strong>{{ $fmt($wa12Pending) }}</strong><small>Inclui {{ $fmt($wa12Review) }} para revisar formato</small>
        </a>
        <a class="wa12-metric {{ $webFilter === 'consulted' && $kind === 'all' && $status === 'all' ? 'is-filter-active' : '' }}" href="{{ route('contacts.whatsapp', ['consulta' => 'consulted', 'porPagina' => $pageSize]) }}#wa8-lista" aria-label="Mostrar números já consultados pelo Web">
            <span>Consultados via Web</span><strong>{{ $fmt((int) $wa12Metrics['consulted']) }}</strong><small>{{ $fmt((int) $wa12Metrics['never_consulted']) }} nunca consultados</small>
        </a>
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
