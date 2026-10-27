{{-- EC_CAMPAIGNS_REVIEW_RECIPIENTS_V73: leitura local; esta prévia não grava ou dispara e-mails. --}}
@php
    $reviewPage = min(max(1, $reviewRecipientsPage), $this->reviewRecipientPageCount());
    $reviewPages = $this->reviewRecipientPageCount();
    $reviewRows = $this->reviewRecipientPreview;
    $reviewStart = count($audienceKeys) > 0 ? (($reviewPage - 1) * $this->reviewRecipientsPerPage()) + 1 : 0;
    $reviewEnd = min(count($audienceKeys), $reviewPage * $this->reviewRecipientsPerPage());
@endphp
<section class="ec73-recipients" aria-labelledby="ec73-recipients-title" data-ec73-review-recipients>
    <div class="ec73-recipients-heading">
        <div>
            <span class="ec73-kicker">CONFERÊNCIA DOS DESTINATÁRIOS</span>
            <h3 id="ec73-recipients-title">Para quais e-mails a campanha será enviada?</h3>
            <p>Verifique nomes, empresas e endereços antes de confirmar. Somente endereços válidos e não bloqueados poderão receber mensagens.</p>
        </div>
        <span class="ec73-recipients-count">{{ number_format(count($audienceKeys), 0, ',', '.') }} selecionado(s)</span>
    </div>

    @if ($audienceKeys === [])
        <div class="ec73-recipient-empty">Nenhum destinatário selecionado. Volte à primeira etapa para escolher os contatos.</div>
    @else
        <div class="ec73-recipients-list" role="list" aria-label="Endereços selecionados nesta página">
            @forelse ($reviewRows as $person)
                <div class="ec73-recipient-row" role="listitem">
                    <span class="ec73-recipient-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim((string) ($person['contact_name'] ?: $person['email'])), 0, 1)) }}</span>
                    <span class="ec73-recipient-identity">
                        <strong>{{ $person['contact_name'] ?: 'Contato sem nome' }}</strong>
                        <span>{{ $person['email'] }}</span>
                        @if ($person['company_name'] !== '')<small>{{ $person['company_name'] }}</small>@endif
                    </span>
                    <span class="ec73-recipient-status {{ $person['suppressed'] ? 'is-blocked' : 'is-eligible' }}">
                        {{ $person['suppressed'] ? 'Bloqueado' : 'Elegível' }}
                    </span>
                </div>
            @empty
                <div class="ec73-recipient-empty">Não foram encontrados e-mails válidos para os contatos desta página. Confira os dados cadastrais na etapa de seleção.</div>
            @endforelse
        </div>
        @if ($reviewPages > 1)
            <div class="ec73-recipients-pagination">
                <span>Seleção {{ $reviewStart }}–{{ $reviewEnd }} de {{ number_format(count($audienceKeys), 0, ',', '.') }} • Página {{ $reviewPage }} de {{ $reviewPages }}</span>
                <div>
                    <button type="button" wire:click="changeReviewRecipientsPage({{ $reviewPage - 1 }})" @disabled($reviewPage === 1) aria-label="Página anterior dos destinatários">Anterior</button>
                    <button type="button" wire:click="changeReviewRecipientsPage({{ $reviewPage + 1 }})" @disabled($reviewPage === $reviewPages) aria-label="Próxima página dos destinatários">Próxima</button>
                </div>
            </div>
        @endif
        <p class="ec73-recipient-disclaimer">Prévia por página. A verificação final, a remoção de duplicidades e os bloqueios de descadastro são aplicados ao salvar o rascunho. Nenhum e-mail é enviado nesta prévia.</p>
    @endif
</section>
