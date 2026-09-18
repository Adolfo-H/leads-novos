<?php

use App\Services\CommercialManagementMetricsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * @return array{
     *     summary: array<string, int>,
     *     sellers: list<array<string, int|string>>
     * }
     */
    #[Computed]
    public function dashboard(): array
    {
        return app(
            CommercialManagementMetricsService::class
        )->dashboard();
    }
};
?>

<div
    class="ec-page-shell"
    wire:poll.30s="$refresh"
>
    @php
        $dashboard =
            $this->dashboard;

        $summary =
            $dashboard['summary'];

        $sellers =
            $dashboard['sellers'];
    @endphp


    <div
        class="
            ec-page-header
            flex flex-col gap-4
            lg:flex-row
            lg:items-end
            lg:justify-between
        "
    >
        <div>
            <div class="ec-page-kicker">
                Operação SDR
            </div>

            <h1 class="ec-page-title">
                Gestão Comercial
            </h1>

            <p class="ec-page-description">
                Visão da carga, carteira e pendências
                de cada responsável comercial.
            </p>
        </div>

        <a
            href="{{ route('leads.index') }}"
            wire:navigate
            class="ec-button-secondary"
        >
            Abrir fila de leads →
        </a>
    </div>


    <div
        class="
            grid gap-3
            sm:grid-cols-2
            xl:grid-cols-6
        "
    >
        <div class="ec-intelligence-card">
            <div class="ec-intelligence-label">
                Carteira ativa
            </div>

            <div class="ec-score-value">
                {{ $summary['active_total'] }}
            </div>

            <div class="ec-intelligence-caption">
                Leads em operação
            </div>
        </div>


        <div class="ec-intelligence-card">
            <div class="ec-intelligence-label">
                Com responsável
            </div>

            <div class="ec-score-value">
                {{ $summary['assigned_total'] }}
            </div>

            <div class="ec-intelligence-caption">
                Leads distribuídos
            </div>
        </div>


        <a
            href="{{
                route(
                    'leads.index',
                    [
                        'owner' =>
                            'unassigned',
                    ]
                )
            }}"
            wire:navigate
            class="
                ec-intelligence-card
                transition
                hover:border-amber-300/20
            "
        >
            <div class="ec-intelligence-label">
                Sem responsável
            </div>

            <div
                class="
                    mt-2 text-2xl
                    font-bold text-amber-300
                "
            >
                {{ $summary['unassigned_total'] }}
            </div>

            <div class="ec-intelligence-caption">
                Aguardando distribuição
            </div>
        </a>


        <div class="ec-intelligence-card">
            <div class="ec-intelligence-label">
                Atrasados
            </div>

            <div
                class="
                    mt-2 text-2xl
                    font-bold text-red-300
                "
            >
                {{ $summary['overdue_total'] }}
            </div>

            <div class="ec-intelligence-caption">
                Follow-ups vencidos
            </div>
        </div>


        <div class="ec-intelligence-card">
            <div class="ec-intelligence-label">
                Para hoje
            </div>

            <div
                class="
                    mt-2 text-2xl
                    font-bold text-amber-300
                "
            >
                {{ $summary['due_today_total'] }}
            </div>

            <div class="ec-intelligence-caption">
                Follow-ups do dia
            </div>
        </div>


        <div class="ec-intelligence-card">
            <div class="ec-intelligence-label">
                Em contato
            </div>

            <div
                class="
                    mt-2 text-2xl
                    font-bold text-cyan-300
                "
            >
                {{ $summary['contacting_total'] }}
            </div>

            <div class="ec-intelligence-caption">
                Abordagens abertas
            </div>
        </div>
    </div>


    <section class="mt-6">

        <div
            class="
                mb-3 flex flex-wrap
                items-end justify-between
                gap-3
            "
        >
            <div>
                <div class="ec-page-kicker">
                    Equipe
                </div>

                <div
                    class="
                        mt-1 text-sm
                        font-semibold
                        text-[#eef1ff]
                    "
                >
                    Distribuição da carteira
                </div>
            </div>

            <div
                class="
                    text-xs
                    text-[#7f89aa]
                "
            >
                O painel atualiza automaticamente.
            </div>
        </div>


        <div
            class="
                overflow-x-auto
                rounded-2xl
                border border-white/[0.06]
                bg-white/[0.02]
            "
        >

            <table
                class="
                    min-w-[1050px]
                    w-full text-left
                "
            >
                <thead>
                    <tr
                        class="
                            border-b
                            border-white/[0.06]
                            text-[10px]
                            font-semibold
                            uppercase
                            tracking-wider
                            text-[#697394]
                        "
                    >
                        <th class="px-5 py-4">
                            Responsável
                        </th>

                        <th class="px-4 py-4">
                            Carteira
                        </th>

                        <th class="px-4 py-4">
                            Novos
                        </th>

                        <th class="px-4 py-4">
                            Em contato
                        </th>

                        <th class="px-4 py-4">
                            Aguardando
                        </th>

                        <th class="px-4 py-4">
                            Atrasados
                        </th>

                        <th class="px-4 py-4">
                            Hoje
                        </th>

                        <th class="px-4 py-4">
                            Futuras
                        </th>
                    </tr>
                </thead>

                <tbody>

                    @forelse (
                        $sellers
                        as $seller
                    )

                        <tr
                            class="
                                border-b
                                border-white/[0.05]
                                last:border-b-0
                                hover:bg-white/[0.025]
                            "
                        >
                            <td class="px-5 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        block
                                        font-semibold
                                        text-[#eef1ff]
                                        hover:text-cyan-300
                                    "
                                >
                                    {{ $seller['name'] }}
                                </a>

                                <div
                                    class="
                                        mt-0.5 text-[10px]
                                        text-[#707a9d]
                                    "
                                >
                                    {{ $seller['email'] }}
                                </div>
                            </td>


                            <td class="px-4 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        font-bold
                                        text-[#eef1ff]
                                        hover:text-cyan-300
                                    "
                                >
                                    {{ $seller['active_total'] }}
                                </a>
                            </td>


                            <td class="px-4 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],

                                                'workStatus' =>
                                                    'new',
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        font-semibold
                                        text-[#aab2cc]
                                        hover:text-white
                                    "
                                >
                                    {{ $seller['new_total'] }}
                                </a>
                            </td>


                            <td class="px-4 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],

                                                'workStatus' =>
                                                    'contacting',
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        font-semibold
                                        text-cyan-300
                                        hover:text-cyan-200
                                    "
                                >
                                    {{ $seller['contacting_total'] }}
                                </a>
                            </td>


                            <td class="px-4 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],

                                                'workStatus' =>
                                                    'waiting',
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        font-semibold
                                        text-[#d9ddef]
                                        hover:text-white
                                    "
                                >
                                    {{ $seller['waiting_total'] }}
                                </a>
                            </td>


                            <td class="px-4 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],

                                                'workStatus' =>
                                                    'waiting',

                                                'followUp' =>
                                                    'overdue',
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        font-bold
                                        text-red-300
                                        hover:text-red-200
                                    "
                                >
                                    {{ $seller['overdue_total'] }}
                                </a>
                            </td>


                            <td class="px-4 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],

                                                'workStatus' =>
                                                    'waiting',

                                                'followUp' =>
                                                    'today',
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        font-bold
                                        text-amber-300
                                        hover:text-amber-200
                                    "
                                >
                                    {{ $seller['due_today_total'] }}
                                </a>
                            </td>


                            <td class="px-4 py-4">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' =>
                                                    (string) $seller['user_id'],

                                                'workStatus' =>
                                                    'future',
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                    class="
                                        font-semibold
                                        text-violet-300
                                        hover:text-violet-200
                                    "
                                >
                                    {{ $seller['future_total'] }}
                                </a>
                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td
                                colspan="8"
                                class="
                                    px-6 py-12
                                    text-center
                                    text-sm
                                    text-[#7f89aa]
                                "
                            >
                                Nenhuma carteira atribuída.
                            </td>
                        </tr>

                    @endforelse

                </tbody>
            </table>

        </div>
    </section>


    <div
        class="
            mt-5 rounded-xl
            border border-white/[0.06]
            bg-white/[0.02]
            px-4 py-3
            text-xs text-[#7f89aa]
        "
    >
        Oportunidades futuras ativas:
        <strong class="text-violet-300">
            {{ $summary['future_total'] }}
        </strong>

        ·

        Aguardando retorno:
        <strong class="text-[#d9ddef]">
            {{ $summary['waiting_total'] }}
        </strong>

        ·

        Novos:
        <strong class="text-cyan-300">
            {{ $summary['new_total'] }}
        </strong>
    </div>

</div>
