{{-- EC_WA_PUBLIC_COMPANY_V11: evidência dos números já cadastrados; escolha de empresa opcional. --}}
@include('contacts.whatsapp-public-v11-styles')
@include('contacts.whatsapp-business-v85-styles')
{{-- EC_WA_ALL_BUSINESSES_V85 --}}
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
            <div><strong>Pesquisar WhatsApp de uma empresa específica</strong><small>Pesquise qualquer empresa ou negócio do HubSpot, além das empresas do Prospector/Receita, mesmo sem site cadastrado. Ao selecionar, veja os telefones associados pelos vínculos reais.</small></div>
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
            {{-- EC_WA_PUBLIC_MULTI_SOURCE_V87: coleta web por nome e domínio, sem confirmar telefones. --}}
            @include('contacts.whatsapp-company-web-v87', [
                'wa87TargetKey' => 'hubspot:'.(int) $wa11Company['company']->id,
                'wa87KnownDomain' => $wa11Company['domain'],
            ])
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
        @elseif($this->businessSelectedV85)
            @include('contacts.whatsapp-business-detail-v85')
        @else
            <div class="wa11-search">
                <label>Buscar empresa por nome ou domínio
                    <input type="search" wire:model.live.debounce.400ms="publicCompanyQuery" placeholder="Ex.: Cooperativa, Comércio, empresa.com.br..." autocomplete="off" maxlength="100">
                </label>
            </div>
            @if(mb_strlen(trim($publicCompanyQuery)) >= 3)
                <div class="wa11-options" aria-label="Empresas e negócios encontrados nas bases">
                    @forelse($this->businessCandidatesV85 as $candidate)
                        <button type="button" class="wa11-company-button wa85-business-option" wire:key="wa85-candidate-{{ str_replace(':', '-', $candidate->key) }}" wire:click="selectBusinessV85('{{ $candidate->key }}')">
                            <strong>{{ $candidate->name }}</strong>
                            <small><span class="wa85-source">{{ $candidate->source }}</span> · {{ $candidate->details }}</small>
                            <small class="wa85-website">{{ $candidate->domain ? 'Site cadastrado: '.$candidate->domain : 'Sem site cadastrado — disponível para consultar vínculos e telefones' }}</small>
                        </button>
                    @empty
                        <p class="wa11-empty">Nenhuma empresa ou negócio correspondeu à pesquisa no espelho local. Tente usar somente o nome principal (ex.: SAAGROS), o ID do negócio ou a raiz do CNPJ. Confira também se a sincronização do HubSpot terminou.</p>
                    @endforelse
                </div>
            @else
                <p class="wa11-note">Digite pelo menos 3 caracteres para consultar empresas e negócios de todas as fontes. Nenhuma pesquisa externa é executada apenas por digitar.</p>
            @endif
        @endif
    </div>

    @if(session('wa_public_success'))<div class="wa11-message" role="status">{{ session('wa_public_success') }}</div>@endif
    @error('public_site')<div class="wa11-message error" role="alert">{{ $message }}</div>@enderror

    {{-- EC_WA_GENERAL_SECTION_REMOVED_V15: pesquisa geral retirada apenas da interface. --}}
    <div class="wa11-footer">Um telefone divulgado em site público é um indício de uso do WhatsApp, mas não comprova que a conta continua ativa. “Tem WhatsApp” exige confirmação já existente por interação ou consulta; resultado inconclusivo não vira “Não possui”.</div>
</section>
