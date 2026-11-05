<section class="wa6-recent" aria-label="Últimos telefones consultados" data-test="whatsapp-web-v6-recent">
    <div class="wa6-head">
        <div><h2>Últimos números consultados</h2><p>Número, resultado, conta utilizada e horário. Selecione um para abrir os detalhes.</p></div>
        <button type="button" class="wa6-text-action" wire:click="quickWebFilter('consulted')">Ver todos os consultados ›</button>
    </div>
    @if($this->webRecent->isEmpty())
        <div class="wa6-empty">Nenhuma consulta técnica registrada. Os primeiros resultados aparecerão aqui.</div>
    @else
        <div class="wa6-recent-list">
            @foreach($this->webRecent as $recent)
                @php
                    $recentLabel = match($recent->result) {
                        'found' => 'Encontrado', 'not_found' => 'Não localizado',
                        'inconclusive' => 'Inconclusivo', default => 'Falha na consulta',
                    };
                @endphp
                <button type="button" wire:key="wa6-recent-{{ $recent->id }}" wire:click="openWebRecent({{ $recent->id }})" class="wa6-recent-row">
                    <span class="wa6-recent-phone"><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($recent->e164, $recent->digits) }}</strong><small>{{ $recent->kind === 'mobile' ? 'Celular' : 'Telefone fixo' }} · {{ $recent->contacts_count }} contato(s)</small></span>
                    <span class="wa6-probe-badge wa6-probe-{{ $recent->result }}">{{ $recentLabel }}</span>
                    <span class="wa6-recent-meta">{{ $recent->session ? 'Conta '.$recent->session : 'Consulta anterior' }}<small>{{ \Carbon\Carbon::parse($recent->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</small></span>
                    <span class="wa6-chevron">›</span>
                </button>
            @endforeach
        </div>
    @endif
</section>
