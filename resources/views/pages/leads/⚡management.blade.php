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

    public string $queueSearch = '';

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

    public function openLeadQueue(): void
    {
        $this->assertCommercialManager();

        $term = trim($this->queueSearch);

        $params = [];

        if ($term !== '') {
            $params['search'] = $term;
        }

        $this->redirect(
            route('leads.index', $params),
            navigate: true
        );
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

{{-- EC_GESTAO_FIGMA_V62: tela L04 fiel ao protótipo. Todos os números vêm dos serviços existentes. --}}
<div class="ecgm-page ecgm-v2 lf34-management ecgm61 ecgm62"
     data-ecgm-figma="L04-L05-V62"
     wire:poll.visible.30s="$refresh"
     x-data="{ view: 'overview', sellerPage: 1, showConfirm: false }">

    <div class="ecgm-page-loading" wire:loading.delay role="status" aria-live="polite">
        <span class="ecgm-page-loading-dot" aria-hidden="true"></span>
        Atualizando painel comercial...
    </div>

    @php
        $dashboard = $this->dashboard;
        $summary = $dashboard['summary'];
        $sellers = array_values($dashboard['sellers']);
        $available = $this->distributionCandidatesCount;
        $participants = $this->distributionUsers;
        $commercialUsers = $this->commercialUsers;
        $attentionQueue = $this->managementAttentionQueue;
        $distributionPreview = $this->distributionPreview;
        $number = static fn ($value): string => number_format((int) $value, 0, ',', '.');
        $localCompanies = \App\Models\Company::query()->count();
        $activeSellers = $commercialUsers->where('commercial_role', User::ROLE_SELLER)->count();
        $sellerPages = max(1, (int) ceil(count($sellers) / 5));
        $rolesById = $commercialUsers->mapWithKeys(static fn ($u): array => [(string) $u->id => (string) $u->commercial_role])->all();
        $firstUser = $commercialUsers->first();
        $attentionCompanies = $attentionQueue === []
            ? collect()
            : \App\Models\Company::query()
                ->whereIn('id', collect($attentionQueue)->pluck('company_id')->all())
                ->get(['id', 'uuid'])
                ->keyBy('id');
    @endphp

    {{-- L04: título compacto; ações abaixo do subtítulo, sem indicadores redundantes. --}}
    <header class="ecgm62-page-header">
        <span class="ecgm62-eyebrow">LEADS</span>
        <h1>Gestão Comercial</h1>
        <p>Distribua trabalho e acompanhe a capacidade da equipe.</p>
        <div class="ecgm62-top-actions">
            <button type="button" class="ecgm62-btn" wire:click="$refresh" wire:loading.attr="disabled">
                Atualizar visão
            </button>
            <button type="button" class="ecgm62-btn ecgm62-btn--primary"
                    aria-label="Distribuição automática — abrir prévia de distribuição"
                    x-on:click="view='distribution'; showConfirm=false">
                Distribuir carteira
            </button>
        </div>
    </header>

    {{-- L04: visão principal. A ordem segue exatamente a referência. --}}
    <div class="lf34-manager-overview ecgm62-overview" x-show="view === 'overview'">
        <section class="ecgm-metrics ecgm62-kpis" aria-label="Indicadores da Gestão Comercial">
            <article class="ecgm-metric ecgm62-kpi">
                <span>Empresas na base local</span>
                <strong>{{ $number($localCompanies) }}</strong>
                <small>Universo cadastral do Prospector</small>
            </article>
            <a class="ecgm-metric ecgm62-kpi" href="{{ route('leads.index', ['owner' => 'unassigned']) }}" wire:navigate>
                <span>Sem responsável</span>
                <strong>{{ $number($summary['unassigned_total']) }}</strong>
                <small>Elegibilidade revalidada na distribuição</small>
            </a>
            <a class="ecgm-metric ecgm62-kpi" href="{{ route('leads.index', ['workStatus' => 'waiting', 'followUp' => 'overdue']) }}" wire:navigate>
                <span>Retornos atrasados</span>
                <strong>{{ $number($summary['overdue_total']) }}</strong>
                <small>Abra a fila de retornos vencidos</small>
            </a>
            <article class="ecgm-metric ecgm62-kpi">
                <span>Vendedores ativos</span>
                <strong>{{ $number($activeSellers) }}</strong>
                <small>Usuários vendedores com acesso verificado</small>
            </article>
        </section>

        {{-- L04: tabela compacta em vez do painel duplicado de cartões de vendedores. --}}
        <section class="ecgm-panel ecgm62-card ecgm62-load-card" aria-labelledby="ecgm62-load-title">
            <header class="ecgm62-card-header">
                <h2 id="ecgm62-load-title">Carga por vendedor</h2>
                <span class="ecgm62-sr">Carga e pendências por vendedor · Distribuição da carteira</span>
            </header>
            <div class="ecgm-team-focus-grid ecgm62-table-shell">
                <div class="ecgm-table-scroll ecgm62-scroll" role="region" tabindex="0" aria-label="Carteira e pendências por vendedor">
                    <table class="ecgm-management-table ecgm62-table">
                        <thead>
                            <tr>
                                <th scope="col">Responsável</th>
                                <th scope="col" title="Carteira ativa de leads">Carteira</th>
                                <th scope="col" title="Oportunidades ativas vinculadas">Ativos</th>
                                <th scope="col">Atrasados</th>
                                <th scope="col">Hoje</th>
                                <th scope="col">Sem prazo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sellers as $index => $seller)
                                <tr wire:key="ecgm62-seller-{{ $seller['user_id'] }}"
                                    x-show="sellerPage === {{ intdiv($index, 5) + 1 }}"
                                    x-cloak>
                                    <th scope="row">
                                        <a href="{{ route('leads.index', ['owner' => (string) $seller['user_id']]) }}"
                                           wire:navigate class="ecgm-v2-table-action ecgm62-person-link"
                                           title="Abrir carteira de {{ $seller['name'] }}">
                                            {{ $seller['name'] }}
                                            <span class="ecgm62-sr">Abrir carteira</span>
                                        </a>
                                    </th>
                                    <td>
                                        <a href="{{ route('leads.index', ['owner' => (string) $seller['user_id']]) }}" wire:navigate>
                                            {{ $number($seller['active_total']) }}
                                        </a>
                                    </td>
                                    <td title="Oportunidades ativas">
                                        <a href="{{ route('leads.index', ['owner' => (string) $seller['user_id'], 'dashboardView' => 'with_deal']) }}" wire:navigate>
                                            {{ $number($seller['opportunities_total']) }}
                                        </a>
                                    </td>
                                    <td>
                                        <a href="{{ route('leads.index', ['owner' => (string) $seller['user_id'], 'workStatus' => 'waiting', 'followUp' => 'overdue']) }}" wire:navigate
                                           class="{{ (int) $seller['overdue_total'] > 0 ? 'ecgm62-overdue' : '' }}">
                                            {{ $number($seller['overdue_total']) }}
                                        </a>
                                    </td>
                                    <td>
                                        <a href="{{ route('leads.index', ['owner' => (string) $seller['user_id'], 'workStatus' => 'waiting', 'followUp' => 'today']) }}" wire:navigate>
                                            {{ $number($seller['due_today_total']) }}
                                        </a>
                                    </td>
                                    <td>
                                        <a href="{{ route('leads.index', ['owner' => (string) $seller['user_id'], 'workStatus' => 'waiting', 'followUp' => 'unscheduled']) }}" wire:navigate>
                                            {{ $number($seller['unscheduled_total']) }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="ecgm-empty ecgm-empty-state">Nenhuma carteira atribuída.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <footer class="ecgm62-card-footer">
                <span>Clique em um recorte para abrir a fila correspondente. <a href="{{ route('leads.index') }}" wire:navigate>Ver todos os leads</a></span>
                <div class="ecgm62-pagination" role="group" aria-label="Paginação dos vendedores">
                    <button type="button" class="ecgm62-btn ecgm62-btn--small"
                            x-on:click="sellerPage = Math.max(1, sellerPage - 1)"
                            x-bind:disabled="sellerPage <= 1">Anterior</button>
                    <button type="button" class="ecgm62-btn ecgm62-btn--small"
                            x-on:click="sellerPage = Math.min({{ $sellerPages }}, sellerPage + 1)"
                            x-bind:disabled="sellerPage >= {{ $sellerPages }}">Próxima</button>
                </div>
            </footer>
        </section>

        {{-- A fila é aberta por padrão, mas pode ser recolhida sem perder ações. --}}
        <details class="ecgm-v2-attention-drawer ecgm62-attention-details" open>
            <summary class="ecgm62-attention-summary"><span>Fila de atenção</span>
                <span class="ecgm62-attention-hint">{{ $number(count($attentionQueue)) }} exibida(s) <span aria-hidden="true">⌄</span></span>
            </summary>
            <div class="ecgm62-attention-content">
                <span class="ecgm62-sr">Empresas que precisam de atenção · Exigem atenção · Ver prioridades</span>
                <div class="ecgm62-scroll" role="region" tabindex="0" aria-label="Fila gerencial de atenção">
                    <table class="ecgm62-table ecgm62-attention-table">
                        <thead>
                            <tr>
                                <th scope="col">Empresa</th>
                                <th scope="col">Motivo</th>
                                <th scope="col">SDR</th>
                                <th scope="col">Responsável</th>
                                <th scope="col">Próxima ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse (array_slice($attentionQueue, 0, 5) as $attention)
                                @php $attentionCompany = $attentionCompanies->get((int) $attention['company_id']); @endphp
                                <tr wire:key="ecgm62-attention-{{ $attention['company_id'] }}">
                                    <th scope="row">
                                        @if ($attentionCompany)
                                            <a href="{{ route('companies.show', $attentionCompany) }}" wire:navigate
                                               class="ecgm62-person-link" title="Abrir dossiê de {{ $attention['company_name'] }}">
                                                {{ $attention['company_name'] }}
                                                <span class="ecgm62-sr">Abrir dossiê</span>
                                            </a>
                                        @else
                                            {{ $attention['company_name'] }}
                                        @endif
                                    </th>
                                    <td><span class="ecgm62-reason">{{ $attention['reason'] }}</span></td>
                                    <td>{{ $number($attention['score']) }}</td>
                                    <td>{{ $attention['owner_name'] ?: 'Não atribuído' }}</td>
                                    <td>
                                        <a href="{{ route('leads.index', $attention['filters']) }}" wire:navigate
                                           title="Ver fila — {{ $attention['detail'] }}">
                                            {{ $attention['moment'] ?: $attention['detail'] }}
                                            <span class="ecgm62-sr">Ver fila</span>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="ecgm-empty ecgm-empty-state">
                                        <strong>Nenhuma pendência crítica agora</strong>
                                        <small>Não há atrasos, acompanhamentos sem prazo ou contatos parados nesta fila.</small>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="ecgm62-attention-footer">
                    <span>Mostrando até 5 de {{ $number(count($attentionQueue)) }} empresas prioritárias. Parados: {{ $number($summary['stale_total']) }} · Sem prazo: {{ $number($summary['unscheduled_total']) }}.</span>
                    <a href="{{ route('leads.index') }}" wire:navigate>Ver todos os leads →</a>
                </div>
            </div>
        </details>

        <section class="ecgm-panel ecgm62-card ecgm62-role-card" aria-labelledby="ecgm62-roles-title">
            <header class="ecgm62-card-header">
                <h2 id="ecgm62-roles-title">Papéis comerciais</h2>
                <span class="ecgm62-sr">Gestores e vendedores</span>
            </header>
            <div class="ecgm62-role-body"
                 x-data="{ selectedUser: @js((string) ($firstUser?->id ?? '')), selectedRole: @js((string) ($firstUser?->commercial_role ?? 'seller')), roles: @js($rolesById) }">
                <div class="ecgm62-role-field">
                    <label for="ecgm62-user">Usuário</label>
                    <select id="ecgm62-user" x-model="selectedUser"
                            x-on:change="selectedRole = roles[selectedUser] || 'seller'">
                        @foreach ($commercialUsers as $commercialUser)
                            <option value="{{ $commercialUser->id }}">{{ $commercialUser->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ecgm62-role-field">
                    <label for="ecgm62-role">Papel</label>
                    <select id="ecgm62-role" x-model="selectedRole" @disabled($commercialUsers->isEmpty())>
                        <option value="seller">Vendedor</option>
                        <option value="manager">Gestor</option>
                    </select>
                    <small>Alterações administrativas exigem confirmação e validação.</small>
                </div>
                <div class="ecgm62-role-action">
                    @foreach ($commercialUsers as $commercialUser)
                        @foreach (['manager' => 'Gestor', 'seller' => 'Vendedor'] as $role => $label)
                            <button type="button" class="ecgm62-btn ecgm62-btn--small"
                                    x-show="selectedUser === '{{ $commercialUser->id }}' && selectedRole === '{{ $role }}' && roles[selectedUser] !== selectedRole"
                                    x-cloak
                                    x-bind:disabled="roles[selectedUser] === selectedRole"
                                    wire:click="updateCommercialRole({{ $commercialUser->id }}, '{{ $role }}')"
                                    wire:confirm="Alterar o perfil comercial de {{ $commercialUser->name }} para {{ $label }}?"
                                    wire:loading.attr="disabled"
                                    wire:target="updateCommercialRole">Salvar</button>
                        @endforeach
                    @endforeach
                </div>
            </div>
            @if ($roleMessage !== '')
                <div class="ecgm62-message ecgm62-message--success" role="status">{{ $roleMessage }}</div>
            @endif
            @if ($roleError !== '')
                <div class="ecgm62-message ecgm62-message--error" role="alert">{{ $roleError }}</div>
            @endif
        </section>
    </div>

    {{-- L05: distribuição continua como uma área separada, sem poluir o L04. --}}
    <div class="ecgm62-distribution" x-show="view === 'distribution'" x-cloak>
        <header class="ecgm62-dist-header">
            <div>
                <span class="ecgm62-eyebrow">DISTRIBUIÇÃO AUTOMÁTICA</span>
                <h2>Balancear novos leads</h2>
                <p>Escolha os vendedores, defina a quantidade e confira a prévia antes de confirmar.</p>
            </div>
            <button type="button" class="ecgm62-btn" x-on:click="view='overview'; showConfirm=false">Voltar à gestão</button>
        </header>

        @if ($distributionMessage !== '')
            <div class="ecgm62-result" role="status">
                <strong>Distribuição concluída</strong>
                <span>{{ $distributionMessage }}</span>
                <a href="{{ route('leads.index') }}" wire:navigate>Ver fila de leads →</a>
            </div>
        @endif
        @if ($distributionError !== '')
            <div class="ecgm62-message ecgm62-message--error" role="alert">{{ $distributionError }}</div>
        @endif

        <section class="ecgm62-dist-stats" aria-label="Resumo da distribuição">
            <div><span>Novos disponíveis</span><strong>{{ $number($available) }}</strong></div>
            <div><span>Responsáveis selecionados</span><strong>{{ $number(count($distributionSellerIds)) }}</strong></div>
            <div><span>Quantidade nesta rodada</span><strong>{{ $number($distributionLimit) }}</strong></div>
        </section>
        <section class="ecgm-panel ecgm62-card" aria-labelledby="ecgm62-dist-options-title">
            <header class="ecgm62-card-header ecgm62-card-header--wrap">
                <div>
                    <h3 id="ecgm62-dist-options-title">Responsáveis participantes</h3>
                    <p>Somente leads novos e sem responsável. A carga atual é considerada no balanceamento.</p>
                </div>
                <div class="ecgm62-inline-actions">
                    <button type="button" class="ecgm62-link-btn" wire:click="selectAllDistributionSellers">Selecionar todos</button>
                    <button type="button" class="ecgm62-link-btn" wire:click="clearDistributionSellers">Limpar</button>
                </div>
            </header>
            <fieldset class="ecgm62-people" wire:loading.attr="disabled" wire:target="autoDistribute">
                <legend class="ecgm62-sr">Vendedores que participarão da distribuição</legend>
                @forelse ($participants as $participant)
                    <label class="ecgm62-person" wire:key="ecgm62-participant-{{ $participant->id }}">
                        <input type="checkbox" value="{{ $participant->id }}" wire:model.live="distributionSellerIds">
                        <span class="ecgm62-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::initials((string) $participant->name, true) }}</span>
                        <span><strong>{{ $participant->name }}</strong><small>{{ $participant->email }}</small></span>
                    </label>
                @empty
                    <div class="ecgm-empty-state">Nenhum usuário com e-mail verificado disponível.</div>
                @endforelse
            </fieldset>
            <div class="ecgm62-dist-config">
                <label for="ecgm62-limit">Quantidade nesta rodada</label>
                <input id="ecgm62-limit" type="number" min="1" max="500" step="1"
                       wire:model.blur="distributionLimit" wire:loading.attr="disabled" wire:target="autoDistribute">
                <small>Máximo de 500 leads por execução. Nenhuma alteração ocorre durante a prévia.</small>
            </div>
        </section>

        @include('partials.commercial-distribution-preview-v61')

        <div class="ecgm62-dist-footer">
            <button type="button" class="ecgm62-btn" x-on:click="view='overview'; showConfirm=false">Cancelar</button>
            <button type="button" class="ecgm62-btn" wire:click="$refresh" wire:loading.attr="disabled">Recalcular prévia</button>
            <button type="button" class="ecgm62-btn ecgm62-btn--primary"
                    x-on:click="showConfirm=true"
                    @disabled($distributionSellerIds === [] || $available === 0 || $distributionPreview === null)>
                Distribuir automaticamente
            </button>
        </div>
        @if ($distributionSellerIds === [] || $available === 0)
            <p class="ecgm62-help">Selecione pelo menos um responsável e verifique se há novos leads disponíveis.</p>
        @endif
        <p class="ecgm62-help">Esta operação altera apenas a atribuição comercial local. Não modifica proprietários, negócios ou etapas no HubSpot.</p>
    </div>

    {{-- L05: confirmação explícita antes de qualquer gravação. --}}
    <div class="lf34-confirm-overlay ecgm62-modal-backdrop" x-show="showConfirm" x-cloak
         x-on:keydown.escape.window="showConfirm=false" x-on:click.self="showConfirm=false">
        <section class="lf34-confirm-dialog ecgm62-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="ecgm62-confirm-title">
            <h2 id="ecgm62-confirm-title">Confirmar distribuição?</h2>
            <p>Atribuiremos apenas leads elegíveis após nova validação. A simulação não garante que a carteira permanecerá inalterada por outros usuários.</p>
            <div class="ecgm62-confirm-stats">
                <div><small>Leads nesta rodada</small><strong>{{ $number($distributionPreview['planned'] ?? 0) }}</strong></div>
                <div><small>Responsáveis</small><strong>{{ $number(count($distributionSellerIds)) }}</strong></div>
                <div><small>Restantes sem responsável</small><strong>{{ $number($distributionPreview['remaining_after'] ?? 0) }}</strong></div>
            </div>
            <p class="ecgm62-help">Não altera diretamente o proprietário no HubSpot.</p>
            <div class="lf34-confirm-buttons ecgm62-confirm-actions">
                <button type="button" class="ecgm62-btn" x-on:click="showConfirm=false">Cancelar</button>
                <button type="button" class="ecgm62-btn ecgm62-btn--primary"
                        wire:click="autoDistribute" x-on:click="showConfirm=false"
                        wire:loading.attr="disabled" wire:target="autoDistribute"
                        @disabled($distributionSellerIds === [] || $available === 0 || $distributionPreview === null)>
                    Confirmar distribuição
                </button>
            </div>
        </section>
    </div>

    <footer class="ecgm62-page-footer">ExportControl Prospector · Dados da operação comercial</footer>
</div>
