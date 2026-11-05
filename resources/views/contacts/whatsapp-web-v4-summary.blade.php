<section class="wpr-web-v4-summary" aria-label="Consulta experimental não oficial pelo WhatsApp Web" data-test="whatsapp-unofficial-probe-v4">
    <div>
        <span class="wpr-web-v4-eyebrow">INTEGRAÇÃO EXPERIMENTAL · WHATSAPP WEB</span>
        <h3>Consulta de existência por QR Code, sem enviar mensagens</h3>
        <p>O piloto usa uma biblioteca não oficial em um processo separado no Ubuntu. Os resultados são indícios técnicos: não substituem a confirmação humana e não mudam o status confirmado em Contatos. Nenhuma varredura é executada automaticamente pelo agendador.</p>
        <small class="wpr-web-v4-tip">Execute até 10 consultas por vez, com uma conta de teste, pelo terminal. A conexão só ocorre quando você inicia e escaneia o QR Code.</small>
    </div>
    <div class="wpr-web-v4-stats" aria-label="Resultados do piloto">
        <div class="wpr-web-v4-stat"><small>Encontrados</small><strong>{{ number_format($this->webProbeTotals['found'], 0, ',', '.') }}</strong></div>
        <div class="wpr-web-v4-stat"><small>Não localizados</small><strong>{{ number_format($this->webProbeTotals['not_found'], 0, ',', '.') }}</strong></div>
        <div class="wpr-web-v4-stat"><small>Inconclusivos / falhas</small><strong>{{ number_format($this->webProbeTotals['inconclusive'] + $this->webProbeTotals['error'], 0, ',', '.') }}</strong></div>
    </div>
</section>
