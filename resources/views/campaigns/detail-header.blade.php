{{-- EC94: ações únicas e operacionais; não altera os indicadores SMTP/IMAP. --}}
<style>
.ec76-detail .ec94-send-form{display:flex;flex-direction:column;gap:7px;min-width:190px}
.ec76-detail .ec94-consent{display:flex;gap:6px;align-items:flex-start;font-size:11px;color:#547181;line-height:1.4}
.ec76-detail .ec94-consent input{accent-color:#008b7f;margin-top:2px}
.ec76-detail .ec94-primary{background:#008b7f;color:#fff;border:1px solid #008b7f;border-radius:8px;padding:9px 12px;cursor:pointer}
.ec76-detail .ec94-primary:disabled{opacity:.5;cursor:not-allowed}
.ec76-detail-actions form{margin:0}
</style>
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

    @if (session('campaigns_operation_v94'))
        <div role="status" style="padding:13px 16px;margin:0 0 14px;background:#e8f7f1;color:#07665a;border:1px solid #addfce;border-radius:9px">{{ session('campaigns_operation_v94') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" style="padding:13px 16px;margin:0 0 14px;background:#fff1ed;color:#9a311f;border:1px solid #edbaaa;border-radius:9px">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif
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
            {{-- EC94_HTTP_ACTIONS: todas as operações passam por POST protegido, sem Livewire. --}}
            @if ($canManageCampaigns)
                @if ($selected->status === 'draft')
                    <a class="ec76-btn ec76-btn-neutral" href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1, 'editar' => $selected->id]) }}">Editar rascunho</a>
                    <form method="POST" action="{{ route('campaigns.ops-v94.start', ['campaign' => $selected->id]) }}" class="ec94-send-form">
                        @csrf
                        <label class="ec94-consent"><input type="checkbox" name="lawful_basis" value="1" required> Confirmo a base legal, os destinatários e o descadastro</label>
                        <button type="submit" class="ec76-btn ec94-primary" @disabled(! $canStart)>Aprovar e iniciar envio</button>
                        @unless ($canStart)
                            <small>Envio bloqueado pela configuração SMTP ou endereço de descadastro. <a href="{{ route('campaigns.settings') }}">Ver configurações</a></small>
                        @endunless
                    </form>
                @elseif ($selected->status === 'running')
                    <form method="POST" action="{{ route('campaigns.ops-v94.pause', ['campaign' => $selected->id]) }}">
                        @csrf
                        <button type="submit" class="ec76-btn ec76-btn-neutral">Pausar novos envios</button>
                    </form>
                @elseif ($selected->status === 'paused')
                    <form method="POST" action="{{ route('campaigns.ops-v94.resume', ['campaign' => $selected->id]) }}">
                        @csrf
                        <button type="submit" class="ec76-btn ec94-primary" @disabled(! $canStart)>Retomar campanha</button>
                    </form>
                @endif
                @if (in_array($selected->status, ['draft', 'running', 'paused'], true))
                    <form method="POST" action="{{ route('campaigns.ops-v94.cancel', ['campaign' => $selected->id]) }}" onsubmit="return window.confirm('Cancelar esta campanha? E-mails já enviados não podem ser desfeitos.')">
                        @csrf
                        <button type="submit" class="ec68-danger-button">Cancelar campanha</button>
                    </form>
                @endif
            @endif
            <a class="ec76-btn ec76-btn-neutral" href="{{ route('campaigns.index') }}">Voltar às campanhas</a>
        </div>
        </div>

        {{-- ecfv3-detail-before-metrics: previsão e e-mail em destaque --}}
        {{-- EC_CAMPAIGNS_UNIFIED_V90: editor movido para baixo dos resultados. --}}
        @include('campaigns.campaign-group-history')

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
                <span>{{ $selected->status === 'draft' ? 'Aguardando aprovação' : 'Na fila' }}</span>
                <strong>{{ number_format($ec76Queue, 0, ',', '.') }}</strong>
                <small>Pendentes ou processando</small>
            </article>
            <article class="ec76-metric">
                <span>Respostas</span>
                <strong>{{ number_format($ec76Replies, 0, ',', '.') }}</strong>
                <small>Registradas no sistema</small>
            </article>
        </div>

        {{-- EC_CAMPAIGNS_BOUNCE_VISIBILITY_V29: aviso real de DSN, sem presumir entrega. --}}
        @if ($this->bounceSummary['total'] > 0)
            <div class="ec29-bounce-banner" role="status" data-ec-bounce-warning>
                <div>
                    <strong>{{ $this->bounceSummary['total'] }} {{ $this->bounceSummary['total'] === 1 ? 'devolução' : 'devoluções' }} identificada(s)</strong>
                    <p>Uma ou mais mensagens foram recusadas ou devolvidas após a tentativa de envio. Confira os destinatários e os códigos informados pelo servidor.</p>
                </div>
                {{-- EC_CAMPAIGN_DETAIL_TABS_V909 --}}<a class="ec29-bounce-button" href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'bounces']) }}">Ver devoluções</a>
            </div>
        @endif

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
