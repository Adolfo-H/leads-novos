{{-- EC_WA_PUBLIC_SITES_V10: pesquisa de sites públicos; nenhuma chamada à Meta/WhatsApp. --}}
@include('contacts.whatsapp-public-v10-styles')
@php
    $publicSummary = $this->publicSiteMetrics;
    $publicRun = $this->publicSiteRun;
    $publicRecent = $this->publicSiteRecent;
@endphp
<section class="wa10-public" aria-label="Pesquisa pública de WhatsApp" data-test="whatsapp-public-sites-v10">
    <div class="wa10-head">
        <div>
            <div class="wa10-eyebrow">PESQUISA PÚBLICA · SEM CONECTAR O WHATSAPP</div>
            <h2>WhatsApps divulgados pelas empresas</h2>
            <p>Localiza links publicados nos domínios cadastrados no HubSpot e compara com os números dos contatos. Um link público é uma evidência de divulgação, não uma confirmação de conta ativa.</p>
        </div>
        <span class="wa10-chip wa10-chip-safe">Sem mensagens nem QR Code</span>
    </div>
    <div class="wa10-stats">
        <div><span>Sites pesquisados</span><strong>{{ $fmt($publicSummary['done']) }}</strong></div>
        <div class="wa10-stat-highlight"><span>Números do índice divulgados</span><strong>{{ $fmt($publicSummary['matched']) }}</strong><small>Correspondência com contatos vinculados</small></div>
        <div><span>Novos números publicados</span><strong>{{ $fmt($publicSummary['new']) }}</strong><small>Aguardando revisão</small></div>
        <div><span>Links públicos encontrados</span><strong>{{ $fmt($publicSummary['links']) }}</strong></div>
    </div>
    <div class="wa10-controls">
        <div class="wa10-run">
            <div class="wa10-panel-title"><strong>Pesquisar sites</strong><small>Somente páginas públicas dos domínios registrados no HubSpot</small></div>
            <div class="wa10-run-actions">
                <label>Sites por lote
                    <select wire:model="publicBatchSites" aria-label="Sites por pesquisa pública"><option value="3">3</option><option value="5">5</option><option value="10">10</option><option value="20">20</option></select>
                </label>
                <button type="button" wire:click="startPublicSites" wire:loading.attr="disabled" wire:target="startPublicSites"
                    @disabled($publicRun && in_array($publicRun->status, ['queued','running'], true))>
                    <span wire:loading.remove wire:target="startPublicSites">Pesquisar agora</span>
                    <span wire:loading wire:target="startPublicSites">Preparando lote...</span>
                </button>
            </div>
            @if($publicRun)
                <div class="wa10-run-progress">
                    <span>Última pesquisa #{{ $publicRun->id }} · {{ match ($publicRun->status) {'queued' => 'Aguardando worker', 'running' => 'Em andamento', 'completed' => 'Concluída', default => 'Indisponível'} }}</span>
                    <b>{{ (int) $publicRun->processed_sites }}/{{ (int) $publicRun->requested_sites }} sites</b>
                </div>
            @else
                <p class="wa10-helper">Primeira pesquisa ainda não iniciada. Não é necessário conectar suas contas comerciais.</p>
            @endif
        </div>
        <div class="wa10-schedule">
            <div class="wa10-panel-title"><strong>Rotina diária opcional</strong><small>Pesquisa pública, separada das consultas pelo WhatsApp Web</small></div>
            <div class="wa10-schedule-row">
                <label>Sites por dia
                    <select wire:model="publicDailySites" aria-label="Sites por dia"><option value="5">5</option><option value="10">10</option><option value="20">20</option></select>
                </label>
                <label class="wa10-checkbox"><input type="checkbox" wire:model="publicDailyEnabled"><span>Pesquisar em dias úteis às 07h10</span></label>
            </div>
            <button type="button" class="wa10-secondary" wire:click="savePublicSitesSchedule" wire:loading.attr="disabled" wire:target="savePublicSitesSchedule">Salvar programação</button>
            <small>Inicialmente desativado. O scheduler usa a fila padrão Laravel, com até duas páginas por domínio.</small>
        </div>
    </div>
    @if (session('wa_public_success'))<div class="wa10-notice" role="status">{{ session('wa_public_success') }}</div>@endif
    @error('public_site')<div class="wa10-error" role="alert">{{ $message }}</div>@enderror
    <div class="wa10-evidence-head"><strong>Últimas descobertas</strong><small>Fonte clicável para conferência e números publicados ainda sem cadastro</small></div>
    @if($publicRecent->isEmpty())
        <div class="wa10-empty">Nenhuma divulgação localizada ainda. Inicie uma pesquisa para buscar links públicos de WhatsApp nos sites das empresas.</div>
    @else
        <div class="wa10-evidence-list">
            @foreach($publicRecent as $item)
                <div class="wa10-evidence-item" wire:key="wa10-link-{{ $item->id }}">
                    <div class="wa10-evidence-main">
                        <strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($item->e164, $item->e164) }}</strong>
                        <small>{{ $item->company_label ?: $item->domain }} · {{ $item->domain }}</small>
                    </div>
                    <span class="wa10-chip {{ $item->phone_id ? 'wa10-chip-safe' : 'wa10-chip-review' }}">{{ $item->phone_id ? 'No diretório' : 'Novo número' }}</span>
                    <a href="{{ $item->source_url }}" target="_blank" rel="noopener noreferrer" title="Abrir a página de origem">Ver fonte ↗</a>
                    @if($item->phone_id)
                        <button type="button" wire:click="openPublicNumber({{ (int) $item->phone_id }})">Ver contato ›</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
    <p class="wa10-footer">A pesquisa não confirma que uma conta continua ativa, não altera os resultados do WhatsApp Web e não cria pessoas a partir de números institucionais.</p>
</section>
