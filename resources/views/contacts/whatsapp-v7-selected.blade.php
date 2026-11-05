@php
    $wa7Probe = $this->webProbePage[(int) $selected->id] ?? null;
    $wa7AutoConfirmed = (string) ($selected->evidence ?? '') === 'web_found_unofficial';
@endphp
@if($wa7AutoConfirmed)
    <section class="wa7-phone-proof wa7-phone-proof-good" aria-label="Origem da confirmação">
        <strong>✓ WhatsApp identificado pela integração Web</strong>
        <p>O resultado positivo foi registrado na Central e propagado aos contatos vinculados. É uma confirmação técnica de uma biblioteca não oficial, sujeita a revisão manual.</p>
        @if($wa7Probe)<small>Última consulta: {{ \Carbon\Carbon::parse($wa7Probe->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $wa7Probe->session ? 'Conta '.$wa7Probe->session : 'Consulta anterior' }}</small>@endif
    </section>
@elseif($wa7Probe)
    <section class="wa7-phone-proof {{ $wa7Probe->result === 'not_found' ? 'wa7-phone-proof-warn' : '' }}" aria-label="Última consulta técnica">
        <strong>{{ match($wa7Probe->result) { 'found' => 'WhatsApp localizado; status humano preservado', 'not_found' => 'Não localizado na consulta', 'inconclusive' => 'Consulta inconclusiva', default => 'Falha na consulta' } }}</strong>
        <p>{{ match($wa7Probe->result) { 'found' => 'A consulta foi positiva, mas havia uma decisão registrada que foi preservada. Revise manualmente se necessário.', 'not_found' => 'A consulta não confirmou presença. Isso não é prova de ausência e não altera um status anterior.', default => 'Nenhuma alteração no status do contato foi feita.' } }}</p>
        <small>{{ \Carbon\Carbon::parse($wa7Probe->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</small>
    </section>
@endif
