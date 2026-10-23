{{-- Figma v36: quatro visões com filtros reais e permissões preservadas. --}}
<nav class="lf36-tabs" aria-label="Visualizações da fila comercial">
    @if ($this->isCommercialManager())
        <button type="button" wire:click="clearFilters" wire:loading.attr="disabled"
                aria-pressed="{{ ! $dueActionOnly && $owner !== 'mine' && $crmSituation !== 'new' && ! $hubSpotOnly && $globalHubSpotStage === '' && $personalHubSpotStage === '' ? 'true' : 'false' }}"
                class="lf36-tab {{ ! $dueActionOnly && $owner !== 'mine' && $crmSituation !== 'new' && ! $hubSpotOnly && $globalHubSpotStage === '' && $personalHubSpotStage === '' ? 'is-active' : '' }}">
            Todos
        </button>
    @endif
    <button type="button" wire:click="applyV15TopCard('due')" wire:loading.attr="disabled"
            aria-pressed="{{ $dueActionOnly ? 'true' : 'false' }}"
            class="lf36-tab {{ $dueActionOnly ? 'is-active' : '' }}">
        Atrasados / hoje
    </button>
    <button type="button" wire:click="applyV15TopCard('mine')" wire:loading.attr="disabled"
            aria-pressed="{{ $owner === 'mine' ? 'true' : 'false' }}"
            class="lf36-tab {{ $owner === 'mine' ? 'is-active' : '' }}">
        Minha fila
    </button>
    @if ($this->isCommercialManager())
        <button type="button" wire:click="applyV15TopCard('new')" wire:loading.attr="disabled"
                aria-pressed="{{ $crmSituation === 'new' ? 'true' : 'false' }}"
                class="lf36-tab {{ $crmSituation === 'new' ? 'is-active' : '' }}">
            Novos para prospectar
        </button>
    @endif
</nav>
