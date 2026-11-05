@php
    $webResult = $this->webProbePage[(int) $selected->id] ?? null;
    $webTitles = [
        'found' => 'Possível conta encontrada pelo WhatsApp Web',
        'not_found' => 'Conta não localizada na consulta',
        'inconclusive' => 'Consulta inconclusiva',
        'error' => 'Falha na consulta',
    ];
@endphp
@if ($webResult)
    <section class="wpr-web-v4-result wpr-web-v4-result--{{ $webResult->result }}" aria-label="Última consulta experimental">
        <strong>{{ $webTitles[$webResult->result] ?? 'Resultado experimental' }}</strong>
        <p>
            {{ match ($webResult->result) {
                'found' => 'A biblioteca retornou sinal positivo. Confirme no aplicativo antes de marcar “Tem WhatsApp”.',
                'not_found' => 'Indício de ausência, não prova definitiva. Não é marcado automaticamente como “Não possui”.',
                'inconclusive' => 'Não foi possível determinar se o número possui conta. Mantenha como não verificado.',
                default => 'A consulta não terminou corretamente. Nenhum status de contato foi alterado.'
            } }}
        </p>
        <small class="wpr-web-v4-meta">Consulta experimental: {{ \Carbon\Carbon::parse($webResult->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ (int) $webResult->attempts }} tentativa(s)</small>
    </section>
@elseif ($selected->kind !== 'review')
    <section class="wpr-web-v4-result" aria-label="Consulta experimental ainda não executada">
        <strong>Ainda não consultado via WhatsApp Web</strong>
        <p>Você pode incluí-lo em um lote piloto pelo terminal. O resultado aparecerá aqui após atualizar a página.</p>
    </section>
@endif
