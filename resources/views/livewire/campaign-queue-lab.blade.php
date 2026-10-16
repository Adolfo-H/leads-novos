<section class="ecm2-context-card ec-queue-lab" data-ec-queue-lab>
    @php($s = $this->scenario)
    @php($actual = $s['actual'])
    <header class="ec-queue-lab-head">
        <div>
            <small>CONTROLE DE DISPAROS</small>
            <h2>Simulador de fila</h2>
        </div>
        <span class="ec-queue-lab-tag">SEM ENVIOS</span>
    </header>
    <div class="ec-queue-lab-body">
        <p>Projete a capacidade do servidor sem enviar mensagens ou alterar campanhas.</p>
        <div class="ec-queue-lab-options" role="group" aria-label="Tamanho do lote hipotético">
            @foreach ([10, 50, 100, 500] as $size)
                <button type="button" wire:click="$set('recipients', {{ $size }})"
                    class="{{ $recipients === $size ? 'is-selected' : '' }}"
                    aria-pressed="{{ $recipients === $size ? 'true' : 'false' }}">
                    {{ $size }} contatos
                </button>
            @endforeach
        </div>
        <div class="ec-queue-lab-limits" aria-label="Limites configurados">
            <div><small>Por hora</small><strong>{{ $s['hourly_limit'] }}</strong></div>
            <div><small>Por 24h corridas</small><strong>{{ $s['daily_limit'] }}</strong></div>
            <div><small>Por minuto</small><strong>{{ $s['per_tick_limit'] }}</strong></div>
        </div>
        <div class="ec-queue-lab-forecast">
            <div><span>Na primeira hora</span><strong>{{ $s['first_hour'] }} / {{ $s['requested'] }}</strong></div>
            <div class="ec-queue-lab-track"><i style="width: {{ round(100 * $s['first_hour'] / $s['requested']) }}%"></i></div>
            <div><span>Até meia-noite (informativo)</span><strong>{{ $s['first_calendar_day'] }}</strong></div>
            <div><span>Nas primeiras 24h</span><strong>{{ $s['first_24_hours'] }}</strong></div>
            <div><span>Projeção até 7 dias</span><strong>{{ $s['within_seven_days'] }} / {{ $s['requested'] }}</strong></div>
        </div>
        @if ($s['remaining'] > 0)
            <div class="ec-queue-lab-note is-warn">{{ $s['remaining'] }} número(s) permaneceriam pendentes após 7 dias nesse cenário.</div>
        @else
            <div class="ec-queue-lab-note">Lote virtual processado dentro dos sete dias simulados.</div>
        @endif
        <div class="ec-queue-lab-actual">
            <div class="ec-queue-lab-actual-title"><strong>Fila real · somente leitura</strong><button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">↻ Atualizar</button></div>
            <div><span>Pendentes</span><strong>{{ $actual['pending'] }}</strong></div>
            <div><span>Reservados / em envio</span><strong>{{ $actual['reserved'] + $actual['sending'] }}</strong></div>
            <div><span>Resultados incertos</span><strong class="{{ $actual['uncertain'] > 0 ? 'is-alert' : '' }}">{{ $actual['uncertain'] }}</strong></div>
            <div><span>Registros possivelmente presos</span><strong class="{{ $actual['stale_reserved'] + $actual['stale_sending'] > 0 ? 'is-alert' : '' }}">{{ $actual['stale_reserved'] + $actual['stale_sending'] }}</strong></div>
        </div>
        <small class="ec-queue-lab-disclaimer">Limites móveis de 60 min e 24h. Projeção hipotética a partir de {{ $s['forecast_starts_at'] }}. Não representa entregas reais, não considera outras campanhas nem envios já reservados. {{ $s['safe_mode'] ? 'Disparos comerciais bloqueados.' : 'Ambiente com envio comercial habilitado; esta simulação continua sem disparar.' }}</small>
    </div>
</section>
