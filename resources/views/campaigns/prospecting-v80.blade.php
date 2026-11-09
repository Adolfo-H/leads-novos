{{-- EC_CAMPAIGN_PROSPECTING_V81_UI: nova interface da V80; lógica e filtros originais preservados. --}}
<x-layouts::app :title="'Prospecção inteligente'">
    @include('campaigns.figma-style-v71')
    <style>
        /* Layout claro do módulo de campanhas; nenhuma regra global de formulários é alterada. */
        body.ec-app-body:has(.ecpi80) main.ec-main { background:#f3f7f9 !important; }
        .ecpi80,.ecpi80 *, .ecpi80 *::before,.ecpi80 *::after { box-sizing:border-box; }
        .ecpi80 { width:100%;max-width:1450px;margin:0 auto;padding:20px clamp(18px,3vw,42px) 42px;color:#17384e;font-family:inherit;font-size:14px;line-height:1.5; }
        .ecpi80-header { display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 17px; }
        .ecpi80-heading { min-width:0;flex:1 1 420px; }
        .ecpi80 h1 { color:#123449 !important;font-size:clamp(25px,2.8vw,34px);line-height:1.18;letter-spacing:-.028em;margin:0 0 6px !important;font-weight:790; }
        .ecpi80 h2 { color:#183d50 !important;font-size:18px;line-height:1.35;font-weight:760;margin:0 0 5px !important; }
        .ecpi80-muted { color:#667f90 !important;font-size:13px;line-height:1.55;max-width:980px;margin:0; }
        .ecpi80-button { display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:9px 14px;border:1px solid #cfdee7;border-radius:9px;background:#fff;color:#24516a !important;-webkit-text-fill-color:#24516a !important;text-decoration:none !important;font:inherit;font-size:12px;font-weight:700;white-space:normal;text-align:center;cursor:pointer;transition:background .14s,border-color .14s; }
        .ecpi80-button:hover { background:#f1faf7;border-color:#8dcab9; }
        .ecpi80-button-primary { background:#119f8b !important;border-color:#119f8b !important;color:#fff !important;-webkit-text-fill-color:#fff !important; }
        .ecpi80-button-primary:hover { background:#087b71 !important;border-color:#087b71 !important; }
        .ecpi80-button:disabled { opacity:.48;cursor:not-allowed; }
        .ecpi80 :is(a,button,input,select,textarea):focus-visible { outline:2px solid #109c88 !important;outline-offset:2px; }
        .ecpi80 nav.ecm6-nav,.ecpi80 nav.ecm7-nav { display:flex !important;align-items:center;flex-wrap:wrap;gap:5px;margin:0 0 15px !important;padding:5px !important;background:#fff !important;border:1px solid #dce6ed !important;border-radius:11px !important;box-shadow:none !important; }
        .ecpi80 nav.ecm6-nav > a,.ecpi80 nav.ecm7-nav > a { min-height:35px;padding:8px 14px !important;border:0 !important;border-radius:8px !important;background:transparent !important;color:#526e80 !important;-webkit-text-fill-color:#526e80 !important;font-size:12px !important;font-weight:710;text-decoration:none !important; }
        .ecpi80 nav.ecm6-nav > a.is-active,.ecpi80 nav.ecm7-nav > a.is-active { background:#e0f5ef !important;color:#008276 !important;-webkit-text-fill-color:#008276 !important; }
        .ecpi80-progress { display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 16px; }
        .ecpi80-progress-item { display:flex;align-items:center;gap:9px;padding:10px 12px;min-height:46px;border:1px solid #e0e9ef;border-radius:9px;background:#fff;color:#8499a7;font-weight:680;font-size:12px; }
        .ecpi80-progress-item .ecpi80-progress-number { display:grid;place-items:center;width:25px;height:25px;flex:0 0 25px;border:1px solid #d9e5ec;border-radius:50%;background:#f4f7f9;color:#8194a3;font-weight:800; }
        .ecpi80-progress-item.is-current { border-color:#a0dacf;background:#f0fbf7;color:#067f70; }
        .ecpi80-progress-item.is-current .ecpi80-progress-number,.ecpi80-progress-item.is-complete .ecpi80-progress-number { border-color:#0e9b89;background:#0e9b89;color:#fff; }
        .ecpi80-progress-item.is-complete { color:#256959; }
        .ecpi80-grid { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 14px; }
        .ecpi80-kpi { display:flex;flex-direction:column;align-items:flex-start;gap:6px;min-width:0;min-height:114px;padding:16px 17px;border:1px solid #dce6ed;border-radius:12px;background:#fff; }
        .ecpi80-kpi span { font-size:12px;color:#6d8698;font-weight:600; }
        .ecpi80-kpi strong { display:block;color:#173c52;font-size:clamp(22px,2.3vw,29px);font-weight:790;line-height:1.1;letter-spacing:-.035em;overflow-wrap:anywhere; }
        .ecpi80-kpi small { display:block;color:#859aaa;font-size:11px; }
        .ecpi80-kpi.is-accent strong { color:#0a8f80; }
        .ecpi80-card { margin:0 0 14px;min-width:0;padding:20px 22px;border:1px solid #dce6ed;border-radius:13px;background:#fff;box-shadow:0 3px 12px rgba(15,52,78,.025); }
        .ecpi80-cardhead { display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin:0 0 17px; }
        .ecpi80-fields { display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 17px; }
        .ecpi80-field { display:flex;flex-direction:column;align-items:stretch;gap:7px;min-width:0;color:#284e63 !important;font-size:12px;font-weight:710; }
        .ecpi80 .ecpi80-field > :is(input,select,textarea) { width:100%;min-width:0;min-height:44px;padding:10px 12px !important;border:1px solid #cfdfe8 !important;border-radius:8px !important;background:#fff !important;color:#203f55 !important;-webkit-text-fill-color:#203f55 !important;color-scheme:light !important;box-shadow:none !important;outline:none;font-family:inherit !important;font-size:13px !important;font-weight:500; }
        .ecpi80 .ecpi80-field select option { background:#fff !important;color:#203f55 !important;-webkit-text-fill-color:#203f55 !important; }
        .ecpi80 .ecpi80-field :is(input,textarea)::placeholder { color:#8298a8 !important;-webkit-text-fill-color:#8298a8 !important;opacity:1; }
        .ecpi80 .ecpi80-field > :is(input,select,textarea):focus { border-color:#0eaa95 !important;box-shadow:0 0 0 3px rgba(14,170,149,.13) !important; }
        .ecpi80 .ecpi80-field > textarea { min-height:136px;resize:vertical;line-height:1.55; }
        .ecpi80-field.span-two { grid-column:span 2; }
        .ecpi80-actions { display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin:16px 0 0; }
        .ecpi80-note { margin:15px 0 0;padding:11px 14px;border-left:3px solid #0f9d88;border-radius:7px;background:#eaf7f3;color:#245f57;font-size:12px;line-height:1.55; }
        .ecpi80-note strong { color:#176758; }
        .ecpi80-error { margin:12px 0;padding:12px 14px;border:1px solid #f4c8ca;border-left:3px solid #bf454f;border-radius:8px;background:#fff2f1;color:#9f303b;font-size:12px; }
        .ecpi80-tablebox { width:100%;overflow-x:auto;border:1px solid #dce7ed;border-radius:9px; }
        .ecpi80 table { width:100%;min-width:790px;border-collapse:collapse;text-align:left;font-size:12px; }
        .ecpi80 th { padding:12px 13px;border-bottom:1px solid #dce7ed;background:#f5f8fa;color:#5c788c !important;font-weight:750;white-space:nowrap; }
        .ecpi80 td { padding:13px;border-bottom:1px solid #ebf0f3;color:#34556a !important;vertical-align:top; }
        .ecpi80 tr:last-child td { border-bottom:0; }
        .ecpi80 td strong { display:block;color:#173d51 !important;font-weight:720; }
        .ecpi80 td small { display:block;margin-top:3px;color:#8196a6;overflow-wrap:anywhere; }
        .ecpi80-pill { display:inline-flex;align-items:center;padding:4px 8px;border:1px solid #cdeae0;border-radius:999px;background:#e9f8f3;color:#087d6f;font-size:11px;font-weight:720;white-space:nowrap; }
        .ecpi80-pages { display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:14px 0 0; }
        .ecpi80-check { display:flex;align-items:flex-start;gap:10px;margin:15px 0 0;color:#355a6c;font-size:12px;line-height:1.6;cursor:pointer; }
        .ecpi80 .ecpi80-check > input[type=checkbox] { flex:0 0 19px;width:19px;min-width:19px;height:19px;margin:2px 0 0;accent-color:#0e9c89;cursor:pointer; }
        .ecpi80-summary { display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 15px;margin:0 0 16px;border:1px solid #d7eae2;border-radius:9px;background:#f1faf6;color:#296b5b;font-size:12px; }
        .ecpi80-summary strong { font-size:17px;color:#087e70; }
        .ecpi80-footnote { padding:7px 1px 0; }
        @media(max-width:1090px) { .ecpi80-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }.ecpi80-fields { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media(max-width:700px) { .ecpi80 { padding:15px 13px 32px; }.ecpi80-card { padding:17px 15px; }.ecpi80-progress { grid-template-columns:1fr;gap:5px; }.ecpi80-progress-item { min-height:34px;padding:6px 10px; }.ecpi80-fields { grid-template-columns:1fr; }.ecpi80-field.span-two { grid-column:auto; }.ecpi80-grid { gap:9px; }.ecpi80-kpi { padding:13px;min-height:102px; }.ecpi80-kpi strong { font-size:23px; }.ecpi80-header .ecpi80-button { width:100%; } }
        @media(max-width:390px) { .ecpi80-grid { grid-template-columns:1fr; } }

        /* EC_PROSPECTING_EXCLUDE_V83: ações e exclusões privadas do gestor. */
        .ecpi80 .ecpi83-remove { width:32px;height:32px;min-width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid #efc5c5 !important;background:#fff7f6 !important;color:#a43b3b !important;-webkit-text-fill-color:#a43b3b !important;font-size:20px;font-weight:800;line-height:1;cursor:pointer; }
        .ecpi80 .ecpi83-remove:hover { background:#fbe8e6 !important;border-color:#e59c9c !important; }
        .ecpi80 .ecpi83-actcol { width:58px;text-align:center; }
        .ecpi80 .ecpi83-exclusions { margin:14px 0;border:1px solid #e0e7ed;background:#f9fbfc;padding:13px 15px;border-radius:9px; }
        .ecpi80 .ecpi83-exclusions summary { cursor:pointer;color:#31576d;font-size:12px;font-weight:730; }
        .ecpi80 .ecpi83-exclusion-row { display:flex;align-items:center;justify-content:space-between;gap:9px;flex-wrap:wrap;padding:9px 0;border-top:1px solid #e1e9ed;font-size:12px;overflow-wrap:anywhere; }
        .ecpi80 .ecpi83-exclusion-row:first-of-type { margin-top:10px; }
        .ecpi80 .ecpi83-restore { font-size:11px;border:1px solid #bcdacf;border-radius:7px;background:#fff;color:#087665;padding:5px 9px;cursor:pointer; }
    </style>
    <main class="ecpi80">
        <div class="ecpi80-header">
            <div class="ecpi80-heading">
                <h1>Prospecção inteligente</h1>
                <p class="ecpi80-muted">Encontre empresas novas ou sem contato recente, com exclusão de clientes, negociações ativas e e-mails já trabalhados. O Prospector seleciona os destinatários automaticamente.</p>
            </div>
            <a href="{{ route('campaigns.index') }}" class="ecpi80-button">Voltar às campanhas</a>
        </div>
        @include('campaigns.module-nav')
        @if($errors->any())
            <div class="ecpi80-error" role="alert">{{ $errors->first() }}</div>
        @endif
        @if(session('intelligence_success'))
            <div class="ecpi80-note" role="status">{{ session('intelligence_success') }}</div>
        @endif
        @if(session('prospecting_v83_notice'))
            <div class="ecpi80-note" role="status">{{ session('prospecting_v83_notice') }}</div>
        @endif

        <div class="ecpi80-progress" aria-label="Etapas da campanha">
            <div class="ecpi80-progress-item {{ $hasAnalysis ? 'is-complete' : 'is-current' }}"><span class="ecpi80-progress-number">1</span> Filtrar empresas e e-mails</div>
            <div class="ecpi80-progress-item {{ $hasAnalysis ? ($people->total() > 0 ? 'is-complete' : 'is-current') : '' }}"><span class="ecpi80-progress-number">2</span> Revisar resultado da análise</div>
            <div class="ecpi80-progress-item {{ $hasAnalysis && $people->total() > 0 ? 'is-current' : '' }}"><span class="ecpi80-progress-number">3</span> Preparar rascunho</div>
        </div>

        <div class="ecpi80-grid" aria-label="Indicadores de elegibilidade">
            <div class="ecpi80-kpi is-accent"><span>E-mails elegíveis no filtro</span><strong>{{ $hasAnalysis ? number_format($people->total(), 0, ',', '.') : '—' }}</strong><small>Únicos, sem bloqueios conhecidos</small></div>
            <div class="ecpi80-kpi"><span>Destinatários no rascunho</span><strong>{{ $hasAnalysis ? number_format(min($cap, $people->total()), 0, ',', '.') : '—' }}</strong><small>Máximo permitido por rascunho</small></div>
            <div class="ecpi80-kpi"><span>Aceitos pelo SMTP (histórico)</span><strong>{{ number_format($previousSent, 0, ',', '.') }}</strong><small>Eventos históricos, não pessoas únicas</small></div>
            <div class="ecpi80-kpi"><span>Teto SMTP configurado</span><strong>{{ number_format($smtpPerDay, 0, ',', '.') }}/dia</strong><small>Capacidade cadastrada, não disponibilidade real</small></div>
        </div>

        <section class="ecpi80-card" id="ecpi80-filters" aria-labelledby="ecpi80-filter-title">
            <div class="ecpi80-cardhead">
                <div><h2 id="ecpi80-filter-title">1. Encontrar empresas para prospectar</h2><p class="ecpi80-muted">Defina o público. Clientes conhecidos, negociações em andamento e contatos recentes serão excluídos da análise.</p></div>
            </div>
            <form method="GET" action="{{ route('campaigns.prospecting.index') }}">
                <input type="hidden" name="analyze" value="1">
                <div class="ecpi80-fields">
                    <label class="ecpi80-field">Origem dos e-mails
                        <select name="origin">
                            <option value="all" @selected($filters['origin']==='all')>Todas — HubSpot, manuais e Receita</option>
                            <option value="hubspot" @selected($filters['origin']==='hubspot')>Contatos HubSpot</option>
                            <option value="manual" @selected($filters['origin']==='manual')>Contatos cadastrados no Prospector</option>
                            <option value="receita" @selected($filters['origin']==='receita')>Canais institucionais da Receita</option>
                        </select>
                    </label>
                    <label class="ecpi80-field">Sem contato nos últimos
                        <select name="days">
                            @foreach([30,60,90,120,180,365] as $days)
                                <option value="{{ $days }}" @selected($filters['days']===$days)>{{ $days }} dias</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ecpi80-field">Tipo de oportunidade
                        <select name="mode">
                            <option value="all" @selected($filters['mode']==='all')>Novos ou antigos sem atividade recente</option>
                            <option value="new" @selected($filters['mode']==='new')>Sem atividade direta registrada</option>
                            <option value="dormant" @selected($filters['mode']==='dormant')>Contato anterior, já fora do intervalo</option>
                        </select>
                    </label>
                    <label class="ecpi80-field">Nome da empresa
                        <input name="company" maxlength="100" value="{{ $filters['company'] }}" placeholder="Ex.: cooperativa, cerealista...">
                    </label>
                    <label class="ecpi80-field">Cargo do destinatário
                        <input name="job" maxlength="100" value="{{ $filters['job'] }}" placeholder="Ex.: fiscal, financeiro...">
                    </label>
                    <label class="ecpi80-field">Nome do contato
                        <input name="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="Busca opcional">
                    </label>
                </div>
                <div class="ecpi80-actions">
                    <button type="submit" class="ecpi80-button ecpi80-button-primary">Analisar empresas e e-mails</button>
                    <a class="ecpi80-button" href="{{ route('campaigns.prospecting.index') }}">Limpar filtros</a>
                </div>
            </form>
            <div class="ecpi80-note">Proteções ativas: clientes identificados, negócios abertos ou ganhos, tarefas em aberto, atividades recentes, e-mails trabalhados no período, campanhas em andamento e descadastros. A ausência de histórico sincronizado não garante que a empresa nunca foi contatada. A coluna Empresa prioriza vínculos reais do HubSpot; nomes livres não comprovam CNPJ. Vínculos múltiplos sem primária única ficam fora da seleção automática.</div>
        </section>

        @if($hasAnalysis)
            <section class="ecpi80-card" id="ecpi80-results" aria-labelledby="ecpi80-result-title">
                <div class="ecpi80-cardhead">
                    <div><h2 id="ecpi80-result-title">2. Revisar o resultado da análise</h2><p class="ecpi80-muted">{{ number_format($people->total(), 0, ',', '.') }} endereço(s) distinto(s). Até 50 exibidos por página; a seleção do rascunho será feita pelo sistema.</p></div>
                    @if($people->total() > 0)
                        <a class="ecpi80-button" href="{{ route('campaigns.prospecting.export', $filters) }}">Exportar elegíveis (CSV)</a>
                    @endif
                </div>
                @if($people->total() > 0)
                    <div class="ecpi80-summary"><strong>{{ number_format($people->total(), 0, ',', '.') }}</strong><span>e-mails elegíveis após os filtros. Até {{ number_format($cap, 0, ',', '.') }} serão incluídos no próximo rascunho. <span>{{ number_format($excludedCount, 0, ',', '.') }} excluído(s) por você.</span></span></div>
                @else
                    <div class="ecpi80-note">Nenhum destinatário elegível para os filtros atuais. Altere o período ou a origem e analise novamente. O formulário de campanha só aparece quando há resultados.</div>
                @endif
                @if($excludedCount > 0)
                    <details class="ecpi83-exclusions" aria-label="E-mails retirados da prospecção">
                        <summary>Ver {{ number_format($excludedCount, 0, ',', '.') }} e-mail(s) removido(s) da minha seleção</summary>
                        <p class="ecpi80-muted">Esses endereços ficam fora das suas análises e novos rascunhos até você restaurá-los. Não é um descadastro global nem altera campanhas existentes. Mostrando os últimos 30.</p>
                        @foreach($excludedRecent as $removed)
                            <div class="ecpi83-exclusion-row">
                                <span>{{ $removed->normalized_email }}</span>
                                <form method="POST" action="{{ route('campaigns.prospecting.restore') }}">
                                    @csrf
                                    <input type="hidden" name="email" value="{{ $removed->normalized_email }}">
                                    @foreach($filters as $name => $value)
                                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                    @endforeach
                                    <input type="hidden" name="page" value="{{ $people->currentPage() }}">
                                    <button class="ecpi83-restore" type="submit" aria-label="Restaurar {{ $removed->normalized_email }}">Restaurar</button>
                                </form>
                            </div>
                        @endforeach
                    </details>
                @endif
                <div class="ecpi80-tablebox">
                    <table>
                        <thead><tr><th>Contato / e-mail</th><th>Empresa</th><th>Perfil</th><th>Origem</th><th>Última atividade direta</th><th class="ecpi83-actcol">Retirar</th></tr></thead>
                        <tbody>
                            @forelse($people as $row)
                                <tr>
                                    <td><strong>{{ $row->contact_name ?: 'Canal institucional' }}</strong><small>{{ $row->normalized_email }}</small></td>
                                    <td>
                                        @if($row->company_name)
                                            <strong>{{ $row->company_name }}</strong>
                                            <small>{{ match ($row->company_link_status ?? '') {
                                                'contact_company' => 'Empresa vinculada ao contato no HubSpot',
                                                'deal_company' => 'Empresa vinculada ao negócio no HubSpot',
                                                'contact_text' => 'Nome informado no contato (não validado por vínculo)',
                                                'local_company' => 'Cadastro da empresa no Prospector',
                                                'receita_company' => 'Cadastro da Receita Federal',
                                                default => 'Cadastro de origem'
                                            } }}</small>
                                        @else
                                            @if(in_array($row->company_link_status ?? '', ['contact_company', 'deal_company'], true))
                                                <span class="ecpi80-company-missing">Empresa vinculada sem nome cadastrado</span>
                                                <small>Complete o nome da empresa no HubSpot</small>
                                            @else
                                                <span class="ecpi80-company-missing">Sem empresa vinculada no espelho</span>
                                                <small>Confirme associações e sincronização do HubSpot</small>
                                            @endif
                                        @endif
                                    </td>
                                    <td><span class="ecpi80-pill">{{ $row->last_contact_at ? 'Reativação potencial' : 'Sem atividade direta' }}</span><small>{{ $row->job_title ?: 'Cargo não informado' }}</small></td>
                                    <td>{{ match ($row->origin) { 'hubspot' => 'HubSpot', 'manual' => 'Prospector', default => 'Receita' } }}</td>
                                    <td>{{ $row->last_contact_at ? \Carbon\Carbon::parse($row->last_contact_at)->timezone('America/Sao_Paulo')->format('d/m/Y') : 'Não informada' }}</td>
                                    <td class="ecpi83-actcol">
                                        <form method="POST" action="{{ route('campaigns.prospecting.exclude') }}">
                                            @csrf
                                            <input type="hidden" name="email" value="{{ $row->normalized_email }}">
                                            @foreach($filters as $name => $value)
                                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                            @endforeach
                                            <input type="hidden" name="page" value="{{ $people->currentPage() }}">
                                            <button type="submit" class="ecpi83-remove" title="Retirar e-mail da sua prospecção (pode restaurar depois)" aria-label="Retirar {{ $row->normalized_email }} da seleção automática">&times;</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6">Não há e-mails elegíveis com estes filtros.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="ecpi80-pages">
                    <span class="ecpi80-muted">Página {{ $people->currentPage() }} de {{ max(1, $people->lastPage()) }}</span>
                    <div class="ecpi80-actions" style="margin:0">
                        @if($people->previousPageUrl())
                            <a class="ecpi80-button" href="{{ $people->appends([...$filters, 'analyze' => '1'])->previousPageUrl() }}">Anterior</a>
                        @endif
                        @if($people->nextPageUrl())
                            <a class="ecpi80-button" href="{{ $people->appends([...$filters, 'analyze' => '1'])->nextPageUrl() }}">Próxima</a>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        @if($hasAnalysis && $people->total() > 0)
            <section class="ecpi80-card" id="ecpi80-draft" aria-labelledby="ecpi80-compose-title">
                <div class="ecpi80-cardhead">
                    <div><h2 id="ecpi80-compose-title">3. Preparar nova campanha</h2><p class="ecpi80-muted">Os destinatários serão selecionados automaticamente a partir da análise. Esta etapa cria somente um rascunho com até {{ number_format($cap, 0, ',', '.') }} contatos; nenhum e-mail será enviado agora.</p></div>
                </div>
                <form method="POST" action="{{ route('campaigns.prospecting.draft') }}">
                    @csrf
                    @foreach($filters as $key=>$value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    <div class="ecpi80-fields">
                        <label class="ecpi80-field">Nome da campanha
                            <input name="name" minlength="3" maxlength="150" value="{{ old('name', 'Prospecção inteligente - '.now()->format('d/m/Y')) }}" required>
                        </label>
                        <label class="ecpi80-field span-two">Assunto do e-mail
                            <input name="subject" minlength="3" maxlength="180" value="{{ old('subject') }}" placeholder="Assunto que será revisado antes do envio" required>
                        </label>
                    </div>
                    <label class="ecpi80-field" style="margin-top:14px">Mensagem
                        <textarea name="body" minlength="10" maxlength="12000" placeholder="Escreva a mensagem de apresentação comercial..." required>{{ old('body') }}</textarea>
                    </label>
                    <label class="ecpi80-check"><input type="checkbox" name="basis_reviewed" value="1" required><span>Revisei a finalidade comercial, a base legal e a pertinência do público. Confirmo que será criado apenas um rascunho, sem envio automático.</span></label>
                    <div class="ecpi80-actions">
                        <button type="submit" class="ecpi80-button ecpi80-button-primary">Criar rascunho com seleção automática</button>
                        <span class="ecpi80-muted">Os limites de destinatários e SMTP existentes continuam valendo.</span>
                    </div>
                </form>
            </section>
        @endif
        <p class="ecpi80-muted ecpi80-footnote">Fonte: espelhos locais do HubSpot, cadastros do Prospector, Receita Federal e histórico das campanhas. Antes do envio, atualize o CRM e reavalie clientes e negócios em andamento.</p>
    </main>
</x-layouts::app>
