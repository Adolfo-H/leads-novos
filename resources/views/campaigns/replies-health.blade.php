{{-- V77 — indicadores locais; nenhum acesso IMAP ocorre durante a renderização. --}}
@php $ec77Health = $this->imapStatus; @endphp
<div class="ec77-reply-health" data-campaign-imap-health-v77>
    <div>
        <div class="ec77-reply-health-title">
            <strong>Sincronização de respostas</strong>
            <span data-state="{{ $ec77Health['state'] }}">{{ $ec77Health['label'] }}</span>
        </div>
        <small>Caixa IMAP monitorada: {{ config('campaign_mail.imap_username') ?: 'Não configurada' }} · Última sincronização: {{ $ec77Health['last_sync_at'] ?? 'Nunca registrada' }} · Respostas comerciais: {{ $ec77Health['campaign_replies'] }} · Testes rastreados: {{ $ec77Health['tracked_replies'] }}</small>
        @if ($ec77Health['state'] !== 'healthy')
            <p>As respostas chegam à caixa de e-mail, mas só aparecem aqui quando o leitor IMAP da conta de resposta está configurado e sincronizando.</p>
        @else
            <p>Somente respostas vinculadas a mensagens enviadas pelo Prospector são identificadas automaticamente.</p>
        @endif
    </div>
    <div class="ec77-reply-health-actions">
        <button type="button" class="ec77-refresh" wire:click="refreshImapStatus" wire:loading.attr="disabled" wire:target="refreshImapStatus">Atualizar estado</button>
    </div>
</div>
