{{-- CAMPANHAS_FIGMA_V68_M05 — cabeçalho operacional com dados verdadeiros e ações originais. --}}
@if ($selected)
    @php
        $ec68Pending = (int) ($stats['pending'] ?? 0) + (int) ($stats['reserved'] ?? 0) + (int) ($stats['sending'] ?? 0);
        $ec68Replies = (int) ($stats['replied'] ?? 0);
    @endphp
    <section class="ec68-detail-top" data-ec68-screen="M05" aria-label="Detalhes da campanha">
        <div class="ec68-detail-eyebrow">CAMPANHAS <span class="ec68-status ec68-status--{{ $selected->status }}">{{ $labels[$selected->status] ?? $selected->status }}</span></div>
        <h1>{{ $selected->name }}</h1>
        <p>{{ $selected->subject }} · {{ $selected->created_at?->format('d/m/Y H:i') }}</p>
        <div class="ec68-detail-actions">
            @if ($selected->status === 'draft')
                <button type="button" wire:click="editDraft({{ $selected->id }})">Editar rascunho</button>
            @elseif ($selected->status === 'running')
                <button type="button" wire:click="pauseCampaign({{ $selected->id }})">Pausar novos envios</button>
            @elseif ($selected->status === 'paused')
                <button type="button" wire:click="resumeCampaign({{ $selected->id }})" @disabled(! $canStart)>Continuar campanha</button>
            @endif
            @if (in_array($selected->status, ['draft', 'running', 'paused'], true))
                <button type="button" class="ec68-danger-button" wire:click="cancelCampaign({{ $selected->id }})" wire:confirm="Cancelar esta campanha? E-mails já enviados não podem ser desfeitos.">Cancelar campanha</button>
            @endif

            <button type="button" wire:click="showOverview">Voltar às campanhas</button>
        </div>
        <div class="ec68-stats ec68-detail-stats">
            <article class="ec68-stat"><span>Destinatários</span><strong>{{ number_format($totalCount, 0, ',', '.') }}</strong><small>Público tratado</small></article>
            <article class="ec68-stat ec68-stat--accent"><span>Aceitos SMTP</span><strong>{{ number_format($sentCount, 0, ',', '.') }}</strong><small>{{ $progress }}% dos destinatários</small></article>
            <article class="ec68-stat"><span>Respostas</span><strong>{{ number_format($selected->replies()->count(), 0, ',', '.') }}</strong><small>Correlacionadas no sistema</small></article>
            <article class="ec68-stat"><span>Na fila</span><strong>{{ number_format($ec68Pending, 0, ',', '.') }}</strong><small>Pendentes, reservados ou processando</small></article>
        </div>
        <div class="ec68-detail-status {{ $selected->status === 'running' ? 'is-running' : '' }}">
            <strong>{{ match ($selected->status) { 'draft' => 'Rascunho em preparação', 'running' => 'Campanha em andamento', 'paused' => 'Campanha pausada', 'completed' => 'Campanha concluída', 'canceled' => 'Campanha cancelada', default => 'Histórico da campanha' } }}</strong>
            <p>O histórico dos destinatários é preservado. Aceitação pelo SMTP não comprova entrega nem abertura.</p>
        </div>
    </section>
@endif
