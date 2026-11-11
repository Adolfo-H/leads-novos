{{-- CAMPANHAS_FIGMA_V68_M01 — nova página inicial, integrada ao Livewire real. --}}
{{-- EC_CAMPAIGN_GROUPED_V1: campanhas originais; rodadas so dentro de cada grupo. --}}
@php
    $ec68PageSize = 8;
    $ec68Matched = $campaignsList->count();
    $ec68Pages = max(1, (int) ceil($ec68Matched / $ec68PageSize));
    $ec68CurrentPage = min(max(1, (int) $campaignTablePage), $ec68Pages);
    $ec68Rows = $campaignsList->slice(($ec68CurrentPage - 1) * $ec68PageSize, $ec68PageSize);
    $ec68Fmt = static fn ($value): string => number_format((int) $value, 0, ',', '.');
    // Uma consulta em lote para mostrar agendamentos nas campanhas da pagina.
    $ec80Plans = $ec68Rows->isEmpty()
        ? collect()
        : \Illuminate\Support\Facades\DB::table('email_campaign_sequences')
            ->whereIn('email_campaign_id', $ec68Rows->pluck('id')->all())
            ->orderByDesc('id')->get()->unique('email_campaign_id')->keyBy('email_campaign_id');
@endphp
<section class="ec68-home" aria-label="Visão geral de campanhas" data-ec68-screen="M01" data-ec80-grouped="v1" wire:poll.60s>
    <style>
    [data-ec80-grouped="v1"] .ec80-subline { display:block; font-size:11px; color:#718699; margin-top:5px; white-space:normal; }
    [data-ec80-grouped="v1"] .ec80-rounds-row > td { padding:0 14px 15px !important; border-top:0; }
    [data-ec80-grouped="v1"] .ec80-rounds-details { border:1px solid #d8e8e5; background:#f8fbfa; border-radius:10px; overflow:hidden; }
    [data-ec80-grouped="v1"] .ec80-rounds-details summary { cursor:pointer; list-style:revert; padding:12px 15px; color:#086c62; font-size:12px; display:flex; gap:12px; justify-content:space-between; flex-wrap:wrap; }
    [data-ec80-grouped="v1"] .ec80-rounds-details summary > strong { font-weight:600; color:#365f62; }
    [data-ec80-grouped="v1"] .ec80-rounds-items { padding:0 14px 12px; display:grid; gap:8px; }
    [data-ec80-grouped="v1"] .ec80-round-item { display:flex; align-items:center; gap:12px; padding:12px; border:1px solid #dfebea; border-radius:9px; background:white; }
    [data-ec80-grouped="v1"] .ec80-round-number { width:30px; height:30px; flex:0 0 30px; display:grid; place-items:center; border-radius:50%; background:#e0f4ef; color:#006b62; font-size:12px; font-weight:700; }
    [data-ec80-grouped="v1"] .ec80-round-item > div { min-width:0; flex:1; }
    [data-ec80-grouped="v1"] .ec80-round-item strong, [data-ec80-grouped="v1"] .ec80-round-item small { display:block; line-height:1.5; overflow-wrap:anywhere; }
    [data-ec80-grouped="v1"] .ec80-round-item strong { font-size:12px; color:#143c50; }
    [data-ec80-grouped="v1"] .ec80-round-item small { font-size:11px; color:#627987; margin-top:3px; }
    /* EC_CAMPAIGN_DIRECT_LINK_V905 */
    [data-ec80-grouped="v1"] .ec80-round-item :is(button,a) {display:inline-flex;text-decoration:none; flex:0 0 auto; color:#006e65; background:#e6f5f1; border:1px solid #cbe8e1; border-radius:8px; padding:8px 12px; font-size:11px; font-weight:600; cursor:pointer; }
    [data-ec80-grouped="v1"] .ec80-round-item--next { border-style:dashed; background:#f3faf8; }
    [data-ec80-grouped="v1"] .ec80-round-disclaimer { margin:0 14px 12px; color:#68808a; font-size:11px; }
    @media(max-width:700px){[data-ec80-grouped="v1"] .ec80-round-item{flex-wrap:wrap}[data-ec80-grouped="v1"] .ec80-round-item button{margin-left:40px}}
    </style>
    <div class="ec68-stats" aria-label="Indicadores reais da operação">
        <article class="ec68-stat"><span>Rascunhos</span><strong>{{ $ec68Fmt($counts['drafts']) }}</strong><small>Em preparação, sem envio</small></article>
        <article class="ec68-stat"><span>Fila ativa</span><strong>{{ $ec68Fmt($counts['queue']) }}</strong><small>Pendentes ou reservados para envio</small></article>
        <article class="ec68-stat ec68-stat--accent"><span>Aceitos hoje SMTP</span><strong>{{ $ec68Fmt($counts['sent_today']) }}</strong><small>Aceitação não comprova entrega</small></article>
        <article class="ec68-stat"><span>Respostas</span><strong>{{ $ec68Fmt($counts['replies']) }}</strong><small>Respostas identificadas</small></article>
    </div>

    <section class="ec68-card ec68-campaigns" aria-labelledby="ec68-list-title">
        <div class="ec68-section-heading">
            <div><h2 id="ec68-list-title">Minhas campanhas</h2><p>Uma campanha por linha. Abra para ver a 1ª abordagem, próximas rodadas e os resultados dos retornos no mesmo histórico.</p></div>
            <span class="ec68-total">{{ $ec68Fmt($counts['campaigns']) }} campanhas principais</span>
        </div>
        <div class="ec68-table-tools">
            <label class="ec68-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4.5 4.5"/></svg><input type="search" wire:model.live.debounce.350ms="campaignSearch" placeholder="Pesquisar campanha pelo nome..." aria-label="Buscar campanhas"></label>
            <div class="ec68-pills" role="group" aria-label="Situação das campanhas">
                @foreach (['all' => 'Todas', 'draft' => 'Rascunhos', 'running' => 'Enviando', 'paused' => 'Pausadas', 'completed' => 'Concluídas', 'canceled' => 'Canceladas'] as $code => $text)
                    <button type="button" wire:click="$set('campaignStatus', @js($code))" class="{{ $campaignStatus === $code ? 'is-active' : '' }}" aria-pressed="{{ $campaignStatus === $code ? 'true' : 'false' }}">{{ $text }}</button>
                @endforeach
            </div>
        </div>
        <div class="ec68-table-scroll">
            <table class="ec68-table">
                <thead><tr><th scope="col">Campanha</th><th scope="col">Status</th><th scope="col">Mensagens por rodada</th><th scope="col">Aceitos SMTP</th><th scope="col">Criada em</th><th scope="col"><span class="ec68-visually-hidden">Ação</span></th></tr></thead>
                <tbody>
                @forelse ($ec68Rows as $item)
                    @php
                        $ec80Rounds = $item->followupRounds;
                        $ec80Plan = $ec80Plans->get((int) $item->id);
                        $ec80RoundCount = $ec80Rounds->count() + 1;
                        $ec80Messages = (int) $item->recipients_count + (int) $ec80Rounds->sum('recipients_count');
                        $ec80Accepted = (int) $item->accepted_smtp_count + (int) $ec80Rounds->sum('accepted_smtp_count');
                        $ec80Rate = $ec80Messages > 0 ? round($ec80Accepted * 100 / $ec80Messages, 1) : 0;
                        $ec80Last = $ec80Rounds->last();
                        $ec80Status = $ec80Last?->status ?? $item->status;
                        $ec80StatusLabel = $labels[$ec80Status] ?? $ec80Status;
                        $ec80MorePlanned = $ec80Plan !== null
                            && (int) $ec80Plan->sent_rounds < (int) $ec80Plan->max_rounds;
                    @endphp
                    <tr wire:key="ec80-main-{{ $item->id }}" data-ec80-campaign-root="{{ $item->id }}">
                        <td>
                            <a class="ec68-link" href="{{ route('campaigns.index', ['campanha' => $item->id]) }}">{{ $item->name }}</a>
                            <span class="ec80-subline">{{ $ec80RoundCount }} {{ $ec80RoundCount === 1 ? 'rodada' : 'rodadas' }} · campanha #{{ $item->id }}</span>
                        </td>
                        <td><span class="ec68-status ec68-status--{{ $ec80Status }}">{{ $ec80StatusLabel }}</span></td>
                        <td>{{ $ec68Fmt($ec80Messages) }} <span class="ec80-subline">mensagens nas rodadas</span></td>
                        <td>{{ $ec68Fmt($ec80Accepted) }}/{{ $ec68Fmt($ec80Messages) }} <span class="ec80-subline">{{ number_format($ec80Rate, 1, ',', '.') }}% de aceites SMTP</span></td>
                        <td>{{ $item->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td><a class="ec68-open" href="{{ route('campaigns.index', ['campanha' => $item->id]) }}" aria-label="Abrir campanha {{ $item->name }}">Ver campanha <span aria-hidden="true">›</span></a></td>
                    </tr>
                    @if ($ec80Rounds->isNotEmpty() || $ec80Plan !== null)
                        <tr wire:key="ec80-contents-{{ $item->id }}" class="ec80-rounds-row">
                            <td colspan="6">
                                <details class="ec80-rounds-details" data-ec80-history="{{ $item->id }}">
                                    <summary>
                                        <span>Histórico e programação da campanha</span>
                                        <strong>{{ $ec80RoundCount }} {{ $ec80RoundCount === 1 ? 'rodada' : 'rodadas' }}{{ $ec80MorePlanned ? ' · próximo retorno programado' : '' }}</strong>
                                    </summary>
                                    <div class="ec80-rounds-items">
                                        <div class="ec80-round-item">
                                            <span class="ec80-round-number">1</span>
                                            <div><strong>1ª rodada · abordagem inicial</strong><small>{{ (int) $item->accepted_smtp_count }} de {{ (int) $item->recipients_count }} aceitos pelo SMTP · {{ $labels[$item->status] ?? $item->status }}</small></div>
                                            <a href="{{ route('campaigns.index', ['campanha' => $item->id]) }}">Ver 1ª rodada</a>
                                        </div>
                                        @foreach ($ec80Rounds as $ec80Round)
                                            <div class="ec80-round-item" wire:key="ec80-round-{{ $ec80Round->id }}">
                                                <span class="ec80-round-number">{{ (int) $ec80Round->followup_round }}</span>
                                                <div><strong>{{ (int) $ec80Round->followup_round }}ª rodada · {{ $ec80Round->name }}</strong>
                                                    <small>{{ (int) $ec80Round->accepted_smtp_count }} de {{ (int) $ec80Round->recipients_count }} aceitos pelo SMTP · {{ $labels[$ec80Round->status] ?? $ec80Round->status }}</small>
                                                </div>
                                                <a href="{{ route('campaigns.index', ['campanha' => $ec80Round->id]) }}">Ver resultados</a>
                                            </div>
                                        @endforeach
                                        @if ($ec80MorePlanned)
                                            <div class="ec80-round-item ec80-round-item--next">
                                                <span class="ec80-round-number">{{ (int) $ec80Plan->sent_rounds + 2 }}</span>
                                                <div><strong>Próximo retorno · {{ (string) $ec80Plan->name }}</strong>
                                                    <small>+{{ (int) $ec80Plan->delay_days }} dias · {{ $ec80Plan->send_time_local ?: 'horário a definir' }} · {{ (bool) $ec80Plan->automatic ? 'Automático' : 'Somente planejado' }}</small>
                                                </div>
                                                <a href="{{ route('campaigns.index', ['campanha' => $item->id]) }}">Ver programação</a>
                                            </div>
                                        @endif
                                    </div>
                                    <p class="ec80-round-disclaimer">Os totais acima são mensagens de cada rodada, não contatos únicos. Aceitação SMTP não comprova entrega.</p>
                                </details>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" class="ec68-empty">
                        <strong>{{ $counts['campaigns'] === 0 ? 'Nenhuma campanha criada' : 'Nenhuma campanha neste filtro' }}</strong>
                        <p>{{ $counts['campaigns'] === 0 ? 'Comece selecionando destinatários e preparando um rascunho.' : 'Tente outro nome ou situação.' }}</p>
                        @if ($counts['campaigns'] === 0 && $canManageCampaigns)<button type="button" class="ec68-primary" wire:click="newCampaign">Nova campanha</button>@endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <footer class="ec68-table-footer">
            <span>{{ $ec68Matched > 0 ? (($ec68CurrentPage - 1) * $ec68PageSize + 1).'–'.min($ec68CurrentPage * $ec68PageSize, $ec68Matched) : '0' }} de {{ $ec68Matched }} campanhas principais consultadas (até 40 recentes)</span>
            <div class="ec68-pagination">
                <button type="button" wire:click="changeCampaignTablePage({{ max(1, $ec68CurrentPage - 1) }})" @disabled($ec68CurrentPage <= 1)>Anterior</button>
                <span>Página {{ $ec68CurrentPage }} / {{ $ec68Pages }}</span>
                <button type="button" wire:click="changeCampaignTablePage({{ min($ec68Pages, $ec68CurrentPage + 1) }})" @disabled($ec68CurrentPage >= $ec68Pages)>Próxima</button>
            </div>
        </footer>
    </section>

    {{-- Proximos retornos, somente os ainda nao executados. --}}
    @include('campaigns.followup-schedule-home-v4')

    <div class="ec69-send-status {{ $canStart ? 'is-ready' : 'is-pending' }}" role="status">
        <span class="ec69-send-mark" aria-hidden="true">{{ $canStart ? '✓' : 'i' }}</span>
        <div class="ec69-send-copy">
            <strong>{{ ! $canManageCampaigns ? 'Consulta habilitada' : ($canStart ? 'Envio disponível' : 'Falta habilitar o envio real') }}</strong>
            <p>{{ ! $canManageCampaigns
                ? 'Você pode acompanhar os resultados. A criação e o envio dependem de autorização do gestor.'
                : ($canStart
                    ? 'Crie uma campanha e confirme os destinatários antes de iniciar.'
                    : 'Você já pode criar campanhas e testar mensagens. Para disparar aos contatos, finalize a configuração SMTP e do descadastro.') }}</p>
        </div>
        @if ($canManageCampaigns && ! $canStart)
            <a href="{{ route('campaigns.settings') }}" wire:navigate class="ec69-configure-link">Preparar envio →</a>
        @endif
    </div>


    @if ($canManageCampaigns)
    <section class="ec68-starters" aria-labelledby="ec68-models-title">
        <header><h2 id="ec68-models-title">Comece por uma mensagem</h2><p>Modelos editáveis: escolha um para abrir o assistente de campanha.</p></header>
        <div class="ec68-starter-grid">
            <button type="button" wire:click="startWithTemplate('initial')"><span class="ec68-model-label">Modelo rápido</span><strong>Apresentação</strong><small>Primeiro contato com uma empresa.</small><em>Usar modelo <span aria-hidden="true">→</span></em></button>
            <button type="button" wire:click="startWithTemplate('followup')"><span class="ec68-model-label">Modelo rápido</span><strong>Retomada</strong><small>Retome uma conversa anterior.</small><em>Usar modelo <span aria-hidden="true">→</span></em></button>
            <button type="button" wire:click="startWithTemplate('meeting')"><span class="ec68-model-label">Modelo de mensagem</span><strong>Agendamento</strong><small>Convide para uma reunião.</small><em>Usar modelo <span aria-hidden="true">→</span></em></button>
        </div>
    </section>
    @endif
    <p class="ec68-footnote">ExportControl Prospector · Dados reais do SMTP e IMAP; não há métrica de abertura sem rastreamento validado.</p>
</section>
