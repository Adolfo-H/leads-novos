{{-- EC_WA_PUBLIC_COMPANY_V11: evidência dos números já cadastrados; escolha de empresa opcional. --}}
@include('contacts.whatsapp-public-v11-styles')
@php
    $wa11Summary = $this->summary;
    $wa11Public = $this->publicSiteMetrics;
    $wa11LastRun = $this->publicSiteRun;
    $wa11Company = $this->publicSelectedCompany;
    $wa11Format = static fn (int $v): string => number_format($v, 0, ',', '.');
@endphp
<section id="wa11-empresa" class="wa11-block" data-test="whatsapp-public-company-v11" aria-label="Conferência e pesquisa pública dos contatos">
    <div class="wa11-heading">
        <div>
            <span class="wa11-kicker">CONTATOS DO PROSPECTOR · PESQUISA POR EMPRESA</span>
            <h2>Verificar números que já estão no meu banco</h2>
            <p>Selecione uma empresa para cruzar os telefones vinculados a ela com os WhatsApps publicados em seu site. A fila acima continua mostrando todos os seus números e as confirmações reais.</p>
        </div>
        <span class="wa11-tag">Sem envio de mensagens</span>
    </div>
    {{-- EC_WA_VISUAL_V12: métricas repetidas removidas. --}}
    <div class="wa11-primary">
        <div class="wa11-primary-top">
            <div><strong>Pesquisar WhatsApp de uma empresa específica</strong><small>Busque uma empresa que tenha site cadastrado no HubSpot, escolha-a e veja quais números do seu banco aparecem naquele site.</small></div>
            <span class="wa11-status">Pesquisa em site público</span>
        </div>
        @if($wa11Company)
            <div class="wa11-company-label">
                <div><strong>{{ $wa11Company['company']->name ?: 'Empresa sem nome' }}</strong><small>{{ $wa11Company['domain'] }} · ID HubSpot: {{ $wa11Company['company']->hubspot_id }}</small></div>
                <button type="button" class="wa11-btn wa11-btn-outline" wire:click="clearPublicCompany">Trocar empresa</button>
            </div>
            <div class="wa11-cards">
                <div><small>Telefones vinculados à empresa</small><strong>{{ $wa11Format($wa11Company['phone_total']) }}</strong></div>
                <div><small>Divulgados no próprio site</small><strong>{{ $wa11Format($wa11Company['direct_total']) }}</strong></div>
                <div><small>WhatsApp já confirmado</small><strong>{{ $wa11Format($wa11Company['confirmed_total']) }}</strong></div>
            </div>
            <div class="wa11-search">
                <button type="button" class="wa11-btn" wire:click="startPublicCompany" wire:loading.attr="disabled" wire:target="startPublicCompany" @disabled($wa11LastRun && in_array($wa11LastRun->status, ['queued', 'running'], true))>
                    <span wire:loading.remove wire:target="startPublicCompany">Pesquisar site desta empresa agora</span>
                    <span wire:loading wire:target="startPublicCompany">Colocando na fila...</span>
                </button>
                @if($wa11Company['check'])
                    <span class="wa11-note">Última consulta: {{ $wa11Company['check']->checked_at ? \Carbon\Carbon::parse($wa11Company['check']->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : 'aguardando' }} · {{ match($wa11Company['check']->status) {'done' => 'concluída', 'running' => 'em execução', 'queued' => 'na fila', default => 'site indisponível ou falha'} }}</span>
                @endif
            </div>
            <div class="wa11-note">A pesquisa é feita pelo worker Laravel. Se o site foi consultado há menos de 10 minutos, reutilizamos o resultado salvo.</div>
            <div style="margin-top:17px"><strong style="font-size:13px">Telefones do seu cadastro vinculados à empresa</strong></div>
            @forelse($wa11Company['phones'] as $phone)
                <div class="wa11-row" wire:key="wa11-company-phone-{{ $phone->id }}">
                    <div class="wa11-row-main">
                        <strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($phone->e164, $phone->e164) }}</strong>
                        <small>{{ $phone->kind === 'mobile' ? 'Celular' : 'Fixo' }} · {{ (int) $phone->contacts_count }} registro(s) com este número</small>
                    </div>
                    <div class="wa11-row-actions">
                        @if($phone->published_here)
                            <span class="wa11-status same">Divulgado neste site</span>
                        @else
                            <span class="wa11-status pending">Não localizado neste site</span>
                        @endif
                        <span class="wa11-status {{ $phone->status === 'yes' ? 'yes' : '' }}">{{ match ($phone->status) {'yes' => 'Tem WhatsApp', 'no' => 'Não possui', default => 'A verificar'} }}</span>
                        <button type="button" class="wa11-link" wire:click="openCompanyPublicNumber({{ $phone->id }})" x-on:click="$dispatch('wa12-show-numbers')">Abrir no diretório ›</button>
                    </div>
                </div>
            @empty
                <div class="wa11-empty">Não há telefones associados diretamente a esta empresa pelo HubSpot ou por vínculo fiscal confiável. A pesquisa no site ainda pode encontrar canais novos, mas não vamos vinculá-los a contatos de outra empresa por suposição.</div>
            @endforelse
            @if($wa11Company['phone_total'] > $wa11Company['phones']->count())
                <p class="wa11-note">Exibindo os primeiros {{ $wa11Company['phones']->count() }} telefones vinculados.</p>
            @endif
            <div style="margin-top:18px"><strong style="font-size:13px">WhatsApps publicados no site desta empresa</strong></div>
            @forelse($wa11Company['evidences'] as $evidence)
                <div class="wa11-row" wire:key="wa11-evidence-{{ $evidence->id }}">
                    <div class="wa11-row-main"><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($evidence->e164, $evidence->e164) }}</strong><small>{{ $evidence->domain }} · {{ \Carbon\Carbon::parse($evidence->last_seen_at)->timezone('America/Sao_Paulo')->format('d/m/Y') }}</small></div>
                    <div class="wa11-row-actions">
                        @if($evidence->phone_id)
                            <span class="wa11-status same">No cadastro desta empresa</span>
                        @elseif($evidence->global_phone_id)
                            <span class="wa11-status other">No Prospector, outro vínculo</span>
                        @else
                            <span class="wa11-status">Novo número</span>
                        @endif
                        <a class="wa11-link" href="{{ $evidence->source_url }}" rel="noopener noreferrer" target="_blank">Ver fonte ↗</a>
                        @if($evidence->phone_id || $evidence->global_phone_id)
                            <button type="button" class="wa11-link" wire:click="openPublicNumber({{ (int) ($evidence->phone_id ?: $evidence->global_phone_id) }})" x-on:click="$dispatch('wa12-show-numbers')">Ver cadastro ›</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="wa11-empty">Nenhum link explícito de WhatsApp foi encontrado nas páginas públicas já pesquisadas deste domínio. Isso não prova ausência de WhatsApp.</p>
            @endforelse
        @else
            <div class="wa11-search">
                <label>Buscar empresa por nome ou domínio
                    <input type="search" wire:model.live.debounce.400ms="publicCompanyQuery" placeholder="Ex.: Cooperativa, Comércio, empresa.com.br..." autocomplete="off" maxlength="100">
                </label>
            </div>
            @if(mb_strlen(trim($publicCompanyQuery)) >= 2)
                <div class="wa11-options" aria-label="Empresas com domínio cadastrado">
                    @forelse($this->publicCompanyCandidates as $candidate)
                        <button type="button" class="wa11-company-button" wire:key="wa11-candidate-{{ $candidate->id }}" wire:click="selectPublicCompany({{ $candidate->id }})">
                            <strong>{{ $candidate->name ?: 'Empresa HubSpot' }}</strong><small>{{ $candidate->domain }}</small>
                        </button>
                    @empty
                        <p class="wa11-empty">Nenhuma empresa com domínio público foi encontrada no HubSpot. A V11 usa os sites já cadastrados, sem deduzir URLs por nome.</p>
                    @endforelse
                </div>
            @else
                <p class="wa11-note">Digite pelo menos 2 caracteres e escolha uma empresa. A pesquisa não será iniciada até você clicar no botão dela.</p>
            @endif
        @endif
    </div>

    @if(session('wa_public_success'))<div class="wa11-message" role="status">{{ session('wa_public_success') }}</div>@endif
    @error('public_site')<div class="wa11-message error" role="alert">{{ $message }}</div>@enderror

    <div class="wa11-secondary">
        <details>
            <summary>WhatsApps divulgados pelas empresas · Ver pesquisas gerais e programação diária</summary>
            <p class="wa11-note">Esta é uma pesquisa complementar: o objetivo principal é conferir os números que já estão no seu cadastro. As divulgações sem correspondência não criam contatos automaticamente.</p>
            <div class="wa11-general">
                <div><strong style="font-size:12px">{{ $wa11Format($wa11Public['done']) }} sites pesquisados</strong><small style="display:block;color:#7f96a4">{{ $wa11Format($wa11Public['links']) }} links · {{ $wa11Format($wa11Public['new']) }} números ainda ausentes</small></div>
                <div class="wa11-general-controls">
                    <label>Sites por lote
                        <select wire:model="publicBatchSites"><option value="3">3</option><option value="5">5</option><option value="10">10</option><option value="20">20</option></select>
                    </label>
                    <button type="button" class="wa11-btn wa11-btn-outline" wire:click="startPublicSites" wire:loading.attr="disabled" wire:target="startPublicSites" @disabled($wa11LastRun && in_array($wa11LastRun->status, ['queued','running'], true))>Pesquisar agora</button>
                </div>
            </div>
            <div class="wa11-general">
                <div><strong style="font-size:12px">Rotina diária opcional</strong><small style="display:block;color:#7f96a4">Sites públicos em dias úteis às 07h10 · independente da consulta WhatsApp Web.</small></div>
                <div class="wa11-general-controls">
                    <label>Sites por dia
                        <select wire:model="publicDailySites"><option value="5">5</option><option value="10">10</option><option value="20">20</option></select>
                    </label>
                    <label class="wa11-checkbox"><input type="checkbox" wire:model="publicDailyEnabled">Ativar</label>
                    <button type="button" class="wa11-btn wa11-btn-outline" wire:click="savePublicSitesSchedule">Salvar programação</button>
                </div>
            </div>
            @if($wa11LastRun)<p class="wa11-note">Pesquisa geral #{{ $wa11LastRun->id }}: {{ (int) $wa11LastRun->processed_sites }}/{{ (int) $wa11LastRun->requested_sites }} sites processados · {{ $wa11LastRun->status }}</p>@endif
            <strong style="font-size:12px">Descobertas recentes (últimas 12)</strong>
            @forelse($this->publicSiteRecent as $item)
                <div class="wa11-row" wire:key="wa11-recent-{{ $item->id }}">
                    <div class="wa11-row-main"><strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($item->e164, $item->e164) }}</strong><small>{{ $item->company_label ?: $item->domain }}</small></div>
                    <div class="wa11-row-actions">
                        <span class="wa11-status {{ $item->phone_id ? 'same' : ($item->global_phone_id ? 'other' : '') }}">{{ $item->phone_id ? 'Contato da empresa' : ($item->global_phone_id ? 'No Prospector' : 'Novo número') }}</span>
                        <a href="{{ $item->source_url }}" class="wa11-link" target="_blank" rel="noopener noreferrer">Ver fonte ↗</a>
                        @if($item->phone_id || $item->global_phone_id)
                            <button type="button" class="wa11-link" wire:click="openPublicNumber({{ (int) ($item->phone_id ?: $item->global_phone_id) }})" x-on:click="$dispatch('wa12-show-numbers')">Ver cadastro ›</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="wa11-empty">Nenhuma divulgação encontrada nas últimas pesquisas.</p>
            @endforelse
        </details>
    </div>
    <div class="wa11-footer">Um telefone divulgado em site público é um indício de uso do WhatsApp, mas não comprova que a conta continua ativa. “Tem WhatsApp” exige confirmação já existente por interação ou consulta; resultado inconclusivo não vira “Não possui”.</div>
</section>
