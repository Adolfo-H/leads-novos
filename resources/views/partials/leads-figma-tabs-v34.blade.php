{{-- Pacote 34 / Figma L01-L03: filtros reais, sem dados fictícios. --}}
<nav class="lf34-tabs" aria-label="Visualizações da fila de leads">
    <button type="button" wire:click="clearFilters" wire:loading.attr="disabled"
        aria-pressed="{{ ! $dueActionOnly && $owner !== 'mine' && $crmSituation !== 'new' && ! $hubSpotOnly ? 'true' : 'false' }}"
        class="lf34-tab {{ ! $dueActionOnly && $owner !== 'mine' && $crmSituation !== 'new' && ! $hubSpotOnly ? 'is-active' : '' }}">
        Todos
    </button>
    <button type="button" wire:click="applyV15TopCard('due')" wire:loading.attr="disabled"
        aria-pressed="{{ $dueActionOnly ? 'true' : 'false' }}"
        class="lf34-tab {{ $dueActionOnly ? 'is-active' : '' }}">
        Atrasados / hoje
    </button>
    <button type="button" wire:click="applyV15TopCard('mine')" wire:loading.attr="disabled"
        aria-pressed="{{ $owner === 'mine' ? 'true' : 'false' }}"
        class="lf34-tab {{ $owner === 'mine' ? 'is-active' : '' }}">
        Minha fila
    </button>
    <button type="button" wire:click="applyV15TopCard('new')" wire:loading.attr="disabled"
        aria-pressed="{{ $crmSituation === 'new' ? 'true' : 'false' }}"
        class="lf34-tab {{ $crmSituation === 'new' ? 'is-active' : '' }}">
        Novos para prospectar
    </button>
</nav>
