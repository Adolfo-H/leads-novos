{{-- EC_RESPONSES_V23_CAMPAIGN: one chronological and paginated list for humans + useful notices. --}}
@include('campaigns.inbound-notices-style')
@include('campaigns.responses-combined-style')
@php
    $ecV23Totals = $this->responseTotals;
    $ecV23Entries = $this->combinedResponses;
    $ecV23Labels = [
        'absence' => 'Férias / ausência',
        'address_change' => 'E-mail alterado',
        'routing' => 'Redirecionamento',
        'automatic' => 'Confirmação automática',
        'auto_reply' => 'Resposta automática',
        'reply_needs_review' => 'Possível resposta humana',
    ];
@endphp
<section class="ec-v22 ec-v23" data-ec-v23-unified-campaign="true" aria-label="Todas as respostas e retornos úteis da campanha">
    <header class="ec-v22-head">
        <div>
            <p class="ec-v22-overline">CAMPANHA SELECIONADA · RESPOSTAS</p>
            <h2 class="ec-v22-title">Todas as respostas</h2>
            <p class="ec-v22-description">Respostas comerciais e retornos automáticos úteis, juntos por data. Cada item mantém sua classificação original.</p>
        </div>
        <div class="ec-v22-count"><strong>{{ number_format($ecV23Totals['total'], 0, ',', '.') }}</strong> no total</div>
    </header>
    <div class="ec-v23-breakdown" aria-label="Resumo das mensagens">
        <span class="ec-v23-stat"><strong>{{ number_format($ecV23Totals['human'], 0, ',', '.') }}</strong> {{ $ecV23Totals['human'] === 1 ? 'resposta comercial' : 'respostas comerciais' }}</span>
        <span class="ec-v23-stat"><strong>{{ number_format($ecV23Totals['useful'], 0, ',', '.') }}</strong> {{ $ecV23Totals['useful'] === 1 ? 'retorno útil' : 'retornos úteis' }}</span>
    </div>
    {{-- Compatibilidade com a nomenclatura operacional e os testes da V2.1, mesmo com a listagem unificada. --}}
    <p class="ec-v23-disclaimer"><strong>Retornos automáticos e para revisão</strong> aparecem nesta mesma lista, com o tipo identificado em cada cartão. O total inclui mensagens humanas e avisos úteis, mas somente respostas confirmadas contam como respostas comerciais. Caixa cheia, spam e falhas de entrega permanecem em Devoluções.</p>
    @if ($ecV23Entries->isEmpty())
        <div class="ec-v22-empty"><strong>Nenhuma resposta identificada pelo IMAP até agora.</strong><p>Quando houver uma resposta humana ou retorno útil vinculado, ele aparecerá aqui.</p></div>
    @else
        <div class="ec-v22-feed">
            @foreach ($ecV23Entries as $ecEntry)
                @php
                    $ecIsHuman = $ecEntry->entry_kind === 'human';
                    $ecLabel = $ecIsHuman ? 'Resposta comercial' : ($ecV23Labels[$ecEntry->notice_type] ?? 'Retorno útil');
                    $ecBadge = $ecIsHuman ? 'human' : (match ($ecEntry->notice_type) {
                        'reply_needs_review' => 'review',
                        'address_change' => 'address',
                        default => 'auto',
                    });
                @endphp
                <article class="ec-v22-item" wire:key="ec-v23-campaign-{{ $ecEntry->entry_kind }}-{{ $ecEntry->row_id }}">
                    <div class="ec-v22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $ecEntry->from_email, 0, 1)) }}</div>
                    <div class="ec-v22-body">
                        <div class="ec-v22-meta">
                            <span class="ec-v22-pill ec-v22-pill--{{ $ecBadge }}">{{ $ecLabel }}</span>
                            <time class="ec-v22-timestamp">{{ \App\Support\CampaignLocalTime::format($ecEntry->received_at, 'Data indisponível') }}</time>
                        </div>
                        <h3 class="ec-v22-subject">{{ $ecEntry->subject ?: 'Sem assunto' }}</h3>
                        <p class="ec-v22-email">De: {{ $ecEntry->from_email }}</p>
                        <div class="ec-v22-foot">
                            @if ($ecIsHuman)
                                <span>Resposta humana registrada</span>
                                <a href="{{ route('campaigns.replies') }}">Abrir caixa de respostas</a>
                            @elseif ($ecEntry->notice_type === 'reply_needs_review')
                                <span>Confira o conteúdo no Outlook</span>
                                <a href="{{ route('campaigns.replies') }}">Revisar em Respostas</a>
                            @else
                                <span>Aviso automático ou informativo; não conta como resposta humana</span>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        @if ($ecV23Entries->hasPages())
            <nav class="ec-v23-pager" aria-label="Páginas de respostas da campanha">
                <span>Página {{ $ecV23Entries->currentPage() }} de {{ $ecV23Entries->lastPage() }}</span>
                <div>
                    @if ($ecV23Entries->onFirstPage())
                        <span aria-disabled="true">Anterior</span>
                    @else
                        <a href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'replies', 'paginaRespostas' => $ecV23Entries->currentPage() - 1]) }}">Anterior</a>
                    @endif
                    @if ($ecV23Entries->hasMorePages())
                        <a href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'replies', 'paginaRespostas' => $ecV23Entries->currentPage() + 1]) }}">Próxima</a>
                    @else
                        <span aria-disabled="true">Próxima</span>
                    @endif
                </div>
            </nav>
        @endif
    @endif
</section>

{{-- A message without a confirmed recipient is visible for review but is NOT a confirmed campaign response. --}}
@php
    $ecV23Unlinked = \Illuminate\Support\Facades\Schema::hasTable('email_campaign_unlinked_notices')
        ? \Illuminate\Support\Facades\DB::table('email_campaign_unlinked_notices')
            ->where('candidate_campaign_id', (int) $selected->id)
            ->orderByDesc('received_at')->limit(15)->get()
        : collect();
@endphp
@if ($ecV23Unlinked->isNotEmpty())
<section class="ec-v22 ec-v22--unlinked" data-ec-v23-unlinked-campaign="true" aria-label="Possíveis avisos sem destinatário confirmado">
    <header class="ec-v22-head"><div><p class="ec-v22-overline">REVISAR · NÃO CONTABILIZADOS</p><h3 class="ec-v22-title">E-mails alterados — associação não confirmada</h3><p class="ec-v22-description">O assunto sugere uma campanha, mas o destinatário original não foi confirmado. Estes avisos não entram no total acima.</p></div><div class="ec-v22-count"><strong>{{ $ecV23Unlinked->count() }}</strong> para conferir</div></header>
    <div class="ec-v22-feed">
        @foreach ($ecV23Unlinked as $ecV23Notice)
            <article class="ec-v22-item" wire:key="ec-v23-unlinked-{{ $ecV23Notice->id }}">
                <div class="ec-v22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $ecV23Notice->from_email, 0, 1)) }}</div>
                <div class="ec-v22-body"><div class="ec-v22-meta"><span class="ec-v22-pill ec-v22-pill--address">E-mail alterado · revisar vínculo</span><time class="ec-v22-timestamp">{{ \App\Support\CampaignLocalTime::format($ecV23Notice->received_at, '—') }}</time></div><h3 class="ec-v22-subject">{{ $ecV23Notice->subject }}</h3><p class="ec-v22-email">De: {{ $ecV23Notice->from_email }}</p><div class="ec-v22-foot"><span>Conferir no Outlook antes de qualquer atualização de contato</span></div></div>
            </article>
        @endforeach
    </div>
</section>
@endif
