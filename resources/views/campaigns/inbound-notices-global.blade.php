{{-- EC_INBOUND_V22_GLOBAL: retornos legiveis e revisao humana explicita; nao altera IMAP na renderizacao. --}}
@include('campaigns.inbound-notices-style')
@php
    $noticeLabels = [
        'absence' => 'Férias ou ausência',
        'address_change' => 'E-mail alterado',
        'routing' => 'Redirecionamento',
        'automatic' => 'Confirmação automática',
        'auto_reply' => 'Resposta automática',
        'reply_needs_review' => 'Possível resposta humana',
    ];
    $noticeLinks = [
        'reference_exact' => 'Mensagem vinculada por identificador e remetente',
        'reference_mismatch' => 'Remetente diferente — conferir',
        'sender_only' => 'Remetente reconhecido — conferir conteúdo',
    ];
    $campaignNames = \Illuminate\Support\Facades\DB::table('email_campaigns')
        ->whereIn('id', $this->inboundNotices->getCollection()->pluck('email_campaign_id')->unique()->all())
        ->pluck('name', 'id');
    $ecV22Manager = auth()->user() instanceof \App\Models\User && auth()->user()->isCommercialManager();
@endphp
<section class="ec-v22" aria-label="Retornos úteis de campanhas">
    <header class="ec-v22-head">
        <div>
            <p class="ec-v22-overline">Retornos automáticos úteis · monitoramento IMAP</p>
            <h2 class="ec-v22-title">Retornos que merecem atenção</h2>
            <p class="ec-v22-description">Respostas a conferir, férias, confirmações automáticas e mudanças de endereço. Tudo em um lugar, sem misturar devoluções técnicas.</p>
        </div>
        <div class="ec-v22-count"><strong>{{ number_format($this->inboundNotices->total(), 0, ',', '.') }}</strong> retornos úteis</div>
    </header>
    <div class="ec-v22-hint"><span class="ec-v22-hint-mark" aria-hidden="true">i</span><span>Caixa cheia, e-mail inválido e mensagens rejeitadas continuam em <strong>Devoluções</strong>. Uma possível resposta só entra na contagem comercial após verificação humana.</span></div>
    @error('noticeReview') <div class="ec-v22-alert ec-v22-alert--warn" role="alert">{{ $message }}</div> @enderror
    @if ($this->inboundNotices->isEmpty())
        <div class="ec-v22-empty"><strong>Nenhum retorno nesta seleção</strong><p>{{ $search !== '' ? 'Tente pesquisar outro e-mail ou assunto.' : 'Quando um aviso relevante chegar, ele aparecerá aqui.' }}</p></div>
    @else
    <div class="ec-v22-feed">
        @foreach ($this->inboundNotices as $notice)
            @php
                $ecV22Type = match ($notice->notice_type) {
                    'reply_needs_review' => 'review',
                    'address_change' => 'address',
                    default => 'auto',
                };
                $ecV22Confirmable = $ecV22Manager
                    && $notice->notice_type === 'reply_needs_review'
                    && in_array($notice->link_method, ['sender_only', 'reference_exact'], true);
            @endphp
            <article class="ec-v22-item" wire:key="ec-v22-global-{{ $notice->id }}">
                <div class="ec-v22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $notice->from_email, 0, 1)) }}</div>
                <div class="ec-v22-body">
                    <div class="ec-v22-meta">
                        <span class="ec-v22-pill ec-v22-pill--{{ $ecV22Type }}">{{ $noticeLabels[$notice->notice_type] ?? 'Aviso para revisão' }}</span>
                        <span class="ec-v22-timestamp">{{ \App\Support\CampaignLocalTime::format($notice->received_at, 'Data indisponível') }}</span>
                    </div>
                    <h3 class="ec-v22-subject">{{ $notice->subject ?: 'Sem assunto' }}</h3>
                    <p class="ec-v22-email">De: {{ $notice->from_email }}</p>
                    <div class="ec-v22-foot">
                        <a href="{{ route('campaigns.index', ['campanha' => $notice->email_campaign_id, 'aba' => 'replies']) }}" wire:navigate>{{ $campaignNames[(int) $notice->email_campaign_id] ?? ('Campanha #'.$notice->email_campaign_id) }}</a>
                        <span>{{ $noticeLinks[$notice->link_method] ?? 'Vínculo a conferir' }}</span>
                    </div>
                    @if ($ecV22Confirmable)
                        <div class="ec-v22-actions">
                            <button type="button" class="ec-v22-button" wire:click="requestNoticeReview({{ (int) $notice->id }})" wire:loading.attr="disabled" wire:target="requestNoticeReview">Confirmar como resposta humana</button>
                            <span class="ec-v22-inline-id">Somente depois de ler a mensagem no Outlook</span>
                        </div>
                    @elseif ($notice->requires_review)
                        <div class="ec-v22-actions"><span class="ec-v22-inline-id">Revisar no Outlook antes de qualquer alteração no contato</span></div>
                    @endif
                    @if ($reviewNoticeId === (int) $notice->id && $ecV22Confirmable)
                        <div class="ec-v22-review" role="group" aria-label="Confirmar resposta humana">
                            <strong>Você confirmou que esta mensagem foi escrita por uma pessoa?</strong>
                            <p>Confira remetente, conteúdo e campanha no Outlook. Confirmar registra uma resposta comercial e impede os próximos follow-ups automáticos a este destinatário. A mensagem original não será alterada.</p>
                            <div class="ec-v22-actions">
                                <button type="button" class="ec-v22-button" wire:click="confirmNoticeHuman" wire:loading.attr="disabled" wire:target="confirmNoticeHuman">Sim, conferi e confirmo</button>
                                <button type="button" class="ec-v22-button ec-v22-button--quiet" wire:click="cancelNoticeReview">Cancelar</button>
                            </div>
                        </div>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    @if ($this->inboundNotices->hasPages()) <div class="ec-v22-page">{{ $this->inboundNotices->links() }}</div> @endif
    @endif
