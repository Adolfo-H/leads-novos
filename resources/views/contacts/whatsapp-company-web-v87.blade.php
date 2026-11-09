{{-- V87: web pública opcional; os achados NÃO se tornam contatos nem confirmações. --}}
@php
    $wa87TargetKey = (string) $wa87TargetKey;
    $wa87Research = app(\App\Services\Contacts\WhatsappCompanyWebResearchV87::class);
    $wa87Providers = $wa87Research->providers();
    $wa87Ready = \Illuminate\Support\Facades\Schema::hasTable('whatsapp_company_web_runs_v87')
        && \Illuminate\Support\Facades\Schema::hasTable('whatsapp_company_web_findings_v87');
    $wa87Latest = $wa87Ready ? $wa87Research->latest($wa87TargetKey) : null;
    $wa87Found = $wa87Ready ? $wa87Research->findings($wa87TargetKey) : collect();
    $wa87IsStale = $wa87Latest && in_array($wa87Latest->status, ['queued', 'running'], true)
        && \Carbon\Carbon::parse((string) $wa87Latest->updated_at, 'UTC')->lt(now('UTC')->subMinutes(6));
    $wa87Busy = $wa87Latest && in_array($wa87Latest->status, ['queued', 'running'], true) && ! $wa87IsStale;
    $wa87HasSite = is_string($wa87KnownDomain ?? null) && trim($wa87KnownDomain) !== '';
    $wa87CanStart = $wa87Ready && ($wa87HasSite || $wa87Providers['tavily'] || $wa87Providers['brave']);
    $wa87Sources = $wa87Latest && $wa87Latest->source_status
        ? json_decode((string) $wa87Latest->source_status, true) : [];
