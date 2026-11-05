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
            {{-- EC_WA_PUBLIC_CHANNELS_V13: canal salvo com empresa e fonte, sem pessoa fictícia. --}}
            <div style="margin-top:18px"><strong style="font-size:13px">WhatsApps públicos cadastrados para esta empresa</strong></div>
            @include('contacts.whatsapp-public-v13-channels')
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

    {{-- EC_WA_GENERAL_SECTION_REMOVED_V15: pesquisa geral retirada apenas da interface. --}}
    <div class="wa11-footer">Um telefone divulgado em site público é um indício de uso do WhatsApp, mas não comprova que a conta continua ativa. “Tem WhatsApp” exige confirmação já existente por interação ou consulta; resultado inconclusivo não vira “Não possui”.</div>
</section>
