@php
    $wa7Probe = $this->webProbePage[(int) $selected->id] ?? null;
    $wa7TechnicalPositive = (string) ($selected->evidence ?? '') === 'web_found_unofficial';
@endphp
@if($wa7TechnicalPositive)
    <section class="wa7-phone-proof wa7-phone-proof-warn" aria-label="Indício técnico">
        <strong>Indício positivo da integração Web — não confirmado</strong>
        <p>O resultado de uma biblioteca não oficial é apenas técnico. Não confirma uma decisão manual nem autoriza o uso operacional deste número; confira no aplicativo.</p>
        @if($wa7Probe)<small>Última consulta: {{ \Carbon\Carbon::parse($wa7Probe->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $wa7Probe->session ? 'Conta '.$wa7Probe->session : 'Consulta anterior' }}</small>@endif
    </section>
@elseif($wa7Probe)
    <section class="wa7-phone-proof {{ $wa7Probe->result === 'not_found' ? 'wa7-phone-proof-warn' : '' }}" aria-label="Última consulta técnica">
        <strong>{{ match($wa7Probe->result) { 'found' => 'WhatsApp localizado; status humano preservado', 'not_found' => 'Não localizado na consulta', 'inconclusive' => 'Consulta inconclusiva', default => 'Falha na consulta' } }}</strong>
        <p>{{ match($wa7Probe->result) { 'found' => 'A consulta foi positiva, mas havia uma decisão registrada que foi preservada. Revise manualmente se necessário.', 'not_found' => 'A consulta não confirmou presença. Isso não é prova de ausência e não altera um status anterior.', default => 'Nenhuma alteração no status do contato foi feita.' } }}</p>
        <small>{{ \Carbon\Carbon::parse($wa7Probe->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</small>
    </section>
@endif
