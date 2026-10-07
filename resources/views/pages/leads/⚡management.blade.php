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
    public function mount(): void
    {
        $this->assertCommercialManager();
    }

    private function assertCommercialManager(): User
    {
        $user =
            auth()->user();

        abort_unless(
            $user instanceof User
            && $user
                ->isCommercialManager(),
            403
        );

        return $user;
    }

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
        $this->assertCommercialManager();

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
        $this->assertCommercialManager();

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
        $this->assertCommercialManager();

        return app(
            LeadAutoDistributionService::class
        )->candidatesCount();
    }

    public function selectAllDistributionSellers(): void
    {
        $this->assertCommercialManager();

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
        $this->assertCommercialManager();

        $this->distributionSellerIds = [];
    }

    public function autoDistribute(
        LeadAutoDistributionService $service,
    ): void {
        $this->assertCommercialManager();

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
        $actor =
            $this->assertCommercialManager();

        $this->roleMessage = '';
        $this->roleError = '';

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
     * Prévia não persistente da distribuição.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function distributionPreview(): ?array
    {
        $this->assertCommercialManager();

        if (
            $this->distributionSellerIds
            === []
        ) {
            return null;
        }

        try {
            return app(
                LeadAutoDistributionService::class
            )->preview(
                userIds: $this->distributionSellerIds,

                limit: $this->distributionLimit,
            );

        } catch (DomainException) {
            /*
             * Enquanto o gestor edita os
             * controles, uma configuração
             * temporariamente inválida apenas
             * oculta a prévia.
             */
            return null;
        }
    }

    /**
     * @return list<array{
     *     company_id: int,
     *     company_name: string,
     *     owner_id: int|null,
     *     owner_name: string,
     *     score: int,
     *     reason: string,
     *     reason_key: string,
     *     detail: string,
     *     moment: string|null,
     *     filters: array<string, string>
     * }>
     */
    #[Computed]
    public function managementAttentionQueue(): array
    {
        $this->assertCommercialManager();

        return app(
            CommercialManagementMetricsService::class
        )->attentionQueue(
            limit: 20,
        );
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
        $this->assertCommercialManager();

        return app(
            CommercialManagementMetricsService::class
        )->dashboard();
    }
};
?>

