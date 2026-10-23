{{-- Dashboard v3: indicadores e explorador da base. --}}
<div class="ec-overview ec-strategic-v4 ef29-activated" x-data="{ dashboardTab: 'commercial', attentionExpanded: false }"
     x-on:overview-explorer-opened.window="dashboardTab = 'base'; $nextTick(() => { const panel = $el.querySelector('[data-overview-explorer]'); if (panel) { panel.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); panel.focus({ preventScroll: true }); } })"
     x-on:overview-explorer-closed.window="$nextTick(() => { const button = $el.querySelector('[data-dataset=&quot;' + $event.detail.dataset + '&quot;]'); if (button) button.focus(); })">
    @php
        $s = $this->summary;
        $manager = $this->canExplore;
        $number = static fn ($value) => number_format((int) $value, 0, ',', '.');
        $percentage = static fn ($value) => $s['establishments'] > 0 ? round($value / $s['establishments'] * 100, 1) : 0;
        $cards = [
            ['key' => 'companies', 'label' => 'Empresas', 'icon' => 'company', 'note' => 'CNPJs raiz cadastrados', 'action' => 'Ver empresas', 'tone' => 'blue'],
            ['key' => 'establishments', 'label' => 'Estabelecimentos', 'icon' => 'establishment', 'note' => 'Matrizes e filiais na base', 'action' => 'Ver estabelecimentos', 'tone' => 'cyan'],
            ['key' => 'active', 'label' => 'Estabelecimentos ativos', 'icon' => 'check', 'note' => number_format($percentage($s['active']), 1, ',', '.').'% dos estabelecimentos', 'action' => 'Ver somente ativos', 'tone' => 'green'],
            ['key' => 'cnaes', 'label' => 'CNAEs vinculados', 'icon' => 'activity', 'note' => 'Atividades presentes na base', 'action' => 'Explorar atividades', 'tone' => 'purple'],
        ];
    @endphp



    <section id="ec-dash-panel-commercial"
             class="ec-dash-pane"
             role="region"
             aria-label="Dashboard estratégico"
             x-show="dashboardTab === 'commercial'"
             x-cloak>

        @include('livewire.dashboard-overview.strategic', [
            'manager' => $manager,
            'commercial' => $manager ? $this->commercialSummary : null,
            'strategic' => $manager ? $this->strategicMetrics : null,
            's' => $s,
        ])

    </section>

    <section id="ec-dash-panel-base"
             class="ec-dash-pane"
             role="region"
             aria-label="Base empresarial"
             x-show="dashboardTab === 'base'"
             x-cloak>

        @include('livewire.dashboard-overview.base-figma-v32', ['s' => $s, 'manager' => $manager, 'number' => $number])

    </section>

</div>
