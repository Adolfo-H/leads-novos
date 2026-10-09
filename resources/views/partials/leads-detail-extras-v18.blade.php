@php
    // Painel somente de leitura: reaproveita relações que a listagem já carregou.
    // Campos ausentes não recebem valores inventados.
    $lv19Email = trim((string) ($matrix?->email ?? ''));
    $lv19Phone1 = trim((string) ($matrix?->phone_1 ?? ''));
    $lv19Phone2 = trim((string) ($matrix?->phone_2 ?? ''));
    $lv19Digits1 = preg_replace('/\D+/', '', $lv19Phone1) ?: '';
    $lv19Digits2 = preg_replace('/\D+/', '', $lv19Phone2) ?: '';
    $lv19DistinctPhone2 = $lv19Digits2 !== '' && $lv19Digits2 !== $lv19Digits1;
    $lv19ContactCount = (int) ($lv19Email !== '')
        + (int) ($lv19Digits1 !== '')
        + (int) $lv19DistinctPhone2;

    $lv19Size = trim((string) ($lead->size_description ?? ''));
    $lv19Nature = trim((string) ($lead->legal_nature_description ?? ''));
    $lv19Status = trim((string) ($matrix?->registration_status ?? ''));
    $lv19Capital = $lead->share_capital;

    $lv19ResearchedAt = $export?->researched_at;
    $lv19LastActivityAt = $hubSpotLead?->last_activity_at;
    $lv19SyncedAt = $hubSpotLead?->synced_at;
    $lv19StatusChangedAt = $hubSpotLead?->work_status_changed_at;
    $lv19SyncIsOld = $lv19SyncedAt !== null
        && $lv19SyncedAt->lessThan(now()->subHours(48));
    $lv19OpenTasks = $hubSpotLead?->open_task_count;

@endphp

<div class="lv19-information-grid" aria-label="Informações complementares da empresa">
    <section class="lv19-info-panel" aria-label="Contatos cadastrados">
        <header class="lv19-info-panel-head">
            <span class="lv19-panel-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
            </span>
            <div>
                <h4>Contatos cadastrados</h4>
                <p>{{ $lv19ContactCount }} {{ $lv19ContactCount === 1 ? 'meio de contato disponível' : 'meios de contato disponíveis' }}</p>
            </div>
        </header>

        <div class="lv19-info-list">
            @if ($lv19Email !== '')
                <div class="lv19-info-line">
                    <span class="lv19-info-label">E-mail da matriz</span>
                    <a class="lv19-info-value" href="mailto:{{ $lv19Email }}" title="Abrir programa de e-mail">{{ $lv19Email }}</a>
                </div>
            @endif

            @if ($lv19Digits1 !== '')
                <div class="lv19-info-line">
                    <span class="lv19-info-label">Telefone principal</span>
                    <a class="lv19-info-value" href="tel:{{ $lv19Digits1 }}" title="Ligar para o telefone cadastrado">{{ $lv19Phone1 }}</a>
                </div>
            @endif

            @if ($lv19DistinctPhone2)
                <div class="lv19-info-line">
                    <span class="lv19-info-label">Telefone adicional</span>
                    <a class="lv19-info-value" href="tel:{{ $lv19Digits2 }}" title="Ligar para outro telefone cadastrado">{{ $lv19Phone2 }}</a>
                </div>
            @endif
        </div>

        @if ($lv19ContactCount === 0)
            <p class="lv19-empty-message">Ainda não há e-mail ou telefone informado para a matriz.</p>
        @else
            <p class="lv19-help-message">Dados cadastrais: confirme o contato antes da abordagem.</p>
        @endif
    </section>

    <section class="lv19-info-panel" aria-label="Dados da empresa">
        <header class="lv19-info-panel-head">
            <span class="lv19-panel-icon lv19-panel-icon--blue" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3"/></svg>
            </span>
            <div>
                <h4>Dados da empresa</h4>
                <p>Perfil cadastral e enquadramento</p>
            </div>
        </header>

        <dl class="lv19-facts">
            @if ($lv19Size !== '')
                <div><dt>Porte</dt><dd>{{ $lv19Size }}</dd></div>
            @endif
            @if ($lv19Nature !== '')
                <div><dt>Natureza jurídica</dt><dd>{{ $lv19Nature }}</dd></div>
            @endif
            @if ($lv19Status !== '')
                <div><dt>Situação cadastral da matriz</dt><dd><span class="lv19-inline-badge {{ mb_strtoupper($lv19Status) === 'ATIVA' ? 'is-positive' : '' }}">{{ $lv19Status }}</span></dd></div>
            @endif
            @if ($lv19Capital !== null)
                <div><dt>Capital social informado</dt><dd class="lv19-mono">R$ {{ number_format((float) $lv19Capital, 2, ',', '.') }}</dd></div>
            @endif
        </dl>
        @if ($lv19Size === '' && $lv19Nature === '' && $lv19Status === '' && $lv19Capital === null)
            <p class="lv19-empty-message">Ainda não há dados cadastrais adicionais disponíveis.</p>
        @endif
    </section>

    <section class="lv19-info-panel" aria-label="Histórico e pesquisa">
        <header class="lv19-info-panel-head">
            <span class="lv19-panel-icon lv19-panel-icon--violet" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            </span>
            <div>
                <h4>Histórico e pesquisa</h4>
                <p>Datas e atividades registradas localmente</p>
            </div>
        </header>

        <ol class="lv19-timeline">
            @if ($lv19LastActivityAt !== null)
                <li><span class="lv19-timeline-marker"></span><span>Última atividade comercial</span><time datetime="{{ $lv19LastActivityAt->toIso8601String() }}">{{ $lv19LastActivityAt->format('d/m/Y H:i') }}</time></li>
            @endif
            @if ($lv19ResearchedAt !== null)
                <li><span class="lv19-timeline-marker"></span><span>Pesquisa de exportação</span><time datetime="{{ $lv19ResearchedAt->toIso8601String() }}">{{ $lv19ResearchedAt->format('d/m/Y') }}</time></li>
            @endif
            @if ($lv19SyncedAt !== null)
                <li><span class="lv19-timeline-marker"></span><span>Sincronização do lead</span><time datetime="{{ $lv19SyncedAt->toIso8601String() }}">{{ $lv19SyncedAt->format('d/m/Y H:i') }}</time></li>
            @endif
            @if ($lv19StatusChangedAt !== null)
                <li><span class="lv19-timeline-marker"></span><span>Alteração de acompanhamento</span><time datetime="{{ $lv19StatusChangedAt->toIso8601String() }}">{{ $lv19StatusChangedAt->format('d/m/Y') }}</time></li>
            @endif
        </ol>

        @if ($lv19LastActivityAt === null && $lv19ResearchedAt === null && $lv19SyncedAt === null && $lv19StatusChangedAt === null)
            <p class="lv19-empty-message">Sem datas de atividade disponíveis para este registro.</p>
        @endif

        @if ($lv19OpenTasks !== null)
            <div class="lv19-mirror-info"><strong>{{ (int) $lv19OpenTasks }}</strong> {{ (int) $lv19OpenTasks === 1 ? 'tarefa aberta' : 'tarefas abertas' }} no resumo local do HubSpot</div>
            <p class="lv19-help-message">Esse total pode diferir do HubSpot online se houver atraso na sincronização.</p>
        @endif
        @if ($lv19SyncIsOld)
            <p class="lv19-freshness-warning">Sincronização do lead há mais de 48 horas. Confirme os dados no HubSpot.</p>
        @endif
    </section>
</div>