<div class="ecgm-page" wire:poll.visible.30s="$refresh">

    <div
        class="ecgm-page-loading"
        wire:loading.delay
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <span
            class="ecgm-page-loading-dot"
            aria-hidden="true"
        ></span>

        Atualizando painel comercial...
    </div>


    @php
        $dashboard = $this->dashboard;
        $summary = $dashboard['summary'];
        $sellers = $dashboard['sellers'];
        $available = $this->distributionCandidatesCount;
        $participants = $this->distributionUsers;
        $commercialUsers = $this->commercialUsers;

        $attentionQueue =
            $this->managementAttentionQueue;

        $distributionPreview =
            $this->distributionPreview;


        $sellerLoads =
            array_map(
                static fn (
                    array $seller
                ): int => (int) $seller[
                    'active_total'
                ],
                $sellers
            );

        $maxSellerLoad =
            $sellerLoads === []
                ? 1
                : max(
                    1,
                    max(
                        $sellerLoads
                    )
                );

        $attentionSellers =
            array_slice(
                array_values(
                    array_filter(
                        $sellers,
                        static fn (
                            array $seller
                        ): bool => (int) $seller[
                            'attention_total'
                        ] > 0
                    )
                ),
                0,
                6
            );

        $number = static fn ($value): string =>
            number_format((int) $value, 0, ',', '.');

        $metrics = [
            [
                'active_total',
                'Carteira ativa',
                'Leads atualmente em trabalho',
                'neutral',
                null,
            ],
            [
                'opportunities_total',
                'Oportunidades ativas',
                'Com negócio HubSpot em andamento',
                'info',
                null,
            ],
            [
                'overdue_total',
                'Atrasados',
                'Retornos vencidos →',
                'danger',
                [
                    'workStatus' => 'waiting',
                    'followUp' => 'overdue',
                ],
            ],
            [
                'due_today_total',
                'Para hoje',
                'Retornos do dia →',
                'warning',
                [
                    'workStatus' => 'waiting',
                    'followUp' => 'today',
                ],
            ],
            [
                'attention_total',
                'Exigem atenção',
                'Atrasos, sem prazo e parados',
                'danger',
                null,
            ],
            [
                'unassigned_total',
                'Sem responsável',
                'Distribuir carteira →',
                'warning',
                [
                    'owner' => 'unassigned',
                ],
            ],
        ];

        $columns = [
            [
                'active_total',
                'Carteira',
                [],
            ],
            [
                'opportunities_total',
                'Oportunidades',
                [],
            ],
            [
                'new_total',
                'Novos',
                [
                    'workStatus' => 'new',
                ],
            ],
            [
                'overdue_total',
                'Atrasados',
                [
                    'workStatus' => 'waiting',
                    'followUp' => 'overdue',
                ],
            ],
            [
                'due_today_total',
                'Hoje',
                [
                    'workStatus' => 'waiting',
                    'followUp' => 'today',
                ],
            ],
            [
                'unscheduled_total',
                'Sem prazo',
                [
                    'workStatus' => 'waiting',
                    'followUp' => 'unscheduled',
                ],
            ],
            [
                'stale_total',
                'Parados',
                [
                    'workStatus' => 'contacting',
                ],
            ],
        ];
    @endphp

    <header class="ecgm-header">
        <div>
            <span class="ecgm-eyebrow">
                OPERAÇÃO COMERCIAL
            </span>

            <h1>Gestão Comercial</h1>

            <p>
                Acompanhe a equipe, priorize os retornos
                e distribua novos leads.
            </p>
        </div>

        <a
            href="{{ route('leads.index') }}"
            wire:navigate
            class="ecgm-button ecgm-primary"
        >
            Abrir fila de leads →
        </a>
    </header>

    <section
        class="ecgm-metrics"
        aria-label="Indicadores da operação"
    >
        @foreach ($metrics as [$key, $label, $caption, $tone, $filters])
            @if ($filters !== null)
                <a
                    class="ecgm-metric"
                    data-tone="{{ $tone }}"
                    href="{{ route('leads.index', $filters) }}"
                    wire:navigate
                >
                    <span>{{ $label }}</span>
                    <strong>{{ $number($summary[$key]) }}</strong>
                    <small>{{ $caption }}</small>
                </a>
            @else
                <div
                    class="ecgm-metric"
                    data-tone="{{ $tone }}"
                >
                    <span>{{ $label }}</span>
                    <strong>{{ $number($summary[$key]) }}</strong>
                    <small>{{ $caption }}</small>
                </div>
            @endif
        @endforeach
    </section>

    @include(
        'partials.commercial-attention-queue'
    )


    <section
        class="ecgm-panel ecgm-team-focus"
        aria-labelledby="ecgm-focus-title"
    >

        <header class="ecgm-panel-head">

            <div>

                <span class="ecgm-eyebrow">
                    ATENÇÃO DA EQUIPE
                </span>

                <h2 id="ecgm-focus-title">
                    Carga e pendências por vendedor
                </h2>

                <p>
                    Quem concentra mais trabalho e onde
                    existem retornos que precisam de ação.
                </p>

            </div>

            <span class="ecgm-focus-total">
                {{
                    $number(
                        $summary[
                            'attention_total'
                        ]
                    )
                }}
                item(ns) exigem atenção
            </span>

        </header>


        <div class="ecgm-team-focus-grid">

            @forelse (
                $sellers
                as $seller
            )

                @php
                    $loadPercent =
                        min(
                            100,
                            (int) round(
                                (
                                    (int) $seller[
                                        'active_total'
                                    ]
                                    / $maxSellerLoad
                                )
                                * 100
                            )
                        );
                @endphp

                <article
                    class="
                        ecgm-seller-card
                        {{
                            (int) $seller[
                                'attention_total'
                            ] > 0
                                ? 'has-attention'
                                : ''
                        }}
                    "
                    wire:key="
                        ecgm-focus-{{
                            $seller[
                                'user_id'
                            ]
                        }}
                    "
                >

                    <div class="ecgm-seller-card-head">

                        <div>

                            <strong>
                                {{ $seller['name'] }}
                            </strong>

                            <span>
                                {{
                                    $number(
                                        $seller[
                                            'active_total'
                                        ]
                                    )
                                }}
                                na carteira
                            </span>

                        </div>

                        @if (
                            (int) $seller[
                                'attention_total'
                            ] > 0
                        )

                            <span class="ecgm-attention-pill">
                                {{
                                    $number(
                                        $seller[
                                            'attention_total'
                                        ]
                                    )
                                }}
                                atenção
                            </span>

                        @else

                            <span
                                class="
                                    ecgm-attention-pill
                                    is-clear
                                "
                            >
                                Em dia
                            </span>

                        @endif

                    </div>


                    <div
                        class="ecgm-load-track"
                        title="
                            Carga relativa da carteira
                        "
                    >
                        <span
                            style="
                                width:
                                {{ $loadPercent }}%;
                            "
                        ></span>
                    </div>


                    <div class="ecgm-seller-signals">

                        <span
                            class="{{
                                (int) $seller[
                                    'overdue_total'
                                ] > 0
                                    ? 'is-danger'
                                    : ''
                            }}"
                        >
                            Atrasados
                            <strong>
                                {{
                                    $number(
                                        $seller[
                                            'overdue_total'
                                        ]
                                    )
                                }}
                            </strong>
                        </span>

                        <span>
                            Hoje
                            <strong>
                                {{
                                    $number(
                                        $seller[
                                            'due_today_total'
                                        ]
                                    )
                                }}
                            </strong>
                        </span>

                        <span>
                            Sem prazo
                            <strong>
                                {{
                                    $number(
                                        $seller[
                                            'unscheduled_total'
                                        ]
                                    )
                                }}
                            </strong>
                        </span>

                        <span>
                            Parados
                            <strong>
                                {{
                                    $number(
                                        $seller[
                                            'stale_total'
                                        ]
                                    )
                                }}
                            </strong>
                        </span>

                    </div>


                    <a
                        href="{{
                            route(
                                'leads.index',
                                [
                                    'owner' =>
                                        (string) $seller[
                                            'user_id'
                                        ],
                                ]
                            )
                        }}"
                        wire:navigate
                        class="ecgm-seller-open"
                    >
                        Abrir carteira →
                    </a>

                </article>

            @empty

                <div class="ecgm-focus-empty">
                    <div class="ecgm-empty-state">

                                    <strong>
                                        Nenhuma carteira atribuída.
                                    </strong>

                                    <span>
                                        Distribua novos leads ou atribua
                                        empresas para começar a acompanhar
                                        a carga da equipe.
                                    </span>

                                </div>
                </div>

            @endforelse

        </div>

    </section>


    <section
        class="ecgm-panel"
        aria-labelledby="ecgm-team-title"
    >
        <header class="ecgm-panel-head">
            <div>
                <h2 id="ecgm-team-title">
                    Distribuição da carteira
                </h2>

                <p>
                    Compare carteira, oportunidades e pendências.
                    Clique nos números para abrir a fila correspondente.
                </p>
            </div>

            <span class="ecgm-caption">
                Atualização a cada 30 s enquanto a tela estiver visível.
            </span>
        </header>

        <div class="ecgm-table-scroll">
            <table class="ecgm-table ecgm-management-table">
                <caption class="ecgm-sr">
                    Carteira e pendências por responsável comercial
                </caption>

                <thead>
                    <tr>
                        <th scope="col">Responsável</th>

                        @foreach ($columns as [$key, $label, $filters])
                            <th scope="col">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @forelse ($sellers as $seller)
                        <tr wire:key="ecgm-seller-{{ $seller['user_id'] }}">
                            <th scope="row">
                                <a
                                    href="{{
                                        route(
                                            'leads.index',
                                            [
                                                'owner' => (string) $seller['user_id'],
                                            ]
                                        )
                                    }}"
                                    wire:navigate
                                >
                                    {{ $seller['name'] }}
                                </a>

                                <small>{{ $seller['email'] }}</small>
                            </th>

                            @foreach ($columns as [$key, $label, $filters])
                                <td data-label="{{ $label }}">
                                    <a
                                        class="ecgm-table-number {{
                                            $key === 'overdue_total'
                                            && (int) $seller[$key] > 0
                                                ? 'ecgm-danger-text'
                                                : ''
                                        }}"
                                        href="{{
                                            route(
                                                'leads.index',
                                                array_merge(
                                                    [
                                                        'owner' => (string) $seller['user_id'],
                                                    ],
                                                    $filters
                                                )
                                            )
                                        }}"
                                        wire:navigate
                                        aria-label="{{ $label }} de {{ $seller['name'] }}: {{ $number($seller[$key]) }}"
                                    >
                                        {{ $number($seller[$key]) }}
                                    </a>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="ecgm-empty">
                                Nenhuma carteira atribuída.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="ecgm-totals">

            <span>
                Novos
                <strong>
                    {{
                        $number(
                            $summary[
                                'new_total'
                            ]
                        )
                    }}
                </strong>
            </span>

            <span>
                Sem prazo
                <strong>
                    {{
                        $number(
                            $summary[
                                'unscheduled_total'
                            ]
                        )
                    }}
                </strong>
            </span>

            <span>
                Contatos parados
                <strong>
                    {{
                        $number(
                            $summary[
                                'stale_total'
                            ]
                        )
                    }}
                </strong>
            </span>

            <span>
                Futuras
                <strong>
                    {{
                        $number(
                            $summary[
                                'future_total'
                            ]
                        )
                    }}
                </strong>
            </span>

        </footer>
    </section>

    <div class="ecgm-controls">

        <section
            class="ecgm-panel"
            aria-labelledby="ecgm-distribution-title"
        >
            <header class="ecgm-panel-head">
                <div>
                    <span class="ecgm-eyebrow">
                        Distribuição automática
                    </span>

                    <h2 id="ecgm-distribution-title">
                        Balancear novos leads
                    </h2>
                </div>

                <div class="ecgm-available">
                    <span>Novos disponíveis</span>
                    <strong>{{ $number($available) }}</strong>
                </div>
            </header>

            <div class="ecgm-panel-body">
                <p class="ecgm-description">
                    Somente leads novos e sem responsável.
                    A distribuição considera a carga da carteira
                    e prioriza os maiores scores.
                </p>

                @if ($distributionMessage !== '')
                    <div
                        class="ecgm-message"
                        data-tone="success"
                        role="status"
                    >
                        {{ $distributionMessage }}
                    </div>
                @endif

                @if ($distributionError !== '')
                    <div
                        class="ecgm-message"
                        data-tone="danger"
                        role="alert"
                    >
                        {{ $distributionError }}
                    </div>
                @endif

                <div class="ecgm-options-head">
                    <h3>Responsáveis participantes</h3>

                    <div>
                        <button
                            type="button"
                            wire:click="selectAllDistributionSellers"
                            wire:loading.attr="disabled"
                            class="ecgm-text-button"
                        >
                            Selecionar todos
                        </button>

                        <button
                            type="button"
                            wire:click="clearDistributionSellers"
                            wire:loading.attr="disabled"
                            class="ecgm-text-button"
                        >
                            Limpar
                        </button>
                    </div>
                </div>

                <fieldset
                    class="ecgm-people"
                    wire:loading.attr="disabled"
                    wire:target="autoDistribute"
                >
                    <legend class="ecgm-sr">
                        Selecione quem receberá novos leads
                    </legend>

                    @forelse ($participants as $participant)
                        <label
                            class="ecgm-person"
                            wire:key="ecgm-participant-{{ $participant->id }}"
                        >
                            <input
                                type="checkbox"
                                value="{{ $participant->id }}"
                                wire:model.live="distributionSellerIds"
                            >

                            <span>
                                <strong>{{ $participant->name }}</strong>
                                <small>{{ $participant->email }}</small>
                            </span>
                        </label>
                    @empty
                        <p class="ecgm-description">
                            Nenhum usuário com e-mail verificado disponível.
                        </p>
                    @endforelse
                </fieldset>

                @include(
                    'partials.commercial-distribution-preview'
                )


                <div class="ecgm-distribution-actions">
                    <div class="ecgm-field">
                        <label for="ecgm-limit">
                            Quantidade nesta rodada
                        </label>

                        <input
                            id="ecgm-limit"
                            type="number"
                            min="1"
                            max="500"
                            step="1"
                            wire:model.blur="distributionLimit"
                            wire:loading.attr="disabled"
                            wire:target="autoDistribute"
                            aria-describedby="ecgm-limit-help"
                        >

                        <small id="ecgm-limit-help">
                            Máximo de 500 por execução.
                        </small>
                    </div>

                    <button
                        type="button"
                        class="ecgm-button ecgm-primary"
                        wire:click="autoDistribute"
                        wire:confirm="Distribuir os novos leads entre os responsáveis selecionados?"
                        wire:loading.attr="disabled"
                        @disabled($distributionSellerIds === [] || $available === 0)
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

                @if ($distributionSellerIds === [])
                    <p class="ecgm-hint">
                        Selecione pelo menos um responsável
                        para habilitar a distribuição.
                    </p>
                @elseif ($available === 0)
                    <p class="ecgm-hint">
                        Nenhum lead novo disponível para esta distribuição.
                    </p>
                @endif
            </div>

            <footer class="ecgm-note">
                Define somente o responsável interno.
                Não altera etapa, status ou negócio no HubSpot.
            </footer>
        </section>

        <section
            class="ecgm-panel"
            aria-labelledby="ecgm-access-title"
        >
            <header class="ecgm-panel-head">
                <div>
                    <span class="ecgm-eyebrow">
                        ACESSOS COMERCIAIS
                    </span>

                    <h2 id="ecgm-access-title">
                        Gestores e vendedores
                    </h2>
                </div>
            </header>

            <div class="ecgm-panel-body">
                <p class="ecgm-description">
                    Gestores acompanham a operação e distribuem leads.
                    Vendedores trabalham com a própria carteira.
                </p>

                @if ($roleMessage !== '')
                    <div
                        class="ecgm-message"
                        data-tone="success"
                        role="status"
                    >
                        {{ $roleMessage }}
                    </div>
                @endif

                @if ($roleError !== '')
                    <div
                        class="ecgm-message"
                        data-tone="danger"
                        role="alert"
                    >
                        {{ $roleError }}
                    </div>
                @endif

                <div class="ecgm-roles">
                    @forelse ($commercialUsers as $commercialUser)
                        <div
                            class="ecgm-role-row"
                            wire:key="ecgm-role-{{ $commercialUser->id }}-{{ $commercialUser->commercial_role }}"
                        >
                            <div>
                                <strong>{{ $commercialUser->name }}</strong>
                                <small>{{ $commercialUser->email }}</small>
                            </div>

                            <div
                                class="ecgm-role-buttons"
                                role="group"
                                aria-label="Perfil comercial de {{ $commercialUser->name }}"
                            >
                                @foreach (['manager' => 'Gestor', 'seller' => 'Vendedor'] as $role => $label)
                                    <button
                                        type="button"
                                        wire:click="updateCommercialRole({{ $commercialUser->id }}, '{{ $role }}')"
                                        wire:confirm="Alterar o perfil comercial de {{ $commercialUser->name }} para {{ $label }}?"
                                        wire:loading.attr="disabled"
                                        @disabled($commercialUser->commercial_role === $role)
                                        aria-pressed="{{ $commercialUser->commercial_role === $role ? 'true' : 'false' }}"
                                    >
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="ecgm-description">
                            Nenhum usuário com e-mail verificado.
                        </p>
                    @endforelse
                </div>

                <p class="ecgm-hint">
                    Clique no perfil desejado e confirme.
                    As regras de acesso existentes continuam sendo aplicadas.
                </p>
            </div>
        </section>

    </div>
</div>
