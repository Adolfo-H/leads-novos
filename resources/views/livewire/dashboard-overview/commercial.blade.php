@php
    $fmt = static fn ($value): string =>
        number_format((int) $value, 0, ',', '.');
@endphp

<section class="ec-c2" aria-label="Painel comercial">

    <header class="ec-c2-top">

        <div>
            <span class="ec-c2-eyebrow">
                EXPORTCONTROL / PROSPECTOR
            </span>

            <h1>Visão geral comercial</h1>

            <p>
                Leads, oportunidades e prioridades em um só lugar.
            </p>
        </div>

        <div class="ec-c2-actions">

            <a href="{{ route('leads.index') }}"
               wire:navigate
               class="ec-c2-primary">
                Abrir leads
                <span aria-hidden="true">↗</span>
            </a>

            @if ($manager)
                <a href="{{ route('leads.management') }}"
                   wire:navigate
                   class="ec-c2-secondary">
                    Gestão comercial
                </a>
            @endif

        </div>

    </header>

    @if ($commercial !== null)

        @php
            $metrics = [
                [
                    'label' => 'Leads ativos',
                    'value' => $commercial['active_total'],
                    'note' => 'Na operação comercial',
                ],
                [
                    'label' => 'Em contato',
                    'value' => $commercial['contacting_total'],
                    'note' => 'Acompanhamento iniciado',
                ],
                [
                    'label' => 'Aguardando retorno',
                    'value' => $commercial['waiting_total'],
                    'note' => 'Com retorno pendente',
                ],
                [
                    'label' => 'Negócios HubSpot',
                    'value' => $commercial['opportunities_total'],
                    'note' => 'Negócios vinculados',
                ],
            ];

            $stages = [
                [
                    'key' => 'new',
                    'label' => 'Novos',
                    'value' => $commercial['new_total'],
                ],
                [
                    'key' => 'contacting',
                    'label' => 'Em contato',
                    'value' => $commercial['contacting_total'],
                ],
                [
                    'key' => 'waiting',
                    'label' => 'Aguardando',
                    'value' => $commercial['waiting_total'],
                ],
                [
                    'key' => 'future',
                    'label' => 'Oportunidade futura',
                    'value' => $commercial['future_total'],
                ],
            ];

            $stageTotal = max(
                1,
                array_sum(array_column($stages, 'value'))
            );
        @endphp

        {{-- Indicadores compactos --}}
        <div class="ec-c2-kpis">

            @foreach ($metrics as $metric)
                <div class="ec-c2-kpi">
                    <span class="ec-c2-kpi-label">
                        {{ $metric['label'] }}
                    </span>

                    <strong class="ec-c2-kpi-number">
                        {{ $fmt($metric['value']) }}
                    </strong>

                    <span class="ec-c2-kpi-note">
                        {{ $metric['note'] }}
                    </span>
                </div>
            @endforeach

        </div>

        {{-- Distribuição comercial --}}
        <section class="ec-c2-panel"
                 aria-label="Distribuição comercial">

            <div class="ec-c2-panel-header">
                <div>
                    <h2>Situação dos leads</h2>
                    <p>Distribuição por acompanhamento comercial</p>
                </div>

                <a href="{{ route('leads.index') }}"
                   wire:navigate
                   class="ec-c2-link">
                    Ver leads ↗
                </a>
            </div>

            <div class="ec-c2-stack"
                 role="img"
                 aria-label="Distribuição dos leads por situação">

                @foreach ($stages as $stage)
                    @php
                        $width = round(
                            100 * $stage['value'] / $stageTotal,
                            2
                        );
                    @endphp

                    <span
                        class="ec-c2-stack-segment ec-c2-stack--{{ $stage['key'] }}"
                        style="width: {{ $width }}%"
                        title="{{ $stage['label'] }}: {{ $fmt($stage['value']) }}">
                    </span>
                @endforeach

            </div>

            <div class="ec-c2-legend">

                @foreach ($stages as $stage)
                    <div class="ec-c2-legend-item">
                        <span class="ec-c2-dot ec-c2-dot--{{ $stage['key'] }}"></span>

                        <span>
                            {{ $stage['label'] }}
                        </span>

                        <strong>
                            {{ $fmt($stage['value']) }}
                        </strong>
                    </div>
                @endforeach

            </div>

        </section>

        {{-- Pendências recolhidas por padrão --}}
        <section class="ec-c2-attention">

            <button
                type="button"
                class="ec-c2-attention-toggle"
                x-on:click="attentionExpanded = !attentionExpanded"
                x-bind:aria-expanded="attentionExpanded ? 'true' : 'false'"
                aria-controls="ec-c2-attention-details">

                <span class="ec-c2-attention-heading">
                    <strong>Atenção operacional</strong>
                    <small>Retornos, atrasos e contatos parados</small>
                </span>

                <span class="ec-c2-attention-count">
                    {{ $fmt($commercial['attention_total']) }}
                    pendências
                </span>

                <span
                    class="ec-c2-chevron"
                    x-bind:class="{ 'is-open': attentionExpanded }"
                    aria-hidden="true">
                    ⌄
                </span>

            </button>

            <div id="ec-c2-attention-details"
                 class="ec-c2-attention-details"
                 x-show="attentionExpanded"
                 x-transition.opacity.duration.150ms
                 x-cloak>

                <a href="{{ route('leads.index', [
                    'workStatus' => 'waiting',
                    'followUp' => 'overdue',
                ]) }}" wire:navigate>
                    <span>Follow-ups atrasados</span>
                    <strong>{{ $fmt($commercial['overdue_total']) }}</strong>
                </a>

                <a href="{{ route('leads.index', [
                    'workStatus' => 'waiting',
                    'followUp' => 'today',
                ]) }}" wire:navigate>
                    <span>Retornos hoje</span>
                    <strong>{{ $fmt($commercial['due_today_total']) }}</strong>
                </a>

                <a href="{{ route('leads.index', [
                    'workStatus' => 'waiting',
                    'followUp' => 'unscheduled',
                ]) }}" wire:navigate>
                    <span>Aguardando sem prazo</span>
                    <strong>{{ $fmt($commercial['unscheduled_total']) }}</strong>
                </a>

                <a href="{{ route('leads.management') }}"
                   wire:navigate>
                    <span>Contatos parados</span>
                    <strong>{{ $fmt($commercial['stale_total']) }}</strong>
                </a>

            </div>

        </section>

    @else

        <section class="ec-c2-seller">

            <h2>Minha fila de leads</h2>

            <p>
                Consulte sua carteira e acompanhe
                suas atividades comerciais.
            </p>

            <a href="{{ route('leads.index') }}" wire:navigate>
                Abrir minha fila →
            </a>

        </section>

    @endif

</section>
