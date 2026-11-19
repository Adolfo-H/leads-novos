@php
    $wa7Probe = $this->webProbePage[(int) $selected->id] ?? null;
    // EC_WA_WEB_RESULT_V1: apenas mostrar positivo quando nao houve consulta posterior negativa.
    $wa7TechnicalPositive = (string) ($selectedView['source'] ?? '') === 'web_found';
@endphp
@if($wa7TechnicalPositive)
    <section class="wa7-phone-proof wa-web-robot-positive" aria-label="Resultado positivo da consulta Web">
        <strong>Tem WhatsApp · detecção automática do robô</strong>
        <p>Resultado positivo registrado automaticamente no índice. Não exige confirmação manual nesta central. A fonte é uma integração não oficial; não foi criado um registro de confirmação humana.</p>
        @if($wa7Probe)<small>Última consulta: {{ \Carbon\Carbon::parse($wa7Probe->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $wa7Probe->session ? 'Conta '.$wa7Probe->session : 'Consulta anterior' }}</small>@endif
    </section>
@elseif($wa7Probe)
    <section class="wa7-phone-proof {{ $wa7Probe->result === 'not_found' ? 'wa7-phone-proof-warn' : '' }}" aria-label="Última consulta técnica">
        <strong>{{ match($wa7Probe->result) { 'found' => 'WhatsApp localizado; status humano preservado', 'not_found' => 'Não localizado na consulta', 'inconclusive' => 'Consulta inconclusiva', default => 'Falha na consulta' } }}</strong>
        <p>{{ match($wa7Probe->result) { 'found' => 'A consulta foi positiva, mas havia uma decisão registrada que foi preservada.', 'not_found' => 'A consulta Web não localizou conta. Isso não é confirmação manual de ausência e não altera um status anterior.', default => 'Nenhuma alteração no status do contato foi feita.' } }}</p>
        <small>{{ \Carbon\Carbon::parse($wa7Probe->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</small>
    </section>
@endif
