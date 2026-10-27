{{-- CAMPANHAS_FIGMA_V68_M01 — nova página inicial, integrada ao Livewire real. --}}
@php
    $ec68PageSize = 8;
    $ec68Matched = $campaignsList->count();
    $ec68Pages = max(1, (int) ceil($ec68Matched / $ec68PageSize));
    $ec68CurrentPage = min(max(1, (int) $campaignTablePage), $ec68Pages);
    $ec68Rows = $campaignsList->slice(($ec68CurrentPage - 1) * $ec68PageSize, $ec68PageSize);
    $ec68Fmt = static fn ($value): string => number_format((int) $value, 0, ',', '.');
@endphp
<section class="ec68-home" aria-label="Visão geral de campanhas" data-ec68-screen="M01">
    <div class="ec68-stats" aria-label="Indicadores reais da operação">
        <article class="ec68-stat"><span>Rascunhos</span><strong>{{ $ec68Fmt($counts['drafts']) }}</strong><small>Em preparação, sem envio</small></article>
        <article class="ec68-stat"><span>Fila ativa</span><strong>{{ $ec68Fmt($counts['queue']) }}</strong><small>Pendentes ou reservados para envio</small></article>
        <article class="ec68-stat ec68-stat--accent"><span>Aceitos hoje SMTP</span><strong>{{ $ec68Fmt($counts['sent_today']) }}</strong><small>Aceitação não comprova entrega</small></article>
        <article class="ec68-stat"><span>Respostas</span><strong>{{ $ec68Fmt($counts['replies']) }}</strong><small>Respostas identificadas</small></article>
    </div>

    <div class="ec69-send-status {{ $canStart ? 'is-ready' : 'is-pending' }}" role="status">
        <span class="ec69-send-mark" aria-hidden="true">{{ $canStart ? '✓' : 'i' }}</span>
        <div class="ec69-send-copy">
            <strong>{{ $canStart ? 'Envio disponível' : 'Falta habilitar o envio real' }}</strong>
            <p>{{ $canStart
                ? 'Crie uma campanha e confirme os destinatários antes de iniciar.'
                : 'Você já pode criar campanhas e testar mensagens. Para disparar aos contatos, finalize a configuração SMTP e do descadastro.' }}</p>
        </div>
        @unless($canStart)
            <a href="{{ route('campaigns.settings') }}" wire:navigate class="ec69-configure-link">Preparar envio →</a>
        @endunless
    </div>

    <section class="ec68-card ec68-campaigns" aria-labelledby="ec68-list-title">
        <div class="ec68-section-heading">
            <div><h2 id="ec68-list-title">Minhas campanhas</h2><p>Consulte o histórico e acompanhe o processamento real das mensagens.</p></div>
            <span class="ec68-total">{{ $ec68Fmt($counts['campaigns']) }} cadastradas</span>
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
                <thead><tr><th scope="col">Campanha</th><th scope="col">Status</th><th scope="col">Destinatários</th><th scope="col">Aceitos SMTP</th><th scope="col">Criada em</th><th scope="col"><span class="ec68-visually-hidden">Ação</span></th></tr></thead>
                <tbody>
                @forelse ($ec68Rows as $item)
                    @php
                        $ec68Total = (int) $item->recipients_count;
                        $ec68Accepted = (int) $item->accepted_smtp_count;
                        $ec68Ratio = $ec68Total > 0 ? round($ec68Accepted * 100 / $ec68Total, 1) : 0;
                    @endphp
                    <tr wire:key="ec68-row-{{ $item->id }}">
                        <td><button type="button" class="ec68-link" wire:click="openCampaign({{ $item->id }})">{{ $item->name }}</button></td>
                        <td><span class="ec68-status ec68-status--{{ $item->status }}">{{ $labels[$item->status] ?? $item->status }}</span></td>
                        <td>{{ $ec68Fmt($ec68Total) }}</td>
                        <td>{{ $ec68Fmt($ec68Accepted) }}{{ $ec68Total > 0 ? ' · '.number_format($ec68Ratio, 1, ',', '.').'%' : '' }}</td>
                        <td>{{ $item->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td><button type="button" class="ec68-open" wire:click="openCampaign({{ $item->id }})" aria-label="Abrir {{ $item->name }}">Ver detalhes <span aria-hidden="true">›</span></button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ec68-empty">
                        <strong>{{ $counts['campaigns'] === 0 ? 'Nenhuma campanha criada' : 'Nenhuma campanha neste filtro' }}</strong>
                        <p>{{ $counts['campaigns'] === 0 ? 'Comece selecionando destinatários e preparando um rascunho.' : 'Tente outro nome ou situação.' }}</p>
                        @if ($counts['campaigns'] === 0)<button type="button" class="ec68-primary" wire:click="newCampaign">Nova campanha</button>@endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <footer class="ec68-table-footer">
            <span>{{ $ec68Matched > 0 ? (($ec68CurrentPage - 1) * $ec68PageSize + 1).'–'.min($ec68CurrentPage * $ec68PageSize, $ec68Matched) : '0' }} de {{ $ec68Matched }} campanhas consultadas (até 40 recentes)</span>
            <div class="ec68-pagination">
                <button type="button" wire:click="changeCampaignTablePage({{ max(1, $ec68CurrentPage - 1) }})" @disabled($ec68CurrentPage <= 1)>Anterior</button>
                <span>Página {{ $ec68CurrentPage }} / {{ $ec68Pages }}</span>
                <button type="button" wire:click="changeCampaignTablePage({{ min($ec68Pages, $ec68CurrentPage + 1) }})" @disabled($ec68CurrentPage >= $ec68Pages)>Próxima</button>
            </div>
        </footer>
    </section>

    <section class="ec68-starters" aria-labelledby="ec68-models-title">
        <header><h2 id="ec68-models-title">Comece por uma mensagem</h2><p>Modelos editáveis: escolha um para abrir o assistente de campanha.</p></header>
        <div class="ec68-starter-grid">
            <button type="button" wire:click="startWithTemplate('initial')"><span class="ec68-model-label">Modelo rápido</span><strong>Apresentação</strong><small>Primeiro contato com uma empresa.</small><em>Usar modelo <span aria-hidden="true">→</span></em></button>
            <button type="button" wire:click="startWithTemplate('followup')"><span class="ec68-model-label">Modelo rápido</span><strong>Retomada</strong><small>Retome uma conversa anterior.</small><em>Usar modelo <span aria-hidden="true">→</span></em></button>
            <button type="button" wire:click="startWithTemplate('meeting')"><span class="ec68-model-label">Modelo de mensagem</span><strong>Agendamento</strong><small>Convide para uma reunião.</small><em>Usar modelo <span aria-hidden="true">→</span></em></button>
        </div>
    </section>
    <p class="ec68-footnote">ExportControl Prospector · Dados reais do SMTP e IMAP; não há métrica de abertura sem rastreamento validado.</p>
</section>
