{{--
    Prospector ExportControl — Remodelagem de Solicitações de acesso V60.
    Este partial não substitui o controller, o middleware nem as rotas de ação.
    Os dados são lidos pelo presenter; aprovar/recusar/reenviar utilizam as
    mesmas rotas POST/PATCH/PUT já registradas pela aplicação.
--}}
@php
    // Os dados chegam do controller autorizado. Fallback apenas para renderizações isoladas.
    $accessPage = $accessPage ?? \App\Support\Access\AccessRequestPageData::load();
    $accessItems = $accessPage['items'];
    $accessCounts = $accessPage['counts'];
    $accessFilter = $accessPage['filter'];
    $accessSearch = $accessPage['search'];
    $accessSort = $accessPage['sort'];
    $accessTabs = [
        'all' => ['Todas', 'layers'],
        'pending' => ['Pendentes', 'clock'],
        'approved' => ['Aprovadas', 'check'],
        'rejected' => ['Recusadas', 'x'],
        'activated' => ['Ativadas', 'user'],
    ];
@endphp

<link rel="stylesheet" href="{{ asset('assets/ec-access-requests-v60.css') }}?v=60.1">
<link rel="stylesheet" href="{{ asset('assets/ec-access-shell-v61.css') }}?v=61.0">
<script defer src="{{ asset('assets/ec-access-shell-v61.js') }}?v=61.0"></script>
<link rel="stylesheet" href="{{ asset('assets/ec-access-modal-v62.css') }}?v=62.0">
<script defer src="{{ asset('assets/ec-access-modal-v62.js') }}?v=62.0"></script>

