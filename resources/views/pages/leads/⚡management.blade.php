<?php

use App\Models\User;
use App\Services\CommercialManagementMetricsService;
use App\Services\CommercialRoleService;
use App\Services\LeadAutoDistributionService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * @var array<int, int|string>
     */
    public array $distributionSellerIds = [];

    public int $distributionLimit = 50;

    public string $distributionMessage = '';

    public string $distributionError = '';

    public string $roleMessage = '';

    public string $roleError = '';

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function commercialUsers(): Collection
    {
        return User::query()
            ->whereNotNull(
                'email_verified_at'
            )
            ->orderBy(
                'name'
            )
            ->get([
                'id',
                'name',
                'email',
                'commercial_role',
            ]);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function distributionUsers(): Collection
    {
        return User::query()
            ->whereNotNull(
                'email_verified_at'
            )
            ->orderBy(
                'name'
            )
            ->get([
                'id',
                'name',
                'email',
            ]);
    }

    #[Computed]
    public function distributionCandidatesCount(): int
    {
        return app(
            LeadAutoDistributionService::class
        )->candidatesCount();
    }

    public function selectAllDistributionSellers(): void
    {
        $this->distributionSellerIds =
            $this
                ->distributionUsers
                ->pluck(
                    'id'
                )
                ->map(
                    static fn ($id): int => (int) $id
                )
                ->values()
                ->all();
    }

    public function clearDistributionSellers(): void
    {
        $this->distributionSellerIds = [];
    }

    public function autoDistribute(
        LeadAutoDistributionService $service,
    ): void {
        $this->distributionMessage = '';
        $this->distributionError = '';

        try {
            $result =
                $service->distribute(
                    userIds: $this
                        ->distributionSellerIds,

                    limit: $this
                        ->distributionLimit,
                );

            $this->distributionMessage =
                $result['distributed']
                .' lead(s) distribuído(s). '
                .$result['remaining']
                .' novo(s) lead(s) continuam sem responsável.';
        } catch (DomainException $exception) {
            $this->distributionError =
                $exception->getMessage();
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->distributionError =
                'Não foi possível concluir a distribuição automática.';
        }
    }

    public function updateCommercialRole(
        int $userId,
        string $role,
        CommercialRoleService $service,
    ): void {
        $this->roleMessage = '';
        $this->roleError = '';

        $actor =
            auth()->user();

        abort_unless(
            $actor instanceof User,
            403
        );

        $target =
            User::query()
                ->whereNotNull(
                    'email_verified_at'
                )
                ->findOrFail(
                    $userId
                );

        try {
            $updated =
                $service->changeRole(
                    actor: $actor,

                    target: $target,

                    role: $role,
                );

            $this->roleMessage =
                $updated->name
                .' agora é '
                .$updated
                    ->commercialRoleLabel()
                .'.';
        } catch (DomainException $exception) {
            $this->roleError =
                $exception->getMessage();
        } catch (Throwable $exception) {
            report(
                $exception
            );

            $this->roleError =
                'Não foi possível alterar o perfil comercial.';
        }
    }

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


    {{-- PERFIS COMERCIAIS --}}
    <section
        class="
            mb-6 rounded-2xl
            border border-white/[0.06]
            bg-white/[0.025]
            p-5
        "
    >
        <div>
            <div class="ec-page-kicker">
                Acessos comerciais
            </div>

            <div
                class="
                    mt-1 text-base
                    font-semibold
                    text-[#eef1ff]
                "
            >
                Gestores e vendedores
            </div>

            <p
                class="
                    mt-1 text-xs
                    leading-5 text-[#7f89aa]
                "
            >
                Gestores visualizam e distribuem
                toda a operação. Vendedores ficam
                restritos à própria carteira.
            </p>
        </div>


        @if ($roleMessage !== '')
            <div
                class="
                    mt-4 rounded-xl
                    border border-emerald-300/20
                    bg-emerald-300/[0.06]
                    px-4 py-3
                    text-sm text-emerald-300
                "
            >
                {{ $roleMessage }}
            </div>
        @endif


        @if ($roleError !== '')
            <div
                class="
                    mt-4 rounded-xl
                    border border-red-300/20
                    bg-red-300/[0.06]
                    px-4 py-3
                    text-sm text-red-300
                "
            >
                {{ $roleError }}
            </div>
        @endif


        <div
            class="
                mt-4 grid gap-2
                lg:grid-cols-2
            "
        >
            @foreach (
                $this->commercialUsers
                as $commercialUser
            )

                <div
                    class="
                        flex flex-col gap-3
                        rounded-xl
                        border border-white/[0.06]
                        bg-white/[0.02]
                        px-4 py-3
                        sm:flex-row
                        sm:items-center
                        sm:justify-between
                    "
                >
                    <div class="min-w-0">
                        <div
                            class="
                                truncate text-sm
                                font-semibold
                                text-[#eef1ff]
                            "
                        >
                            {{ $commercialUser->name }}
                        </div>

                        <div
                            class="
                                mt-0.5 truncate
                                text-[10px]
                                text-[#707a9d]
                            "
                        >
                            {{ $commercialUser->email }}
                        </div>
                    </div>

                    <select
                        wire:change="
                            updateCommercialRole(
                                {{ $commercialUser->id }},
                                $event.target.value
                            )
                        "
                        class="
                            rounded-lg border
                            border-white/[0.08]
                            bg-[#151a36]
                            px-3 py-2
                            text-xs text-[#d9ddef]
                        "
                    >
                        <option
                            value="manager"
                            @selected(
                                $commercialUser
                                    ->commercial_role
                                === 'manager'
                            )
                        >
                            Gestor
                        </option>

                        <option
                            value="seller"
                            @selected(
                                $commercialUser
                                    ->commercial_role
                                === 'seller'
                            )
                        >
                            Vendedor
                        </option>
                    </select>
                </div>

            @endforeach
        </div>
    </section>


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


    {{-- DISTRIBUIÇÃO AUTOMÁTICA --}}
    <section
        class="
            mt-6 rounded-2xl
            border border-white/[0.06]
            bg-white/[0.025]
            p-5
        "
    >
        <div
            class="
                flex flex-col gap-4
                xl:flex-row
                xl:items-start
                xl:justify-between
            "
        >
            <div>
                <div class="ec-page-kicker">
                    Distribuição automática
                </div>

                <div
                    class="
                        mt-1 text-base
                        font-semibold
                        text-[#eef1ff]
                    "
                >
                    Balancear novos leads
                </div>

                <p
                    class="
                        mt-1 max-w-2xl
                        text-xs leading-5
                        text-[#7f89aa]
                    "
                >
                    Distribui somente leads novos
                    e sem responsável. A carga atual
                    de cada carteira é considerada,
                    e os leads de maior score entram
                    primeiro.
                </p>
            </div>

            <div
                class="
                    rounded-xl
                    border border-amber-300/15
                    bg-amber-300/[0.05]
                    px-4 py-3
                    text-right
                "
            >
                <div
                    class="
                        text-[10px]
                        font-semibold uppercase
                        tracking-wider
                        text-[#7f89aa]
                    "
                >
                    Novos disponíveis
                </div>

                <div
                    class="
                        mt-1 text-2xl
                        font-bold
                        text-amber-300
                    "
                >
                    {{
                        $this
                            ->distributionCandidatesCount
                    }}
                </div>
            </div>
        </div>


        @if (
            $distributionMessage
            !== ''
        )

            <div
                class="
                    mt-4 rounded-xl
                    border border-emerald-300/20
                    bg-emerald-300/[0.06]
                    px-4 py-3
                    text-sm text-emerald-300
                "
            >
                {{ $distributionMessage }}
            </div>

        @endif


        @if (
            $distributionError
            !== ''
        )

            <div
                class="
                    mt-4 rounded-xl
                    border border-red-300/20
                    bg-red-300/[0.06]
                    px-4 py-3
                    text-sm text-red-300
                "
            >
                {{ $distributionError }}
            </div>

        @endif


        <div
            class="
                mt-5 flex flex-wrap
                items-center justify-between
                gap-3
            "
        >
            <div
                class="
                    text-[10px]
                    font-semibold uppercase
                    tracking-wider
                    text-[#697394]
                "
            >
                Quem participa desta distribuição
            </div>

            <div
                class="
                    flex items-center
                    gap-3 text-xs
                "
            >
                <button
                    type="button"
                    wire:click="selectAllDistributionSellers"
                    class="
                        font-semibold
                        text-cyan-300
                        hover:text-cyan-200
                    "
                >
                    Selecionar todos
                </button>

                <button
                    type="button"
                    wire:click="clearDistributionSellers"
                    class="
                        font-semibold
                        text-[#8d96b4]
                        hover:text-white
                    "
                >
                    Limpar
                </button>
            </div>
        </div>


        <div
            class="
                mt-3 grid gap-2
                md:grid-cols-2
                xl:grid-cols-3
            "
        >
            @foreach (
                $this->distributionUsers
                as $distributionUser
            )

                <label
                    class="
                        flex cursor-pointer
                        items-center gap-3
                        rounded-xl border
                        border-white/[0.06]
                        bg-white/[0.02]
                        px-4 py-3
                        transition
                        hover:bg-white/[0.04]
                    "
                >
                    <input
                        type="checkbox"
                        value="{{ $distributionUser->id }}"
                        wire:model="distributionSellerIds"
                        class="
                            rounded
                            border-white/20
                            bg-white/[0.04]
                            text-cyan-300
                        "
                    >

                    <div class="min-w-0">
                        <div
                            class="
                                truncate text-sm
                                font-semibold
                                text-[#e8ebf7]
                            "
                        >
                            {{ $distributionUser->name }}
                        </div>

                        <div
                            class="
                                truncate text-[10px]
                                text-[#707a9d]
                            "
                        >
                            {{ $distributionUser->email }}
                        </div>
                    </div>
                </label>

            @endforeach
        </div>


        <div
            class="
                mt-5 flex flex-col
                gap-3
                sm:flex-row
                sm:items-end
                sm:justify-between
            "
        >
            <div>
                <label
                    class="
                        text-[10px]
                        font-semibold uppercase
                        tracking-wider
                        text-[#697394]
                    "
                    for="distribution-limit"
                >
                    Quantidade nesta rodada
                </label>

                <input
                    id="distribution-limit"
                    type="number"
                    min="1"
                    max="500"
                    wire:model="distributionLimit"
                    class="
                        mt-1 block w-32
                        rounded-xl border
                        border-white/[0.08]
                        bg-[#151a36]
                        px-3 py-2.5
                        text-sm text-[#eef1ff]
                        outline-none
                    "
                >

                <div
                    class="
                        mt-1 text-[10px]
                        text-[#687394]
                    "
                >
                    Máximo de 500 por execução.
                </div>
            </div>


            <button
                type="button"
                wire:click="autoDistribute"
                wire:confirm="Distribuir automaticamente os novos leads entre os responsáveis selecionados?"
                wire:loading.attr="disabled"
                wire:target="autoDistribute"
                @disabled(
                    $distributionSellerIds
                    === []
                    || $this->distributionCandidatesCount
                        === 0
                )
                class="
                    rounded-xl
                    border border-cyan-300/20
                    bg-cyan-300/[0.08]
                    px-5 py-3
                    text-sm font-semibold
                    text-cyan-300
                    transition
                    hover:bg-cyan-300/[0.13]
                    disabled:cursor-not-allowed
                    disabled:opacity-40
                "
            >
                <span
                    wire:loading.remove
                    wire:target="autoDistribute"
                >
                    Distribuir automaticamente
                </span>

                <span
                    wire:loading
                    wire:target="autoDistribute"
                >
                    Distribuindo...
                </span>
            </button>
        </div>


        <div
            class="
                mt-4 rounded-lg
                bg-white/[0.02]
                px-3 py-2
                text-[10px]
                leading-5
                text-[#707a9d]
            "
        >
            A distribuição não altera etapa,
            status ou negócio no HubSpot.
            Apenas define o responsável interno
            pelo lead.
        </div>
    </section>


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