</section>

{{-- Avisos com assunto explicito de mudanca de endereco, mas SEM remetente destinatario confirmado. --}}
@php
    $ecV22CanRead = \Illuminate\Support\Facades\Schema::hasTable('email_campaign_unlinked_notices');
    $ecV22Term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($search), 0, 100));
    $ecV22Unlinked = $ecV22CanRead
        ? \Illuminate\Support\Facades\DB::table('email_campaign_unlinked_notices')
            ->when($ecV22Term !== '', static function ($query) use ($ecV22Term): void {
                $query->where(static function ($filter) use ($ecV22Term): void {
                    $filter->where('from_email', 'like', '%'.$ecV22Term.'%')
                        ->orWhere('subject', 'like', '%'.$ecV22Term.'%');
                });
            })->orderByDesc('received_at')->limit(40)->get()
        : collect();
@endphp
@if ($ecV22Unlinked->isNotEmpty())
<section class="ec-v22 ec-v22--unlinked" id="ec-v21-unlinked" aria-label="Avisos de e-mail alterado — vínculo para conferir">
    <header class="ec-v22-head">
        <div><p class="ec-v22-overline">Atenção · vínculo não confirmado</p><h2 class="ec-v22-title">Endereços alterados para conferir</h2><p class="ec-v22-description">O assunto cita uma prospecção, mas não confirma a identidade do destinatário. Nenhum contato ou e-mail enviado é modificado automaticamente.</p></div>
        <div class="ec-v22-count"><strong>{{ $ecV22Unlinked->count() }}</strong> aviso(s)</div>
    </header>
    <div class="ec-v22-feed">
        @foreach ($ecV22Unlinked as $ecV22Notice)
        <article class="ec-v22-item" wire:key="ec-v22-unlinked-{{ $ecV22Notice->id }}">
            <div class="ec-v22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $ecV22Notice->from_email, 0, 1)) }}</div>
            <div class="ec-v22-body">
                <div class="ec-v22-meta"><span class="ec-v22-pill ec-v22-pill--address">E-mail alterado · verificar</span><span class="ec-v22-timestamp">{{ \App\Support\CampaignLocalTime::format($ecV22Notice->received_at, 'Data indisponível') }}</span></div>
                <h3 class="ec-v22-subject">{{ $ecV22Notice->subject }}</h3>
                <p class="ec-v22-email">De: {{ $ecV22Notice->from_email }}</p>
                <div class="ec-v22-foot">
                    @if ($ecV22Notice->candidate_campaign_id !== null)
                        <a href="{{ route('campaigns.index', ['campanha' => $ecV22Notice->candidate_campaign_id, 'aba' => 'replies']) }}" wire:navigate>Campanha #{{ $ecV22Notice->candidate_campaign_id }} (possível)</a>
                    @else
                        <span>Campanha não identificada com segurança</span>
                    @endif
                    <span>Conferir o novo endereço no corpo do e-mail</span>
                </div>
            </div>
        </article>
        @endforeach
    </div>
    @if ($ecV22Unlinked->count() === 40) <p class="ec-v22-more">Exibindo os 40 avisos mais recentes. Use a busca para localizar os demais.</p> @endif
</section>
@endif
