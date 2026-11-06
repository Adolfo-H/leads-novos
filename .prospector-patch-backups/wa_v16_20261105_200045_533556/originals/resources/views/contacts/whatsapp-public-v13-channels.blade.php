{{-- EC_WA_PUBLIC_CHANNELS_V13: canal empresarial, não um contato pessoal fictício. --}}
@include('contacts.whatsapp-public-v13-styles')
@php
    $wa13Channels = app(\App\Services\Contacts\WhatsappPublicCompanyChannels::class)
        ->forCompany((int) $wa11Company['company']->id);
    $wa13Deals = app(\App\Services\Contacts\WhatsappPublicCompanyChannels::class)
        ->companyDeals((int) $wa11Company['company']->id);
@endphp
<div class="wa13-explain" data-test="wa13-company-channel-registry">
    <span><strong>Canais públicos da empresa cadastrados</strong>
    Todos os links descobertos são armazenados com domínio, empresa, fonte e data.
    Não são criadas pessoas ou negócios fictícios.</span>
    <span class="wa13-count">{{ $wa13Channels->count() }} canal(is) exibido(s)</span>
</div>
@forelse ($wa13Channels as $channel)
    <div class="wa13-card" wire:key="wa13-channel-{{ $channel->id }}" data-phone-e164="{{ $channel->e164 }}">
        <div class="wa13-card-main">
            <strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display($channel->e164, $channel->e164) }}</strong>
            <small>
                Fonte: {{ $wa11Company['domain'] }} · Visto em {{ \Carbon\Carbon::parse($channel->last_seen_at)->timezone('America/Sao_Paulo')->format('d/m/Y') }}
            </small>
            <small>{{ $channel->global_phone_id ? 'Também encontrado no diretório do Prospector' : 'Ainda não é contato pessoal cadastrado' }}</small>
        </div>
        <div class="wa13-actions">
            @if ($channel->phone_id)
                <span class="wa13-badge">Já era telefone desta empresa</span>
            @else
                <span class="wa13-badge">Salvo como canal da empresa</span>
            @endif
            <a class="wa13-inline" href="{{ $channel->source_url }}" target="_blank" rel="noopener noreferrer">Ver fonte ↗</a>
            @if ($channel->phone_id || $channel->global_phone_id)
                <button class="wa11-link" type="button" wire:click="openPublicNumber({{ (int) ($channel->phone_id ?: $channel->global_phone_id) }})" x-on:click="$dispatch('wa12-show-numbers')">Ver telefone no diretório ›</button>
            @endif
        </div>
    </div>
@empty
    <div class="wa11-empty">Nenhum canal público foi associado a esta empresa até agora. Pesquise o site e confira as fontes encontradas.</div>
@endforelse
<div class="wa13-deals" data-test="wa13-associated-deals">
    <h4>Negócios já associados à empresa no HubSpot</h4>
    <p class="wa13-note">Exibição para referência comercial. O número divulgado não é associado automaticamente a um negócio específico.</p>
    @forelse ($wa13Deals as $deal)
        <div class="wa13-deal"><strong>{{ $deal->name ?: 'Negócio '. $deal->hubspot_id }}</strong><span>{{ $deal->pipeline_label ?: 'Pipeline' }} · {{ $deal->stage_label ?: 'Sem etapa' }}</span></div>
    @empty
        <p class="wa13-note">Nenhum negócio explicitamente vinculado a este registro de empresa.</p>
    @endforelse
</div>

{{-- EC_WA_PUBLIC_DIRECTORY_V14_EXTERNAL: fontes ampliadas opcionais, com revisão. --}}
@include('contacts.whatsapp-external-discovery-v14')
