@php
    $wa8 = $this->webMetrics;
    $wa8Valid = (int) $summary['mobile'] + (int) $summary['landline'];
    $wa8Percent = $wa8Valid > 0 ? min(100, (int) round(100 * $summary['yes'] / $wa8Valid)) : 0;
@endphp
<div class="wa7-dashboard wa8-dashboard" data-test="whatsapp-unified-v8">
    <section class="wa7-overview" aria-label="Resumo único da verificação WhatsApp">
        <div class="wa7-intro">
            <div>
                <span class="wa7-eyebrow">VISÃO GERAL · VERIFICAÇÃO WHATSAPP</span>
                <h2>Todos os números em uma só central</h2>
                <p>Consulte a lista completa, filtre por tipo e acompanhe as verificações sem perder os vínculos com Contatos.</p>
            </div>
            <div class="wa7-index-note">{{ $fmt($summary['total']) }} números únicos cadastrados<br><small>{{ $fmt($summary['landline']) }} fixos · {{ $fmt($summary['review']) }} para revisar</small></div>
        </div>
        <div class="wa7-metrics wa8-metrics">
            <button type="button" wire:click="quickFilter('all','all')" class="wa7-metric wa8-metric" aria-label="Mostrar todos os números cadastrados">
                <span>Todos os números</span><strong>{{ $fmt($summary['total']) }}</strong><small>Celulares, fixos e formatos a revisar</small>
            </button>
            <button type="button" wire:click="quickFilter('all','yes')" class="wa7-metric wa7-metric-good" aria-label="Mostrar números com WhatsApp">
                <span>Com WhatsApp</span><strong>{{ $fmt($summary['yes']) }}</strong><small>Confirmados pelo sistema</small>
            </button>
            <button type="button" wire:click="quickFilter('all','unknown')" class="wa7-metric wa7-metric-accent" aria-label="Mostrar todos os números sem confirmação">
                <span>A verificar</span><strong>{{ $fmt((int) $summary['pending'] + (int) $summary['review']) }}</strong><small>Sem confirmação, inclusive para revisar</small>
            </button>
            <button type="button" wire:click="quickWebFilter('consulted')" class="wa7-metric" aria-label="Mostrar números já consultados via Web">
                <span>Consultados via Web</span><strong>{{ $fmt($wa8['consulted']) }}</strong><small>{{ $fmt($wa8['never_consulted']) }} nunca consultados</small>
            </button>
            <button type="button" wire:click="quickFilter('review','all')" class="wa7-metric" aria-label="Mostrar números que exigem revisão de formato">
                <span>Revisar formato</span><strong>{{ $fmt($summary['review']) }}</strong><small>Exigem correção na origem</small>
            </button>
        </div>
        <p class="wa8-technical-legend"><strong>WhatsApp identificado pela integração Web</strong> indica um resultado técnico não oficial; registros inconclusivos continuam para revisão.</p>
        <div class="wa7-completion">
            <div class="wa7-progress-label"><span><strong>{{ $wa8Percent }}%</strong> dos números válidos com WhatsApp confirmado</span><span>Confirmações técnicas e manuais identificadas</span></div>
            <div class="wa7-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $wa8Percent }}" aria-label="Porcentagem de celulares confirmados"><span style="width:{{ $wa8Percent }}%"></span></div>
        </div>
    </section>

    <div class="wa8-directory-intro">
        <div><span class="wa8-directory-kicker">Números e contatos</span><strong>Diretório completo</strong><p>{{ $fmt($summary['mobile']) }} celulares · {{ $fmt($summary['landline']) }} fixos · {{ $fmt($summary['review']) }} para revisar. Todos disponíveis na lista paginada.</p></div>
        <div class="wa8-nav-buttons"><a href="#wa8-lista" class="wa8-nav-main">Ver todos os números</a><a href="#wa8-operacao" class="wa8-nav-secondary">Configurar verificações</a></div>
    </div>
</div>