@endphp
<section class="wa87-panel" wire:poll.visible.15s aria-label="Pesquisar telefones em fontes públicas" data-test="wa87-multisource-research">
    <style>
        .wa87-panel{padding:17px;margin:17px 0;border:1px solid #bce2dd;border-radius:11px;background:#f5fbf9;color:#1b4751}
        .wa87-head{display:flex;align-items:flex-start;justify-content:space-between;gap:13px;flex-wrap:wrap}
        .wa87-head strong{font-size:15px;color:#0d514a;display:block;margin-bottom:5px}
        .wa87-head p{margin:0;max-width:820px;color:#567b83;font-size:12px;line-height:1.55}
        .wa87-action{background:#008b7f;color:#fff;border:1px solid #008b7f;border-radius:8px;padding:10px 13px;cursor:pointer;font-weight:750;font-size:12px}
        .wa87-action:disabled{opacity:.5;cursor:not-allowed}
        .wa87-state{background:#fff;border:1px solid #d9e9e8;border-radius:8px;padding:11px 13px;margin:12px 0 8px;font-size:12px;color:#385967;line-height:1.55}
        .wa87-state strong{color:#184e50}
        .wa87-alert{background:#fff2e9;border-color:#f2d2b5;color:#8a4a2b}
        .wa87-result{padding:11px 3px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;border-bottom:1px solid #dcebe7}
        .wa87-result:last-child{border-bottom:none}
        .wa87-result strong{display:block;font-size:13px;color:#163f50;margin-bottom:5px}
        .wa87-result small{display:block;font-size:11px;color:#5b7d8b;line-height:1.5;overflow-wrap:anywhere}
        .wa87-label{display:inline-flex;padding:4px 7px;border-radius:7px;background:#e5f2f0;color:#1b726a;font-weight:700;font-size:10px;margin-bottom:5px}
        .wa87-label.ordinary{background:#f3efe7;color:#866128}
        .wa87-source{color:#087d75;font-size:12px;font-weight:700;text-decoration:underline;white-space:nowrap}
        .wa87-context{font-size:11px;color:#68828d;margin-top:10px}
        .wa87-count{font-size:11px;color:#577b84;font-weight:700;margin-top:12px}
    </style>
    <div class="wa87-head">
        <div>
            <strong>Pesquisar telefones e WhatsApps na internet</strong>
            <p>Procura em resultados públicos da Tavily/Brave e no site cadastrado, quando houver. Não depende de o telefone já existir no CRM. Exibe a fonte de cada achado; não envia mensagens nem confirma WhatsApp automaticamente.</p>
        </div>
        <button type="button" class="wa87-action" wire:click="startCompanyWebResearchV87(@js($wa87TargetKey))"
            wire:loading.attr="disabled" wire:target="startCompanyWebResearchV87"
            @disabled($wa87Busy || ! $wa87CanStart)>
            {{ $wa87Busy ? 'Pesquisa em andamento' : ($wa87Found->isEmpty() ? 'Iniciar pesquisa na web' : 'Pesquisar novamente') }}
        </button>
    </div>
    <div class="wa87-context">Fontes disponíveis: site cadastrado {{ $wa87HasSite ? 'sim' : 'não' }} · Tavily {{ $wa87Providers['tavily'] ? 'configurada' : 'não configurada' }} · Brave {{ $wa87Providers['brave'] ? 'configurada' : 'não configurada' }}.</div>
    @error('wa87_search')
        <div class="wa87-state wa87-alert" role="alert">{{ $message }}</div>
    @enderror
    @if(! $wa87Ready)
        <div class="wa87-state wa87-alert">A atualização do banco da V87 ainda não foi executada. Rode <code>./vendor/bin/sail artisan migrate --force</code> no projeto antes de pesquisar.</div>
    @elseif(! $wa87CanStart)
        <div class="wa87-state wa87-alert">Esta empresa não tem site identificado e nenhuma API de busca pública está configurada. Configure <code>TAVILY_API_KEY</code> ou <code>WA_PUBLIC_BRAVE_SEARCH_API_KEY</code> no servidor. Sem um provedor não é possível pesquisar a internet apenas pelo nome.</div>
    @endif
    @if($wa87Latest)
        <div class="wa87-state {{ $wa87IsStale || in_array($wa87Latest->status, ['failed', 'unavailable', 'timed_out'], true) ? 'wa87-alert' : '' }}" role="status">
            <strong>Última pesquisa #{{ (int) $wa87Latest->id }}:</strong>
            @if($wa87IsStale)
                A fila não concluiu em 6 minutos. Confira o worker <code>queue-worker</code>; você pode solicitar nova busca, sem apagar os achados anteriores.
            @elseif($wa87Latest->status === 'queued')
                Aguardando o worker Laravel executar a busca. Se continuar assim, confira o processamento da fila <code>default</code>.
            @elseif($wa87Latest->status === 'running')
                Consultando as fontes públicas. A pesquisa pode retornar números comuns sem link de WhatsApp.
            @elseif($wa87Latest->status === 'completed')
                Concluída. {{ (int) $wa87Latest->findings_count }} achado(s) com fonte. {{ (int) $wa87Latest->findings_count === 0 ? 'As fontes responderam, mas não trouxeram um telefone identificável para esta empresa.' : 'Confira a fonte antes de usar.' }}
            @elseif($wa87Latest->status === 'unavailable')
                Nenhuma fonte respondeu com sucesso. Confira as credenciais, cotas das APIs e acesso ao site; isso não significa que a empresa não tenha telefone.
            @else
                A execução falhou ou expirou. Consulte <code>docker compose --profile background logs --tail=40 queue-worker</code>.
            @endif
            @if(is_array($wa87Sources) && $wa87Sources !== [])
                <div class="wa87-context">Fontes: @foreach($wa87Sources as $wa87Name => $wa87Value) {{ $wa87Name }}: {{ $wa87Value }}{{ ! $loop->last ? ' · ' : '' }} @endforeach</div>
            @endif
        </div>
    @else
        <div class="wa87-state">Pesquisa ainda não realizada para esta empresa. A ausência de resultados no cadastro local não significa que não existam telefones públicos.</div>
    @endif
    @if($wa87Found->isNotEmpty())
        <div class="wa87-count">{{ $wa87Found->count() }} evidência(s) exibida(s) — da última pesquisa com resultados, mesmo que a tentativa mais recente tenha falhado.</div>
        @foreach($wa87Found as $wa87Item)
            <div class="wa87-result" wire:key="wa87-found-{{ $wa87Item->id }}">
                <div>
                    <strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display((string) $wa87Item->phone_e164, (string) $wa87Item->phone_e164) }}</strong>
                    <span class="wa87-label {{ $wa87Item->kind !== 'explicit_whatsapp_link' ? 'ordinary' : '' }}">
                        {{ $wa87Item->kind === 'explicit_whatsapp_link' ? 'Link público de WhatsApp — não confirmado' : 'Telefone público — WhatsApp não verificado' }}
                    </span>
                    <small>{{ $wa87Item->title ?: 'Fonte pública' }} · {{ match($wa87Item->provider) { 'tavily' => 'Índice Tavily', 'brave' => 'Índice Brave', default => 'Site cadastrado' } }}</small>
                </div>
                <a class="wa87-source" href="{{ $wa87Item->source_url }}" target="_blank" rel="noopener noreferrer nofollow">Conferir fonte</a>
            </div>
        @endforeach
    @endif
    <p class="wa87-context">Os números são indícios encontrados publicamente. O sistema não os associa aos contatos nem os marca como “Tem WhatsApp”. Confira a empresa e a origem antes de utilizá-los.</p>
</section>
