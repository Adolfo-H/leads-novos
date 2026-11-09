{{-- V85: visão segura de empresa/negócio sem exigir domínio público. --}}
@php $wa85Business = $this->businessSelectedV85; @endphp
@if($wa85Business)
    <div class="wa11-company-label wa85-business-label">
        <div>
            <strong>{{ $wa85Business['name'] }}</strong>
            <small>{{ $wa85Business['source'] }}</small>
            @if($wa85Business['domain'])<small>Domínio cadastrado: {{ $wa85Business['domain'] }}</small>@endif
        </div>
        <button type="button" class="wa11-btn wa11-btn-outline" wire:click="clearPublicCompany">Trocar empresa ou negócio</button>
    </div>
    @include('contacts.whatsapp-business-group-v86')
    @if(count($wa85Business['linked_companies']) > 0)
        <div class="wa85-companies">
            <strong>{{ ($wa85Business['grouped'] ?? false) ? 'Cadastros encontrados no agrupamento de nomes' : 'Empresas efetivamente associadas no CRM' }}</strong>
            <p>{{ implode(' · ', $wa85Business['linked_companies']) }}</p>
            <small>As associações são mostradas como constam no HubSpot. O sistema não atribui CNPJ por semelhança de nome.</small>
        </div>
    @endif
    <div class="wa11-cards">
        <div><small>Telefones identificados nos vínculos</small><strong>{{ number_format($wa85Business['phone_total'], 0, ',', '.') }}</strong></div>
        <div><small>Com WhatsApp confirmado</small><strong>{{ number_format($wa85Business['confirmed_total'], 0, ',', '.') }}</strong></div>
        <div><small>Pesquisa de site</small><strong class="wa85-site-status">{{ $wa85Business['site_hubspot_company_id'] ? 'Disponível' : 'Sem domínio' }}</strong></div>
    </div>
    {{-- EC_WA_PUBLIC_MULTI_SOURCE_V87: empresa/negócio também pesquisável sem site. --}}
    @include('contacts.whatsapp-company-web-v87', [
        'wa87TargetKey' => (string) $wa85Business['key'],
        'wa87KnownDomain' => $wa85Business['domain'],
    ])
    @if($wa85Business['site_hubspot_company_id'])
        <div class="wa85-note-box">
            Há um domínio cadastrado para esta empresa associada. Você pode abrir a pesquisa pública do site, mantendo a identidade da Company do HubSpot.
            <button type="button" class="wa11-btn wa11-btn-outline" wire:click="selectPublicCompany({{ $wa85Business['site_hubspot_company_id'] }})">Ver pesquisa do site vinculado</button>
        </div>
    @else
        <p class="wa85-note-box">Empresa ou negócio localizado no CRM, mas sem domínio público seguro associado. Os telefones registrados aparecem abaixo. Nenhum site será inventado ou pesquisado por aproximação de nome.</p>
    @endif
    <div class="wa85-phones-title">Telefones vinculados pelos cadastros e associações reais</div>
    @forelse($wa85Business['phones'] as $wa85Phone)
        <div class="wa11-row" wire:key="wa85-business-phone-{{ $wa85Phone->id }}">
            <div class="wa11-row-main">
                <strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($wa85Phone->e164, (string) $wa85Phone->digits) }}</strong>
                <small>{{ match($wa85Phone->kind) { 'mobile' => 'Celular', 'landline' => 'Fixo', default => 'Revisar formato' } }} · {{ (int) $wa85Phone->contacts_count }} vínculo(s) no diretório</small>
                @if(!empty($wa85Phone->source_labels))
                    <small>Fonte(s): {{ implode(' · ', $wa85Phone->source_labels) }}</small>
                @endif
            </div>
            <div class="wa11-row-actions">
                <span class="wa11-status {{ $wa85Phone->status === 'yes' ? 'yes' : ($wa85Phone->status === 'no' ? 'other' : 'pending') }}">
                    {{ match($wa85Phone->status) { 'yes' => 'Tem WhatsApp', 'no' => 'Não possui', default => 'A verificar' } }}
                </span>
                <button type="button" class="wa11-link" wire:click="openBusinessNumberV85({{ $wa85Phone->id }})" x-on:click="$dispatch('wa12-show-numbers')">Abrir no diretório ›</button>
            </div>
        </div>
    @empty
        <p class="wa11-empty">Nenhum telefone foi encontrado nos contatos ou estabelecimentos efetivamente associados a este registro. Confira se os vínculos do HubSpot foram sincronizados; o cadastro existe, mas não podemos inferir telefones de outras empresas.</p>
    @endforelse
    @if($wa85Business['phone_total'] > $wa85Business['phones']->count())
        <p class="wa11-note">Mostrando os primeiros 100 telefones. Para consultar os demais, use o diretório de números e contatos.</p>
    @endif
@endif
