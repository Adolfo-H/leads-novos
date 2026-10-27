{{-- EC_CAMPAIGNS_SEND_CONFIRMATION_V73: substitui wire:confirm nativo por confirmação dentro do aplicativo. --}}
@if ($sendConfirmationOpen)
    <div class="ec73-confirm-backdrop" wire:key="ec73-send-confirm-modal" role="presentation">
        <section class="ec73-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="ec73-confirm-title" aria-describedby="ec73-confirm-description">
            <div class="ec73-confirm-heading">
                <span class="ec73-confirm-icon" aria-hidden="true">✉</span>
                <button type="button" class="ec73-modal-close" wire:click="cancelSendConfirmation" aria-label="Fechar confirmação de envio">×</button>
            </div>
            <span class="ec73-kicker">ÚLTIMA CONFERÊNCIA</span>
            <h2 id="ec73-confirm-title">Confirmar o envio da campanha</h2>
            <p id="ec73-confirm-description">Confira os destinatários e o assunto. Ao confirmar, o Prospector autorizará o processamento pela fila, respeitando os limites e o descadastro.</p>
            <dl class="ec73-confirm-facts">
                <div><dt>Campanha</dt><dd>{{ $name }}</dd></div>
                <div><dt>Assunto</dt><dd>{{ $subject }}</dd></div>
                <div><dt>Contatos selecionados</dt><dd>{{ number_format(count($audienceKeys), 0, ',', '.') }}</dd></div>
                <div><dt>Remetente</dt><dd>{{ config('campaign_mail.from_address') ?: 'Não configurado' }}</dd></div>
            </dl>
            <div class="ec73-confirm-preview">
                <strong>Prévia dos e-mails selecionados</strong>
                <ul>
                    @foreach (array_slice($this->reviewRecipientPreview, 0, 6) as $person)
                        <li><span>{{ $person['email'] }}</span>@if ($person['suppressed'])<em>Bloqueado</em>@endif</li>
                    @endforeach
                </ul>
                <small>Consulte a lista paginada na etapa de revisão para conferir todos os endereços. Os destinatários elegíveis são validados novamente ao salvar.</small>
            </div>
            <div class="ec73-confirm-warning">Esta ação pode iniciar envios reais. Depois que o servidor SMTP aceita uma mensagem, não é possível desfazer o envio.</div>
            <div class="ec73-confirm-actions">
                <button type="button" class="ec73-button-secondary" wire:click="cancelSendConfirmation">Voltar e revisar</button>
                <button type="button" class="ec73-button-primary" wire:click="confirmSendCampaign" wire:loading.attr="disabled" wire:target="confirmSendCampaign">
                    <span wire:loading.remove wire:target="confirmSendCampaign">Confirmar e iniciar</span>
                    <span wire:loading wire:target="confirmSendCampaign">Confirmando…</span>
                </button>
            </div>
        </section>
    </div>
@endif
