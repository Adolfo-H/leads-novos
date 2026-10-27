{{-- CAMPANHAS_DETAIL_FIGMA_V761 — M05: visão operacional sem painéis duplicados.
     Usa exclusivamente dados já carregados pelo Volt original.
     Aprovação, destinatários, SMTP e fluxo de envio continuam nos serviços existentes. --}}
@if ($selected)
    @php
        $ec76Queue = (int) ($stats['pending'] ?? 0)
            + (int) ($stats['reserved'] ?? 0)
            + (int) ($stats['sending'] ?? 0);
        $ec76Replies = (int) $selected->replies()->count();
        $ec76Status = (string) $selected->status;
        $ec76StatusLabel = $labels[$ec76Status] ?? 'Em acompanhamento';
        $ec76Progress = min(100, max(0, (int) $progress));
    @endphp

    @php
$ec68Pending = (int) ($stats['pending'] ?? 0) + (int) ($stats['reserved'] ?? 0) + (int) ($stats['sending'] ?? 0);
        $ec68Replies = (int) ($stats['replied'] ?? 0);
    @endphp

    <section class="ec76-detail" data-ec76-detail="M05-V761" data-campaign-status="{{ $ec76Status }}"
             aria-labelledby="ec76-detail-title">
        <div class="ec76-detail-header">
            <div class="ec76-detail-intro">
                <div class="ec76-breadcrumb">CAMPANHAS <span aria-hidden="true">/</span> DETALHES DA CAMPANHA</div>
                <div class="ec76-title-line">
                    <h1 id="ec76-detail-title">{{ $selected->name }}</h1>
                    <span class="ec76-status ec76-status--{{ $ec76Status }}">{{ $ec76StatusLabel }}</span>
                </div>
                <p class="ec76-detail-subject">{{ $selected->subject ?: 'Assunto não informado' }}</p>
                <p class="ec76-detail-created">Criada em {{ $selected->created_at?->format('d/m/Y H:i') ?? 'data não informada' }}</p>
            </div>

            <div class="ec76-detail-actions ec68-detail-actions" aria-label="Ações da campanha">
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
        </div>

        <div class="ec76-metrics" aria-label="Indicadores reais da campanha">
            <article class="ec76-metric">
                <span>Destinatários</span>
                <strong>{{ number_format($totalCount, 0, ',', '.') }}</strong>
                <small>Incluídos nesta campanha</small>
            </article>
            <article class="ec76-metric ec76-metric--highlight">
                <span>Aceitos pelo SMTP</span>
                <strong>{{ number_format($sentCount, 0, ',', '.') }}</strong>
                <small>{{ $ec76Progress }}% do público</small>
            </article>
            <article class="ec76-metric">
                <span>Na fila</span>
                <strong>{{ number_format($ec76Queue, 0, ',', '.') }}</strong>
                <small>Pendentes ou processando</small>
            </article>
            <article class="ec76-metric">
                <span>Respostas</span>
                <strong>{{ number_format($ec76Replies, 0, ',', '.') }}</strong>
                <small>Registradas no sistema</small>
            </article>
        </div>

        <div class="ec76-progress-card" aria-label="Andamento da campanha">
            <div class="ec76-progress-heading">
                <div>
                    <strong>Andamento dos envios</strong>
                    <span>Mensagens aceitas pelo servidor SMTP</span>
                </div>
                <div class="ec76-progress-value">{{ $sentCount }} de {{ $totalCount }} <b>{{ $ec76Progress }}%</b></div>
            </div>
            <div class="ec76-progress-track" role="progressbar" aria-label="Aceitação do SMTP"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $ec76Progress }}">
                <span style="width:{{ $ec76Progress }}%"></span>
            </div>
            <p>Aceitação SMTP não significa entrega na caixa de entrada nem leitura do e-mail.</p>
        </div>

        <details class="ec76-metadata">
            <summary>Ver informações da campanha
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <dl>
                <div><dt>Remetente</dt><dd>{{ $selected->from_address ?: 'Não configurado' }}</dd></div>
                <div><dt>Respostas para</dt><dd>{{ $selected->reply_to ?: 'Não configurado' }}</dd></div>
                <div><dt>Identificação</dt><dd>Campanha #{{ $selected->id }}</dd></div>
                <div><dt>Criada em</dt><dd>{{ $selected->created_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            </dl>
        </details>
    </section>
@endif
