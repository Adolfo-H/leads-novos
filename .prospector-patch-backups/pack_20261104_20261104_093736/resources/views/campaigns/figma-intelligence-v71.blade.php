{{-- CAMPANHAS_FIGMA_V71_M12: segmentação e continuidade de campanha. Preserva todos os fluxos existentes. --}}
<div class="eci9 ec71-page ec71-intelligence" data-campaign-figma="M12-V71">
    <header class="ec71-hero">
        <span class="ec71-eyebrow">CAMPANHAS <span class="ec71-head-chip">Inteligência</span></span>
        <h1>Inteligência comercial</h1>
        <p>Organize segmentos, prepare abordagens e acompanhe follow-ups com base em contatos elegíveis.</p>
        <div class="ec71-actions">
            <a class="ec71-btn ec71-btn-outline" href="#ec71-rules">Pré-visualizar</a>
            <a class="ec71-btn ec71-btn-primary" href="#ec71-save-segment">Salvar segmento</a>
            <a class="ec71-btn ec71-btn-outline" href="#ec71-draft">Criar rascunho</a>
            <a class="ec71-btn ec71-btn-outline" href="#ec71-followups">Follow-ups</a>
        </div>
    </header>

    @include('campaigns.module-nav')

    @if (session('intelligence_success'))
        <div class="ec71-notice ec71-notice-success" role="status">{{ session('intelligence_success') }}</div>
    @endif
    @if ($errors->any())
        <div class="ec71-notice ec71-notice-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <nav class="ec71-page-tabs" aria-label="Áreas de inteligência comercial">
        <a href="#ec71-rules">Segmentos</a>
        <a href="#ec71-draft">Rascunho por segmento</a>
        <a href="#ec71-followups">Follow-up</a>
        <a href="#ec71-replies">Atendimento individual</a>
    </nav>

    <section class="ec71-kpis" aria-label="Resultados reais dos últimos 90 dias">
        <article class="ec71-kpi"><span>Campanhas em 90 dias</span><strong>{{ number_format((int) $metrics['campaigns'], 0, ',', '.') }}</strong><small>Campanhas registradas</small></article>
        <article class="ec71-kpi"><span>Aceitos SMTP</span><strong>{{ number_format((int) $metrics['smtp_accepted'], 0, ',', '.') }}</strong><small>Aceitação, não abertura</small></article>
        <article class="ec71-kpi"><span>Respostas comerciais</span><strong>{{ number_format((int) $metrics['responses'], 0, ',', '.') }}</strong><small>Respostas identificadas</small></article>
        <article class="ec71-kpi"><span>Devoluções</span><strong>{{ number_format((int) $metrics['bounces'], 0, ',', '.') }}</strong><small>Descadastros: {{ (int) $metrics['unsubscribes'] }}</small></article>
    </section>
    <p class="ec71-small-context">Contatos associados a negócios no HubSpot: {{ number_format((int) $metrics['contacts_with_hubspot_deals'], 0, ',', '.') }}. Isso não representa atribuição de vendas.</p>

    <section class="ec71-card" id="ec71-rules" aria-labelledby="ec71-rules-title">
        <div class="ec71-card-header"><h2 id="ec71-rules-title">Regras do segmento</h2><p>Defina o público e consulte os contatos elegíveis antes de salvar.</p></div>
        <form method="GET" action="{{ route('campaigns.intelligence.index') }}" class="ec71-form">
            <div class="ec71-grid-two">
                <label class="ec71-field" for="ec71-origin"><span>Origem</span>
                    <select name="origin" id="ec71-origin">
                        <option value="all">Todas</option>
                        <option value="hubspot" @selected($filters['origin'] === 'hubspot')>HubSpot</option>
                        <option value="manual" @selected($filters['origin'] === 'manual')>Cadastro manual</option>
                        <option value="receita" @selected($filters['origin'] === 'receita')>Receita Federal · canal institucional</option>
                    </select>
                    <small>Origem do registro cadastral</small>
                </label>
                <label class="ec71-field" for="ec71-company"><span>Empresa contém</span>
                    <input id="ec71-company" name="company" maxlength="100" value="{{ $filters['company'] }}" placeholder="Nome da empresa">
                    <small>Pesquisa por nome da empresa</small>
                </label>
                <label class="ec71-field" for="ec71-job"><span>Cargo contém</span>
                    <input id="ec71-job" name="job" maxlength="100" value="{{ $filters['job'] }}" placeholder="Ex.: Fiscal ou Contabilidade">
                </label>
                <label class="ec71-field" for="ec71-search"><span>Nome contém</span>
                    <input id="ec71-search" name="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="Nome do contato">
                </label>
            </div>
            <button type="submit" class="ec71-btn ec71-btn-outline">Pré-visualizar segmento</button>
        </form>
        <form id="ec71-save-segment" method="POST" action="{{ route('campaigns.intelligence.segments.save') }}" class="ec71-segment-save">
            @csrf
            @foreach (['origin', 'company', 'job', 'search'] as $name)
                <input type="hidden" name="{{ $name }}" value="{{ $filters[$name] }}">
            @endforeach
            <input type="hidden" name="exclude_customers" value="1">
            <label class="ec71-field" for="ec71-segment-name"><span>Nome do segmento</span>
                <input id="ec71-segment-name" name="segment_name" required minlength="3" maxlength="150" placeholder="Ex.: Gestores fiscais — cooperativas">
            </label>
            <button type="submit" class="ec71-btn ec71-btn-primary">Salvar segmento</button>
        </form>
    </section>

    <section class="ec71-card" aria-labelledby="ec71-preview-title">
        <div class="ec71-card-header"><h2 id="ec71-preview-title">Prévia do segmento</h2><p>Até 40 contatos elegíveis · resultado da pesquisa atual.</p></div>
        <div class="ec71-table-scroll">
            <table class="ec71-table"><thead><tr><th>Contato</th><th>E-mail</th><th>Empresa / cargo</th><th>Origem</th></tr></thead>
                <tbody>
                    @forelse ($preview as $person)
                        <tr><td><strong>{{ $person['name'] }}</strong></td><td>{{ $person['email'] }}</td><td>{{ $person['company'] }} <small>{{ $person['job'] }}</small></td><td>{{ $person['origin'] }} @if ($person['needs_review'])<span class="ec71-badge">Revisar cliente</span>@endif</td></tr>
                    @empty
                        <tr><td colspan="4" class="ec71-empty">Nenhum contato elegível para esses filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="ec71-small-context">A elegibilidade é revalidada na criação do rascunho. Segmentos não disparam mensagens.</p>
    </section>

    <div class="ec71-notice ec71-notice-warning" role="note">
        <span class="ec71-notice-icon" aria-hidden="true">!</span>
        <div><strong>Exclusões e revisão</strong><p>Endereços bloqueados, clientes identificados e contatos já trabalhados são tratados conforme os critérios disponíveis. Cadastros manuais e canais institucionais exigem análise antes de campanhas.</p></div>
    </div>

    <div class="ec71-grid-two ec71-workflows">
        <section class="ec71-card" id="ec71-draft">
            <div class="ec71-card-header"><h2>Criar campanha em rascunho</h2><p>Utiliza os contatos elegíveis do segmento salvo; não envia e-mails automaticamente.</p></div>
            <form action="{{ route('campaigns.intelligence.draft') }}" method="POST" class="ec71-stacked-form">
                @csrf
                <label class="ec71-field" for="ec71-draft-segment"><span>Segmento salvo</span>
                    <select id="ec71-draft-segment" name="segment_id" required>
                        @foreach ($segments as $segment)<option value="{{ $segment->id }}">{{ $segment->name }}</option>@endforeach
                    </select>
                </label>
                <label class="ec71-field" for="ec71-draft-name"><span>Nome da campanha</span><input id="ec71-draft-name" name="name" required minlength="3" maxlength="150" placeholder="Nome interno"></label>
                <label class="ec71-field" for="ec71-draft-subject"><span>Assunto</span><input id="ec71-draft-subject" name="subject" required minlength="3" maxlength="180" placeholder="Assunto da mensagem"></label>
                <label class="ec71-field" for="ec71-draft-body"><span>Mensagem</span><textarea id="ec71-draft-body" name="body" required minlength="10" maxlength="12000" placeholder="Olá, contato..."></textarea></label>
                <label class="ec71-checkbox"><input type="checkbox" name="basis_reviewed" value="1" required><span>Revisei a base legal, os contatos e a finalidade da abordagem.</span></label>
                <button class="ec71-btn ec71-btn-primary" type="submit" @disabled($segments->isEmpty())>Gerar rascunho para aprovação</button>
                @if ($segments->isEmpty())<p class="ec71-hint">Salve primeiro um segmento para habilitar esta ação.</p>@endif
            </form>
        </section>
        <section class="ec71-card" id="ec71-followups">
            <div class="ec71-card-header"><h2>Planejamento de follow-up</h2><p>Prepare um acompanhamento posterior apenas de contatos elegíveis, sem enviar na criação.</p></div>
            <form action="{{ route('campaigns.intelligence.sequence') }}" method="POST" class="ec71-stacked-form">
                @csrf
                <label class="ec71-field" for="ec71-source"><span>Campanha original</span>
                    <select id="ec71-source" name="email_campaign_id" required>
                        @foreach ($campaigns as $campaign)<option value="{{ $campaign->id }}">#{{ $campaign->id }} · {{ $campaign->name }} ({{ $campaign->status }})</option>@endforeach
                    </select>
                </label>
                <label class="ec71-field" for="ec71-sequence-name"><span>Nome da etapa</span><input id="ec71-sequence-name" name="name" required minlength="3" maxlength="150" placeholder="Retorno após apresentação"></label>
                <label class="ec71-field" for="ec71-sequence-delay"><span>Dias desde o aceite SMTP</span><input id="ec71-sequence-delay" name="delay_days" type="number" min="1" max="90" value="5" required></label>
                <label class="ec71-field" for="ec71-sequence-subject"><span>Assunto do follow-up</span><input id="ec71-sequence-subject" name="subject" required minlength="3" maxlength="180" placeholder="Assunto da retomada"></label>
                <label class="ec71-field" for="ec71-sequence-body"><span>Mensagem do follow-up</span><textarea id="ec71-sequence-body" name="body" required minlength="10" maxlength="12000"></textarea></label>
                <button class="ec71-btn ec71-btn-primary" type="submit" @disabled($campaigns->isEmpty())>Salvar planejamento</button>
            </form>
        </section>
    </div>

    <section class="ec71-card" id="ec71-followup-plans" aria-labelledby="ec71-plans-title">
        <div class="ec71-card-header"><h2 id="ec71-plans-title">Planos de follow-up</h2><p>Rascunhos programados para revisão do responsável.</p></div>
        <div class="ec71-table-scroll"><table class="ec71-table"><thead><tr><th>Plano</th><th>Campanha de origem</th><th>Espera</th><th>Situação</th><th>Ação</th></tr></thead><tbody>
            @forelse ($sequences as $seq)
                <tr><td><strong>{{ $seq->name }}</strong></td><td>#{{ $seq->email_campaign_id }}</td><td>{{ $seq->delay_days }} dias</td><td><span class="ec71-badge">{{ $seq->status }}</span>@if ($seq->draft_campaign_id) <small>#{{ $seq->draft_campaign_id }}</small> @endif</td>
                    <td>@if ($seq->status === 'planned')
                        <form method="POST" action="{{ route('campaigns.intelligence.prepare', ['sequence' => $seq->id]) }}" class="ec71-plan-action">@csrf
                            <label class="ec71-checkbox"><input name="confirm_draft" value="1" type="checkbox" required><span>Somente rascunho</span></label>
                            <button class="ec71-btn ec71-btn-outline" type="submit">Preparar</button>
                        </form>
                    @else — @endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="ec71-empty">Ainda não existem follow-ups planejados.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <section class="ec71-card" id="ec71-replies">
        <div class="ec71-card-header"><h2>Atendimento individual</h2><p>Resposta somente ao endereço que respondeu à campanha. Não permite listas, anexos ou destinatários avulsos.</p></div>
        @unless ($repliesEnabled)<div class="ec71-notice ec71-notice-warning" role="status"><div><strong>Respostas individuais indisponíveis</strong><p>Esta opção requer envio comercial habilitado e autorização específica para respostas.</p></div></div>@endunless
        <form method="POST" action="{{ route('campaigns.intelligence.reply') }}" class="ec71-stacked-form">
            @csrf
            <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label class="ec71-field" for="ec71-reply-id"><span>Resposta recebida</span>
                <select id="ec71-reply-id" name="reply_id" required>
                    @foreach ($replies as $reply)<option value="{{ $reply->id }}">#{{ $reply->id }} · {{ $reply->from_email }} · {{ $reply->subject }}</option>@endforeach
                </select>
            </label>
            <label class="ec71-field" for="ec71-reply-body"><span>Sua mensagem</span><textarea id="ec71-reply-body" name="body" required minlength="5" maxlength="10000"></textarea></label>
            <label class="ec71-checkbox"><input type="checkbox" name="confirm_recipient" value="1" required><span>Confirmo que é o destinatário correto e autorizo esta resposta individual.</span></label>
            <button class="ec71-btn ec71-btn-primary" type="submit" @disabled(! $repliesEnabled || $replies->isEmpty())>Enviar resposta individual</button>
        </form>
        <p class="ec71-small-context">O sistema respeita descadastros, respostas e devoluções. Aceitação SMTP não comprova recebimento.</p>
    </section>
    <footer class="ec71-page-foot">ExportControl Prospector · Dados reais registrados em Campanhas</footer>
</div>