<div class="ec-access-v60" id="ec-access-v60">
    <div class="ec-access-v60__inner">


        <div class="ec-access-v60__heading">
            <h1>Solicitações de acesso</h1>
            <p>Aprove ou recuse pedidos. Nenhum cadastro é liberado sem sua autorização.</p>
        </div>

        @if(session('access_success') || session('success') || session('message') || session('status'))
            <div class="ec-access-v60__flash ec-access-v60__flash--success" role="status">
                {{ session('access_success') ?: session('success') ?: session('message') ?: session('status') }}
            </div>
        @endif
        @if(session('access_error') || session('error'))
            <div class="ec-access-v60__flash ec-access-v60__flash--error" role="alert">{{ session('access_error') ?: session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="ec-access-v60__flash ec-access-v60__flash--error" role="alert">
                <strong>Não foi possível concluir a ação.</strong>
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="ec-access-v60__toolbar">
            <div class="ec-access-v60__tabs" role="navigation" aria-label="Filtrar solicitações por situação">
                @foreach($accessTabs as $tabKey => $tab)
                    <a
                        href="{{ request()->fullUrlWithQuery(['status' => $tabKey === 'all' ? null : $tabKey, 'page' => null]) }}"
                        class="ec-access-v60__tab {{ $accessFilter === $tabKey ? 'is-selected' : '' }}"
                        @if($accessFilter === $tabKey) aria-current="page" @endif
                    >
                        @switch($tab[1])
                            @case('layers')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 16l9 5 9-5"/></svg>
                                @break
                            @case('clock')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg>
                                @break
                            @case('check')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>
                                @break
                            @case('x')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg>
                                @break
                            @default
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m13-10 2 2 3-3"/><circle cx="11" cy="7" r="4"/></svg>
                        @endswitch
                        <span>{{ $tab[0] }}</span>
                        <span class="ec-access-v60__tab-count">{{ $accessCounts[$tabKey] ?? 0 }}</span>
                    </a>
                @endforeach
            </div>

            <form method="GET" action="{{ url()->current() }}" class="ec-access-v60__search" role="search">
                @if($accessFilter !== 'all')<input type="hidden" name="status" value="{{ $accessFilter }}">@endif
                @if($accessSort !== 'newest')<input type="hidden" name="sort" value="{{ $accessSort }}">@endif
                <label for="ec-access-search" class="sr-only">Buscar solicitações por nome, e-mail ou empresa</label>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input id="ec-access-search" name="q" type="search" value="{{ $accessSearch }}" maxlength="120" autocomplete="off" placeholder="Buscar por nome, e-mail ou empresa...">
                <button type="submit" aria-label="Buscar solicitações">Buscar</button>
            </form>
        </div>

        <section class="ec-access-v60__table-card" aria-label="Solicitações cadastradas">
            <div class="ec-access-v60__table-scroll">
                <table class="ec-access-v60__table">
                    <thead>
                        <tr>
                            <th scope="col" class="ec-access-v60__select-cell">
                                <input type="checkbox" id="ec-access-select-all" aria-label="Selecionar todas as solicitações desta página">
                            </th>
                            <th scope="col">Pessoa / e-mail</th>
                            <th scope="col">Empresa e cargo</th>
                            <th scope="col">Situação</th>
                            <th scope="col">
                                <a class="ec-access-v60__sort-link" href="{{ request()->fullUrlWithQuery(['sort' => $accessSort === 'newest' ? 'oldest' : 'newest', 'page' => null]) }}">
                                    Recebida em <span aria-hidden="true">{{ $accessSort === 'newest' ? '↓' : '↑' }}</span>
                                </a>
                            </th>
                            <th scope="col" class="ec-access-v60__actions-heading">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($accessItems as $accessRequest)
                            @php
                                $rowStatus = \App\Support\Access\AccessRequestPageData::status($accessRequest);
                                $rowApprove = $rowStatus === 'pending' ? \App\Support\Access\AccessRequestPageData::action($accessRequest, 'approve') : null;
                                $rowReject = $rowStatus === 'pending' ? \App\Support\Access\AccessRequestPageData::action($accessRequest, 'reject') : null;
                                $rowResendNotice = $rowStatus === 'pending' ? \App\Support\Access\AccessRequestPageData::action($accessRequest, 'resend-notification') : null;
                                $rowResendInvite = $rowStatus === 'approved' ? \App\Support\Access\AccessRequestPageData::action($accessRequest, 'resend-invitation') : null;
                                $rowName = \App\Support\Access\AccessRequestPageData::field($accessRequest, ['name', 'full_name'], 'Sem nome');
                                $rowEmail = \App\Support\Access\AccessRequestPageData::field($accessRequest, ['email'], '—');
                            @endphp
                            <tr>
                                <td class="ec-access-v60__select-cell">
                                    <input type="checkbox" class="ec-access-v60__row-select" data-email="{{ $rowEmail !== '—' ? $rowEmail : '' }}" aria-label="Selecionar solicitação de {{ $rowName }}">
                                </td>
                                <td>
                                    <div class="ec-access-v60__person">
                                        <span class="ec-access-v60__avatar" aria-hidden="true">{{ \App\Support\Access\AccessRequestPageData::initials($accessRequest) }}</span>
                                        <div class="ec-access-v60__person-text">
                                            <strong>{{ $rowName }}</strong>
                                            <span class="ec-access-v60__email">{{ $rowEmail }}</span>
                                            @if(\App\Support\Access\AccessRequestPageData::field($accessRequest, ['phone', 'phone_number', 'mobile']) !== '—')
                                                <span class="ec-access-v60__phone">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 3 5.18 2 2 0 0 1 5 3h3a2 2 0 0 1 2 1.72l.42 2.81a2 2 0 0 1-.57 1.72L8.1 11a16 16 0 0 0 4.9 4.9l1.75-1.75a2 2 0 0 1 1.72-.57l2.81.42A2 2 0 0 1 22 16.92Z"/></svg>
                                                    {{ \App\Support\Access\AccessRequestPageData::field($accessRequest, ['phone', 'phone_number', 'mobile']) }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="ec-access-v60__company">
                                        <strong>{{ \App\Support\Access\AccessRequestPageData::field($accessRequest, ['company', 'company_name', 'organization']) }}</strong>
                                        <span>{{ \App\Support\Access\AccessRequestPageData::field($accessRequest, ['position', 'job_title', 'role']) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="ec-access-v60__status ec-access-v60__status--{{ in_array($rowStatus, ['pending','approved','rejected','activated'], true) ? $rowStatus : 'unknown' }}">
                                        <span class="ec-access-v60__status-dot" aria-hidden="true"></span>
                                        {{ \App\Support\Access\AccessRequestPageData::label($accessRequest) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="ec-access-v60__received">
                                        <strong>{{ \App\Support\Access\AccessRequestPageData::date($accessRequest) }}</strong>
                                        <span>{{ $accessRequest->created_at ? \Illuminate\Support\Carbon::parse($accessRequest->created_at)->diffForHumans() : '' }}</span>
                                    </div>
                                </td>
                                <td class="ec-access-v60__actions-cell">
                                    <details class="ec-access-v60__action-menu">
                                        <summary aria-label="Ações para {{ $rowName }}" title="Ações">•••</summary>
                                        <div class="ec-access-v60__action-popover">
                                            @if($rowApprove)
                                                <form method="POST" action="{{ $rowApprove['url'] }}" data-ec-access-confirm="approve" data-ec-requester="{{ $rowName }}">
                                                    @csrf
                                                    @if($rowApprove['method'] !== 'POST') @method($rowApprove['method']) @endif
                                                    <button type="submit" class="ec-access-v60__action-approve">Aprovar solicitação</button>
                                                </form>
                                            @endif
                                            @if($rowReject)
                                                <form method="POST" action="{{ $rowReject['url'] }}" data-ec-access-confirm="reject" data-ec-requester="{{ $rowName }}">
                                                    @csrf
                                                    @if($rowReject['method'] !== 'POST') @method($rowReject['method']) @endif
                                                    <button type="submit" class="ec-access-v60__action-reject">Recusar solicitação</button>
                                                </form>
                                            @endif
                                            @if($rowResendInvite)
                                                <form method="POST" action="{{ $rowResendInvite['url'] }}">
                                                    @csrf
                                                    @if($rowResendInvite['method'] !== 'POST') @method($rowResendInvite['method']) @endif
                                                    <button type="submit">Reenviar convite</button>
                                                </form>
                                            @endif
                                            @if($rowResendNotice)
                                                <form method="POST" action="{{ $rowResendNotice['url'] }}">
                                                    @csrf
                                                    @if($rowResendNotice['method'] !== 'POST') @method($rowResendNotice['method']) @endif
                                                    <button type="submit">Reenviar aviso ao administrador</button>
                                                </form>
                                            @endif
                                            @unless($rowApprove || $rowReject || $rowResendInvite || $rowResendNotice)
                                                <span class="ec-access-v60__no-actions">Nenhuma ação pendente</span>
                                            @endunless
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr class="ec-access-v60__empty-row">
                                <td colspan="6">
                                    <div class="ec-access-v60__empty">
                                        <span class="ec-access-v60__empty-mark" aria-hidden="true">✓</span>
                                        <strong>Nenhuma solicitação encontrada</strong>
                                        <p>Não há registros para este filtro ou termo de busca.</p>
                                        @if($accessFilter !== 'all' || $accessSearch !== '')
                                            <a href="{{ url()->current() }}">Limpar filtros</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="ec-access-v60__table-footer">
                <div>
                    <span>Mostrando {{ $accessItems->count() ? $accessItems->firstItem() : 0 }}–{{ $accessItems->lastItem() ?? 0 }} de {{ $accessItems->total() }} {{ $accessItems->total() === 1 ? 'solicitação' : 'solicitações' }}</span>
                    <button type="button" id="ec-access-copy" class="ec-access-v60__copy" hidden>Copiar e-mails selecionados (<span id="ec-access-selected-count">0</span>)</button>
                </div>
                @if($accessItems->lastPage() > 1)
                    <nav class="ec-access-v60__pagination" aria-label="Paginação das solicitações">
                        @if($accessItems->onFirstPage())
                            <span class="is-disabled" aria-hidden="true">‹</span>
                        @else
                            <a href="{{ $accessItems->previousPageUrl() }}" aria-label="Página anterior">‹</a>
                        @endif
                        @for($page = max(1, $accessItems->currentPage() - 2); $page <= min($accessItems->lastPage(), $accessItems->currentPage() + 2); $page++)
                            <a href="{{ $accessItems->url($page) }}" class="{{ $page === $accessItems->currentPage() ? 'is-current' : '' }}" @if($page === $accessItems->currentPage()) aria-current="page" @endif>{{ $page }}</a>
                        @endfor
                        @if($accessItems->hasMorePages())
                            <a href="{{ $accessItems->nextPageUrl() }}" aria-label="Próxima página">›</a>
                        @else
                            <span class="is-disabled" aria-hidden="true">›</span>
                        @endif
                    </nav>
                @endif
            </div>
        </section>

        <aside class="ec-access-v60__notice">
            <span class="ec-access-v60__notice-icon" aria-hidden="true">i</span>
            <p>Convites são de uso único, expiram automaticamente e nunca conferem perfil de gestor. Envio aceito pelo mailer não garante entrega ao destinatário.</p>
        </aside>
    </div>
</div>

<dialog class="ec-access-v62-modal" id="ec-access-v62-modal" aria-labelledby="ec-access-v62-title" aria-describedby="ec-access-v62-description">
    <div class="ec-access-v62-modal__content">
        <button type="button" class="ec-access-v62-modal__close" data-ec-modal-cancel aria-label="Fechar confirmação">×</button>
        <div class="ec-access-v62-modal__icon" aria-hidden="true" id="ec-access-v62-icon">✓</div>
        <h2 id="ec-access-v62-title">Confirmar solicitação</h2>
        <p id="ec-access-v62-description">Confirme sua decisão antes de continuar.</p>
        <label class="ec-access-v62-modal__reason" id="ec-access-v62-reason-wrap" hidden>
            <span>Motivo da recusa <small>(opcional)</small></span>
            <textarea id="ec-access-v62-reason" rows="3" maxlength="500" placeholder="Informe o motivo para o registro administrativo, se desejar."></textarea>
            <small>Até 500 caracteres.</small>
        </label>
        <div class="ec-access-v62-modal__actions">
            <button class="ec-access-v62-modal__cancel" type="button" data-ec-modal-cancel>Cancelar</button>
            <button class="ec-access-v62-modal__confirm" id="ec-access-v62-confirm" type="button">Confirmar</button>
        </div>
    </div>
</dialog>

<script>
(function () {
    const root = document.getElementById('ec-access-v60');
    if (!root || root.dataset.selectionInitialized === '1') return;
    root.dataset.selectionInitialized = '1';
    const master = root.querySelector('#ec-access-select-all');
    const rows = [...root.querySelectorAll('.ec-access-v60__row-select')];
    const button = root.querySelector('#ec-access-copy');
    const count = root.querySelector('#ec-access-selected-count');
    const update = () => {
        const selected = rows.filter(c => c.checked);
        if (count) count.textContent = String(selected.length);
        if (button) button.hidden = selected.length === 0;
        if (master) {
            master.checked = rows.length > 0 && selected.length === rows.length;
            master.indeterminate = selected.length > 0 && selected.length < rows.length;
        }
    };
    master?.addEventListener('change', () => { rows.forEach(c => c.checked = master.checked); update(); });
    rows.forEach(c => c.addEventListener('change', update));
    button?.addEventListener('click', async () => {
        const emails = [...new Set(rows.filter(c => c.checked).map(c => c.dataset.email).filter(Boolean))];
        if (!emails.length) return;
        try {
            await navigator.clipboard.writeText(emails.join('; '));
            const before = button.textContent;
            button.textContent = 'E-mails copiados';
            window.setTimeout(() => button.textContent = before, 1800);
        } catch (_) {
            window.prompt('Copie os e-mails selecionados:', emails.join('; '));
        }
    });
})();
</script>
