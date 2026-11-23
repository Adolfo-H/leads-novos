{{-- EC_RESPONSES_V23_GLOBAL: default unified view; previous dedicated filters remain available. --}}
@include('campaigns.inbound-notices-style')
@include('campaigns.responses-combined-style')
@php
    $ecV23Global = $this->globalResponseTotals;
    $ecV23Entries = $this->allResponses;
    $ecV23Names = \Illuminate\Support\Facades\DB::table('email_campaigns')
        ->whereIn('id', $ecV23Entries->getCollection()->pluck('email_campaign_id')->unique()->all())
        ->pluck('name', 'id');
    $ecV23Labels = [
        'absence' => 'Férias / ausência',
        'address_change' => 'E-mail alterado',
        'routing' => 'Redirecionamento',
        'automatic' => 'Confirmação automática',
        'auto_reply' => 'Resposta automática',
        'reply_needs_review' => 'Possível resposta humana',
    ];
@endphp
<section class="ec-v22 ec-v23" data-ec-v23-unified-global="true" aria-label="Todas as respostas e retornos úteis">
    <header class="ec-v22-head">
        <div><p class="ec-v22-overline">CAIXA DE RESPOSTAS · TODAS AS CAMPANHAS</p><h2 class="ec-v22-title">Todas as respostas</h2><p class="ec-v22-description">Respostas humanas e retornos úteis reunidos por data. Selecione Comerciais ou Retornos úteis para ações específicas.</p></div>
        <div class="ec-v22-count"><strong>{{ number_format($ecV23Global['total'], 0, ',', '.') }}</strong> no total</div>
    </header>
    <div class="ec-v23-breakdown" aria-label="Resumo de respostas">
        <span class="ec-v23-stat"><strong>{{ number_format($ecV23Global['human'], 0, ',', '.') }}</strong> comerciais</span>
        <span class="ec-v23-stat"><strong>{{ number_format($ecV23Global['useful'], 0, ',', '.') }}</strong> retornos úteis</span>
        @if ($search !== '')<span class="ec-v23-stat"><strong>{{ $ecV23Entries->total() }}</strong> encontrados na pesquisa</span>@endif
    </div>
    <p class="ec-v23-disclaimer">O número total representa mensagens registradas, não contatos únicos nem respostas humanas. Devoluções técnicas e avisos sem vínculo confirmado não são contabilizados.</p>
    @if ($ecV23Entries->isEmpty())
        <div class="ec-v22-empty"><strong>{{ $search !== '' ? 'Nenhuma conversa encontrada' : 'Nenhuma resposta encontrada' }}</strong><p>{{ $search !== '' ? 'Tente buscar por outro remetente ou assunto.' : 'As respostas de campanhas aparecerão aqui.' }}</p></div>
    @else
        <div class="ec-v22-feed">
            @foreach ($ecV23Entries as $ecEntry)
                @php
                    $ecIsHuman = $ecEntry->entry_kind === 'human';
                    $ecBadge = $ecIsHuman ? 'human' : (match ($ecEntry->notice_type) {
                        'reply_needs_review' => 'review',
                        'address_change' => 'address',
                        default => 'auto',
                    });
                    $ecLabel = $ecIsHuman ? 'Resposta comercial' : ($ecV23Labels[$ecEntry->notice_type] ?? 'Retorno útil');
                @endphp
                <article class="ec-v22-item" wire:key="ec-v23-all-{{ $ecEntry->entry_kind }}-{{ $ecEntry->row_id }}">
                    <div class="ec-v22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $ecEntry->from_email, 0, 1)) }}</div>
                    <div class="ec-v22-body">
                        <div class="ec-v22-meta"><span class="ec-v22-pill ec-v22-pill--{{ $ecBadge }}">{{ $ecLabel }}</span><time class="ec-v22-timestamp">{{ \App\Support\CampaignLocalTime::format($ecEntry->received_at, '—') }}</time></div>
                        <h3 class="ec-v22-subject">{{ $ecEntry->subject ?: 'Sem assunto' }}</h3>
                        <p class="ec-v22-email">De: {{ $ecEntry->from_email }}</p>
                        <div class="ec-v22-foot">
                            <a href="{{ route('campaigns.index', ['campanha' => $ecEntry->email_campaign_id, 'aba' => 'replies']) }}">{{ $ecV23Names[(int) $ecEntry->email_campaign_id] ?? ('Campanha #'.$ecEntry->email_campaign_id) }}</a>
                            @if ($ecIsHuman)
                                <button type="button" class="ec-v23-link-button" wire:click="chooseKind('commercial')">Ver em Comerciais</button>
                            @else
                                <button type="button" class="ec-v23-link-button" wire:click="chooseKind('notices')">Ver em Retornos úteis</button>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        @if ($ecV23Entries->hasPages()) <div class="ec-v22-page">{{ $ecV23Entries->links() }}</div> @endif
    @endif
</section>
