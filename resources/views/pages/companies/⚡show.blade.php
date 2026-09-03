<?php

use App\Models\Company;
use App\Services\EstablishmentService;
use App\Support\Cnpj;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public string $newCnaeCode = '';

public string $newCnaeDescription = '';

public bool $newCnaePrimary = false;

public bool $showCnaeForm = false;

    public function mount(Company $company): void
    {
        $this->company = $company->load([
            'establishments.cnaes',
            'icpScore',
            'crmCheck',
        ]);
    }

    #[Computed]
    public function matrix()
    {
        return $this->company
            ->establishments
            ->firstWhere('type', 'matrix')
            ?? $this->company
                ->establishments
                ->first();
    }

    #[Computed]
    public function primaryCnae()
    {
        return $this->matrix
            ?->cnaes
            ->first(
                fn ($cnae) =>
                    (bool) $cnae
                        ->pivot
                        ->is_primary
            );
    }

    #[Computed]
    public function secondaryCnaes(): Collection
    {
        if (! $this->matrix) {
            return collect();
        }

        return $this->matrix
            ->cnaes
            ->filter(
                fn ($cnae) =>
                    ! (bool) $cnae
                        ->pivot
                        ->is_primary
            )
            ->values();
    }

public function toggleCnaeForm(): void
{
    $this->showCnaeForm =
        ! $this->showCnaeForm;

    if (! $this->showCnaeForm) {
        $this->resetCnaeForm();
    }
}

public function addCnae(
    EstablishmentService $service
): void {
    $validated = $this->validate([
        'newCnaeCode' => [
            'required',
            'string',
            'max:20',
        ],

        'newCnaeDescription' => [
            'nullable',
            'string',
            'max:255',
        ],

        'newCnaePrimary' => [
            'boolean',
        ],
    ]);

    if (! $this->matrix) {
        $this->addError(
            'newCnaeCode',
            'A empresa não possui matriz cadastrada.'
        );

        return;
    }

    try {
        $service->addCnae(
            $this->matrix,
            [
                'code' =>
                    $validated[
                        'newCnaeCode'
                    ],

                'description' =>
                    $validated[
                        'newCnaeDescription'
                    ] ?: null,

                'is_primary' =>
                    $validated[
                        'newCnaePrimary'
                    ],
            ]
        );
    } catch (\InvalidArgumentException $exception) {
        $this->addError(
            'newCnaeCode',
            $exception->getMessage()
        );

        return;
    }

    $this->reloadCompany();

    $this->resetCnaeForm();

    $this->showCnaeForm = false;

    session()->flash(
        'success',
        'CNAE adicionado com sucesso.'
    );
}

public function makeCnaePrimary(
    int $cnaeId,
    EstablishmentService $service
): void {
    if (! $this->matrix) {
        return;
    }

    $cnae = $this->matrix
        ->cnaes
        ->firstWhere(
            'id',
            $cnaeId
        );

    if (! $cnae) {
        return;
    }

    try {
        $service->setPrimaryCnae(
            $this->matrix,
            $cnae
        );
    } catch (\InvalidArgumentException) {
        return;
    }

    $this->reloadCompany();

    session()->flash(
        'success',
        'CNAE principal atualizado.'
    );
}

public function removeCnae(
    int $cnaeId,
    EstablishmentService $service
): void {
    if (! $this->matrix) {
        return;
    }

    $cnae = $this->matrix
        ->cnaes
        ->firstWhere(
            'id',
            $cnaeId
        );

    if (! $cnae) {
        return;
    }

    $service->removeCnae(
        $this->matrix,
        $cnae
    );

    $this->reloadCompany();

    session()->flash(
        'success',
        'CNAE removido.'
    );
}

private function resetCnaeForm(): void
{
    $this->newCnaeCode = '';

    $this->newCnaeDescription = '';

    $this->newCnaePrimary = false;

    $this->resetValidation([
        'newCnaeCode',
        'newCnaeDescription',
        'newCnaePrimary',
    ]);
}

private function reloadCompany(): void
{
    $this->company = $this->company
        ->fresh()
        ->load([
            'establishments.cnaes',
            'icpScore',
            'crmCheck',
        ]);

    unset(
        $this->matrix,
        $this->primaryCnae,
        $this->secondaryCnaes,
    );
}

    public function formatMoney(
        mixed $value
    ): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return 'R$ '.number_format(
            (float) $value,
            2,
            ',',
            '.'
        );
    }
};
?>

<div class="ec-page-shell">

    {{-- VOLTAR --}}
    <div>
        <a
            href="{{ route('companies.index') }}"
            wire:navigate
            class="ec-back-link"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                class="size-4"
            >
                <path d="m15 18-6-6 6-6" />
            </svg>

            Empresas
        </a>
    </div>


    {{-- CABEÇALHO DA EMPRESA --}}
    <section class="ec-dossier-hero">

        <div class="min-w-0">

            <div class="ec-page-kicker">
                Dossiê empresarial
            </div>

            <div class="ec-dossier-title-row">

                <h1 class="ec-dossier-title">
                    {{ $company->corporate_name }}
                </h1>

                @if ($this->matrix?->registration_status === 'ATIVA')

                    <span class="ec-status ec-status-active">
                        <span></span>
                        Ativa
                    </span>

                @elseif (
                    $this->matrix?->registration_status
                    === 'SUSPENSA'
                )

                    <span class="ec-status ec-status-warning">
                        <span></span>
                        Suspensa
                    </span>

                @elseif ($this->matrix?->registration_status)

                    <span class="ec-status ec-status-inactive">
                        <span></span>

                        {{
                            ucfirst(
                                mb_strtolower(
                                    $this->matrix
                                        ->registration_status
                                )
                            )
                        }}
                    </span>

                @endif

            </div>

            <div class="ec-dossier-meta">

                @if ($this->matrix)

                    <span>
                        {{ Cnpj::format($this->matrix->cnpj) }}
                    </span>

                @endif

                @if ($this->matrix?->municipality_name)

                    <span class="ec-meta-separator">
                        •
                    </span>

                    <span>
                        {{ $this->matrix->municipality_name }}

                        @if ($this->matrix->state)
                            / {{ $this->matrix->state }}
                        @endif
                    </span>

                @endif

                @if ($this->matrix?->fantasy_name)

                    <span class="ec-meta-separator">
                        •
                    </span>

                    <span>
                        {{ $this->matrix->fantasy_name }}
                    </span>

                @endif

            </div>

        </div>

        <a
            href="{{ route('companies.edit', $company) }}"
            wire:navigate
            class="ec-button-secondary"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                class="size-4"
            >
                <path
                    d="M12 20h9"
                />

                <path
                    d="M16.5 3.5a2.121 2.121 0 0 1 3 3L8 18l-4 1 1-4Z"
                />
            </svg>

            Editar empresa
        </a>

    </section>


    {{-- MENSAGENS --}}
    @if (session('success'))

        <div class="ec-alert-success">

            <span class="ec-alert-dot"></span>

            <span>
                {{ session('success') }}
            </span>

        </div>

    @endif


    {{-- INTELIGÊNCIA COMERCIAL --}}
    <section>

        <div class="ec-section-heading">

            <div>

                <h2 class="ec-section-title">
                    Inteligência comercial
                </h2>

                <p class="ec-section-description">
                    Situação atual da empresa dentro do processo de prospecção.
                </p>

            </div>

            <span class="ec-section-hint">
                Enriquecimento automático nas próximas etapas
            </span>

        </div>


        <div class="ec-intelligence-grid">

            {{-- CRM --}}

            @php
                $crm = $company->crmCheck;

                $crmStatusLabel = match ($crm?->status) {
                    'client' => 'Cliente',
                    'opportunity' => 'Oportunidade',
                    'prospected' => 'Prospectado',
                    'known' => 'Conhecido',
                    'not_found' => 'Não encontrado',
                    default => 'Não verificado',
                };

                $crmStatusClasses = match ($crm?->status) {
                    'client' =>
                        'bg-emerald-500/15 text-emerald-300',

                    'opportunity' =>
                        'bg-amber-500/15 text-amber-300',

                    'prospected' =>
                        'bg-sky-500/15 text-sky-300',

                    'known' =>
                        'bg-violet-500/15 text-violet-300',

                    'not_found' =>
                        'bg-white/5 text-[#9ba3c2]',

                    default =>
                        'bg-white/5 text-[#7f87a7]',
                };

                $crmCaption = match ($crm?->status) {
                    'client' =>
                        'Já é cliente no CRM',

                    'opportunity' =>
                        'Já possui oportunidade comercial',

                    'prospected' =>
                        'Já houve contato comercial',

                    'known' =>
                        'Registro localizado no CRM',

                    'not_found' =>
                        'Não localizado no HubSpot',

                    default =>
                        'Base comercial',
                };
            @endphp

            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        CRM
                    </span>

                    @if ($crm)

                        <span
                            class="
                                rounded-full px-2 py-1
                                text-[10px] font-bold
                                uppercase tracking-wide
                                {{ $crmStatusClasses }}
                            "
                        >
                            {{ $crmStatusLabel }}
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                <div class="ec-intelligence-value">
                    {{ $crmStatusLabel }}
                </div>

                <div class="ec-intelligence-caption">
                    {{ $crmCaption }}
                </div>

                @if (
                    $crm?->matched_value
                    || $crm?->external_domain
                )

                    <div
                        class="
                            mt-2 truncate text-[11px]
                            text-[#7f87a7]
                        "
                        title="{{
                            $crm->matched_value
                            ?? $crm->external_domain
                        }}"
                    >
                        {{
                            $crm->matched_value
                            ?? $crm->external_domain
                        }}
                    </div>

                @endif

            </div>


            {{-- ICP --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        ICP
                    </span>

                    @if ($company->icpScore)

                        <span
                            class="
                                inline-flex size-7 items-center
                                justify-center rounded-full
                                text-xs font-bold
                                {{ match ($company->icpScore->grade) {
                                    'A' => 'bg-emerald-500/15 text-emerald-300',
                                    'B' => 'bg-sky-500/15 text-sky-300',
                                    'C' => 'bg-amber-500/15 text-amber-300',
                                    default => 'bg-rose-500/15 text-rose-300',
                                } }}
                            "
                        >
                            {{ $company->icpScore->grade }}
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                @if ($company->icpScore)

                    <div class="ec-intelligence-value">
                        {{ $company->icpScore->score }}/100
                    </div>

                    <div class="ec-intelligence-caption">
                        {{ $company->icpScore->label }}
                    </div>

                @else

                    <div class="ec-intelligence-value">
                        Não calculado
                    </div>

                    <div class="ec-intelligence-caption">
                        Perfil ideal
                    </div>

                @endif

            </div>


            {{-- DIRETA --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Exp. direta
                    </span>

                    <span class="ec-intelligence-dot"></span>

                </div>

                <div class="ec-intelligence-value">
                    Não pesquisada
                </div>

                <div class="ec-intelligence-caption">
                    Exportação própria
                </div>

            </div>


            {{-- INDIRETA --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Exp. indireta
                    </span>

                    <span class="ec-intelligence-dot"></span>

                </div>

                <div class="ec-intelligence-value">
                    Não pesquisada
                </div>

                <div class="ec-intelligence-caption">
                    Fim específico
                </div>

            </div>


            {{-- TRADING --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Trading
                    </span>

                    <span class="ec-intelligence-dot"></span>

                </div>

                <div class="ec-intelligence-value">
                    Não pesquisada
                </div>

                <div class="ec-intelligence-caption">
                    Relação comercial
                </div>

            </div>


            {{-- SCORE --}}
            <div class="ec-intelligence-card ec-intelligence-score">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Score
                    </span>

                    <span class="ec-intelligence-dot"></span>

                </div>

                <div class="ec-score-value">
                    —
                </div>

                <div class="ec-intelligence-caption">
                    Prioridade SDR
                </div>

            </div>

        </div>

        @if ($company->crmCheck)

            @php
                $crm = $company->crmCheck;
            @endphp

            <details
                class="
                    mt-4 overflow-hidden rounded-xl
                    border border-white/5
                    bg-white/[0.025]
                "
            >

                <summary
                    class="
                        flex cursor-pointer list-none
                        items-center justify-between
                        px-5 py-4
                        text-sm font-semibold
                        text-[#d9ddef]
                        transition
                        hover:bg-white/[0.025]
                    "
                >
                    <span>
                        Detalhamento do CRM
                    </span>

                    <span
                        class="
                            text-xs font-medium
                            text-[#7f87a7]
                        "
                    >
                        {{ $crmStatusLabel }}
                        •
                        {{ strtoupper($crm->provider) }}
                    </span>
                </summary>

                <div
                    class="
                        border-t border-white/5
                        px-5 py-5
                    "
                >

                    <div
                        class="
                            grid gap-4
                            sm:grid-cols-2
                            xl:grid-cols-4
                        "
                    >

                        <div>
                            <div class="ec-field-label">
                                Status comercial
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{ $crmStatusLabel }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Empresa no CRM
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{
                                    $crm->external_name
                                    ?: '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Encontrado por
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                @if ($crm->matched_by)

                                    {{
                                        match (
                                            $crm->matched_by
                                        ) {
                                            'domain' =>
                                                'Domínio',

                                            'name' =>
                                                'Nome',

                                            default =>
                                                ucfirst(
                                                    $crm->matched_by
                                                ),
                                        }
                                    }}

                                    @if ($crm->matched_value)
                                        ·
                                        {{ $crm->matched_value }}
                                    @endif

                                @else
                                    —
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Lifecycle HubSpot
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                {{
                                    $crm->lifecycle_stage
                                    ?: '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Interações registradas
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{ $crm->contacted_count }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Negócios associados
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{
                                    $crm
                                        ->associated_deals_count
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Último contato
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                {{
                                    $crm->last_contacted_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Verificado em
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                {{
                                    $crm->checked_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}
                            </div>
                        </div>

                    </div>

                    @if ($crm->external_url)

                        <div class="mt-5">

                            <a
                                href="{{ $crm->external_url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ec-button-secondary"
                            >
                                Abrir no HubSpot

                                <span aria-hidden="true">
                                    ↗
                                </span>
                            </a>

                        </div>

                    @endif

                </div>

            </details>

        @endif


        @if ($company->icpScore)

            <details class="mt-4 overflow-hidden rounded-xl border border-white/5 bg-white/[0.025]">

                <summary
                    class="
                        flex cursor-pointer list-none
                        items-center justify-between
                        px-5 py-4
                        text-sm font-semibold
                        text-[#d9ddef]
                        transition
                        hover:bg-white/[0.025]
                    "
                >
                    <span>
                        Detalhamento do ICP
                    </span>

                    <span class="text-xs font-medium text-[#7f87a7]">
                        {{ $company->icpScore->grade }}
                        •
                        {{ $company->icpScore->score }}/100
                    </span>
                </summary>

                <div class="border-t border-white/5 px-5 py-4">

                    <div class="space-y-3">

                        @foreach ([
                            'cnae' => 'CNAE prioritário',
                            'state' => 'Estado prioritário',
                            'size' => 'Porte da empresa',
                            'capital' => 'Capital social',
                            'legal_nature' => 'Natureza jurídica',
                            'regional_relevance' => 'Relevância regional',
                        ] as $factorKey => $factorLabel)

                            @php
                                $factor = data_get(
                                    $company->icpScore->factors,
                                    $factorKey,
                                    []
                                );

                                $points = (int) (
                                    $factor['points']
                                    ?? 0
                                );

                                $max = (int) (
                                    $factor['max']
                                    ?? 0
                                );

                                $reason =
                                    $factor['reason']
                                    ?? 'Sem informação.';
                            @endphp

                            <div
                                class="
                                    grid gap-3
                                    rounded-lg
                                    border border-white/5
                                    bg-black/5
                                    px-4 py-3
                                    md:grid-cols-[180px_1fr_80px]
                                    md:items-center
                                "
                            >

                                <div class="text-sm font-medium text-[#d9ddef]">
                                    {{ $factorLabel }}
                                </div>

                                <div class="text-xs text-[#838baa]">
                                    {{ $reason }}
                                </div>

                                <div class="text-right">

                                    <span
                                        class="
                                            inline-flex rounded-full
                                            px-2.5 py-1
                                            text-xs font-bold
                                            {{ $points > 0
                                                ? 'bg-emerald-500/10 text-emerald-300'
                                                : 'bg-white/5 text-[#747c9b]'
                                            }}
                                        "
                                    >
                                        +{{ $points }}/{{ $max }}
                                    </span>

                                </div>

                            </div>

                        @endforeach

                    </div>

                    <div
                        class="
                            mt-4 flex items-center
                            justify-between
                            border-t border-white/5
                            pt-4
                        "
                    >

                        <div>

                            <div class="text-xs uppercase tracking-[0.12em] text-[#737b9c]">
                                Classificação
                            </div>

                            <div class="mt-1 text-sm font-semibold text-white">
                                {{ $company->icpScore->label }}
                            </div>

                        </div>

                        <div class="text-right">

                            <div class="text-xs text-[#737b9c]">
                                Score cadastral
                            </div>

                            <div class="mt-1 text-2xl font-bold text-[#43b9a7]">
                                {{ $company->icpScore->score }}
                            </div>

                        </div>

                    </div>

                    <p class="mt-4 text-xs leading-5 text-[#68708f]">
                        Este score considera somente dados cadastrais e estruturais.
                        Exportação, relação com tradings, CRM e contatos serão avaliados
                        em etapas posteriores do Prospector.
                    </p>

                </div>

            </details>

        @endif

    </section>


    {{-- DADOS + CONTATO --}}
    <div class="ec-dossier-main-grid">

        {{-- DADOS CADASTRAIS --}}
        <section class="ec-detail-panel ec-dossier-data-panel">

            <div class="ec-detail-header">

                <div>

                    <h2 class="ec-detail-title">
                        Dados cadastrais
                    </h2>

                    <p class="ec-detail-description">
                        Informações oficiais e cadastrais da empresa.
                    </p>

                </div>

            </div>


            <dl class="ec-data-grid">

                <div class="ec-data-item">

                    <dt>
                        Razão social
                    </dt>

                    <dd>
                        {{ $company->corporate_name }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Nome fantasia
                    </dt>

                    <dd>
                        {{ $this->matrix?->fantasy_name ?: '—' }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        CNPJ raiz
                    </dt>

                    <dd class="font-mono">
                        {{ $company->cnpj_root }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Capital social
                    </dt>

                    <dd>
                        {{ $this->formatMoney($company->share_capital) }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Porte
                    </dt>

                    <dd>

                        {{ $company->size_code ?: '—' }}

                        @if ($company->size_description)

                            <span class="ec-data-muted">
                                — {{ $company->size_description }}
                            </span>

                        @endif

                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Natureza jurídica
                    </dt>

                    <dd>

                        {{ $company->legal_nature_code ?: '—' }}

                        @if ($company->legal_nature_description)

                            <span class="ec-data-muted">
                                — {{ $company->legal_nature_description }}
                            </span>

                        @endif

                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Origem
                    </dt>

                    <dd>
                        <span class="ec-source-badge">
                            {{ ucfirst($company->source) }}
                        </span>
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Última atualização
                    </dt>

                    <dd>
                        {{
                            $company
                                ->source_updated_at
                                ?->format('d/m/Y H:i')
                            ?? '—'
                        }}
                    </dd>

                </div>

            </dl>

        </section>


        {{-- CONTATO CADASTRAL --}}
        <section class="ec-detail-panel">

            <div class="ec-detail-header">

                <div>

                    <h2 class="ec-detail-title">
                        Contato cadastral
                    </h2>

                    <p class="ec-detail-description">
                        Dados públicos do estabelecimento.
                    </p>

                </div>

            </div>


            <div class="ec-contact-list">

                <div class="ec-contact-item">

                    <div class="ec-contact-icon">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            class="size-4"
                        >
                            <path
                                d="M4 4h16v16H4z"
                            />

                            <path
                                d="m4 6 8 6 8-6"
                            />
                        </svg>

                    </div>

                    <div class="min-w-0">

                        <div class="ec-contact-label">
                            E-mail
                        </div>

                        <div class="ec-contact-value">

                            @if ($this->matrix?->email)

                                <a
                                    href="mailto:{{ $this->matrix->email }}"
                                >
                                    {{ $this->matrix->email }}
                                </a>

                            @else
                                —
                            @endif

                        </div>

                    </div>

                </div>


                <div class="ec-contact-item">

                    <div class="ec-contact-icon">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            class="size-4"
                        >
                            <path
                                d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.62 2.63a2 2 0 0 1-.45 2.11L8 9.73a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.85.29 1.73.5 2.63.62A2 2 0 0 1 22 16.92Z"
                            />
                        </svg>

                    </div>

                    <div>

                        <div class="ec-contact-label">
                            Telefone
                        </div>

                        <div class="ec-contact-value">
                            {{ $this->matrix?->phone_1 ?: '—' }}
                        </div>

                    </div>

                </div>


                <div class="ec-contact-item">

                    <div class="ec-contact-icon">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            class="size-4"
                        >
                            <path
                                d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"
                            />

                            <circle
                                cx="12"
                                cy="10"
                                r="2.5"
                            />
                        </svg>

                    </div>

                    <div>

                        <div class="ec-contact-label">
                            Município
                        </div>

                        <div class="ec-contact-value">

                            {{ $this->matrix?->municipality_name ?: '—' }}

                            @if ($this->matrix?->state)
                                / {{ $this->matrix->state }}
                            @endif

                        </div>

                    </div>

                </div>

            </div>


            <div class="ec-contact-note">
                Esses dados ainda não representam um decisor comercial.
                O módulo de contatos fará o enriquecimento posteriormente.
            </div>

        </section>

    </div>


    {{-- ESTABELECIMENTOS --}}
    <section class="ec-table-panel">

        <div class="ec-table-toolbar">

            <div>

                <div class="flex items-center gap-2">

                    <h2 class="ec-table-title">
                        Estabelecimentos
                    </h2>

                    <span class="ec-count-badge">
                        {{ $company->establishments->count() }}
                    </span>

                </div>

                <p class="ec-table-description">
                    Matriz e filiais vinculadas ao mesmo CNPJ raiz.
                </p>

            </div>

            <a
                href="{{ route('companies.branches.create', $company) }}"
                wire:navigate
                class="ec-button-primary"
            >

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    class="size-4"
                >
                    <path d="M12 5v14M5 12h14" />
                </svg>

                Adicionar filial

            </a>

        </div>


        <div class="overflow-x-auto">

            <table class="ec-table">

                <thead>

                    <tr>

                        <th>
                            Tipo
                        </th>

                        <th>
                            CNPJ
                        </th>

                        <th>
                            Nome fantasia
                        </th>

                        <th>
                            Localização
                        </th>

                        <th>
                            Situação
                        </th>

                    </tr>

                </thead>

                <tbody>

                    @forelse (
                        $company->establishments
                        as $establishment
                    )

                        <tr
                            wire:key="establishment-{{ $establishment->id }}"
                        >

                            <td>

                                @if ($establishment->type === 'matrix')

                                    <span class="ec-type-badge ec-type-matrix">
                                        Matriz
                                    </span>

                                @else

                                    <span class="ec-type-badge ec-type-branch">
                                        Filial
                                    </span>

                                @endif

                            </td>


                            <td class="whitespace-nowrap">

                                <span class="ec-table-primary-text font-mono">
                                    {{ Cnpj::format($establishment->cnpj) }}
                                </span>

                            </td>


                            <td>

                                <span class="ec-table-primary-text">
                                    {{ $establishment->fantasy_name ?: '—' }}
                                </span>

                            </td>


                            <td class="whitespace-nowrap">

                                <span class="ec-table-primary-text">
                                    {{ $establishment->municipality_name ?: '—' }}
                                </span>

                                @if ($establishment->state)

                                    <span class="ec-table-muted">
                                        / {{ $establishment->state }}
                                    </span>

                                @endif

                            </td>


                            <td class="whitespace-nowrap">

                                @if (
                                    $establishment->registration_status
                                    === 'ATIVA'
                                )

                                    <span class="ec-status ec-status-active">
                                        <span></span>
                                        Ativa
                                    </span>

                                @elseif (
                                    $establishment->registration_status
                                    === 'SUSPENSA'
                                )

                                    <span class="ec-status ec-status-warning">
                                        <span></span>
                                        Suspensa
                                    </span>

                                @elseif (
                                    $establishment->registration_status
                                )

                                    <span class="ec-status ec-status-inactive">

                                        <span></span>

                                        {{
                                            ucfirst(
                                                mb_strtolower(
                                                    $establishment
                                                        ->registration_status
                                                )
                                            )
                                        }}

                                    </span>

                                @else

                                    <span class="ec-table-muted">
                                        —
                                    </span>

                                @endif

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="5"
                                class="!py-16 text-center"
                            >

                                <div class="ec-empty-title">
                                    Nenhum estabelecimento encontrado
                                </div>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </section>


    {{-- CNAES --}}
    <section class="ec-detail-panel ec-cnae-panel">

        <div class="ec-detail-header ec-cnae-header">

            <div>

                <h2 class="ec-detail-title">
                    CNAEs da matriz
                </h2>

                <p class="ec-detail-description">
                    Atividades econômicas usadas posteriormente no cálculo do ICP.
                </p>

            </div>

            <button
                type="button"
                wire:click="toggleCnaeForm"
                class="{{
                    $showCnaeForm
                        ? 'ec-button-secondary'
                        : 'ec-button-primary'
                }}"
            >

                @if ($showCnaeForm)

                    Cancelar

                @else

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        class="size-4"
                    >
                        <path d="M12 5v14M5 12h14" />
                    </svg>

                    Adicionar CNAE

                @endif

            </button>

        </div>


        {{-- FORMULÁRIO CNAE --}}
        @if ($showCnaeForm)

            <div class="ec-cnae-form">

                <form
                    wire:submit="addCnae"
                    class="space-y-4"
                >

                    <div class="grid gap-4 md:grid-cols-3">

                        <div>

                            <label
                                for="newCnaeCode"
                                class="ec-field-label"
                            >
                                Código CNAE
                            </label>

                            <input
                                id="newCnaeCode"
                                wire:model.blur="newCnaeCode"
                                placeholder="4622200"
                                maxlength="20"
                                class="ec-input"
                            >

                            @error('newCnaeCode')

                                <p class="ec-field-error">
                                    {{ $message }}
                                </p>

                            @enderror

                        </div>


                        <div class="md:col-span-2">

                            <label
                                for="newCnaeDescription"
                                class="ec-field-label"
                            >
                                Descrição
                            </label>

                            <input
                                id="newCnaeDescription"
                                wire:model.blur="newCnaeDescription"
                                placeholder="Ex.: Comércio atacadista de soja"
                                class="ec-input"
                            >

                            @error('newCnaeDescription')

                                <p class="ec-field-error">
                                    {{ $message }}
                                </p>

                            @enderror

                        </div>

                    </div>


                    <label class="ec-checkbox-row">

                        <input
                            type="checkbox"
                            wire:model="newCnaePrimary"
                            class="ec-checkbox"
                        >

                        <span>

                            <strong>
                                CNAE principal
                            </strong>

                            <small>
                                Definir esta atividade como principal da matriz.
                            </small>

                        </span>

                    </label>


                    <div class="flex justify-end">

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="addCnae"
                            class="ec-button-primary"
                        >

                            <span
                                wire:loading.remove
                                wire:target="addCnae"
                            >
                                Salvar CNAE
                            </span>

                            <span
                                wire:loading
                                wire:target="addCnae"
                            >
                                Salvando...
                            </span>

                        </button>

                    </div>

                </form>

            </div>

        @endif


        <div class="ec-cnae-content">

            {{-- PRINCIPAL --}}
            @if ($this->primaryCnae)

                <div class="ec-cnae-block">

                    <div class="ec-cnae-block-label">
                        CNAE principal
                    </div>

                    <div class="ec-cnae-primary">

                        <div>

                            <div class="flex flex-wrap items-center gap-2">

                                <span class="ec-cnae-main-code">
                                    {{ $this->primaryCnae->code }}
                                </span>

                                <span class="ec-primary-badge">
                                    Principal
                                </span>

                            </div>

                            <p class="ec-cnae-main-description">
                                {{
                                    $this->primaryCnae->description
                                    ?: 'Sem descrição'
                                }}
                            </p>

                        </div>

                        <button
                            type="button"
                            wire:click="removeCnae({{ $this->primaryCnae->id }})"
                            wire:confirm="Deseja realmente remover este CNAE da matriz?"
                            class="ec-danger-action"
                        >
                            Remover
                        </button>

                    </div>

                </div>

            @else

                <div class="ec-cnae-empty">

                    <div class="ec-empty-title">
                        Nenhum CNAE principal definido
                    </div>

                    <p class="ec-empty-description">
                        Adicione um CNAE ou torne um CNAE secundário o principal.
                    </p>

                </div>

            @endif


            {{-- SECUNDÁRIOS --}}
            @if ($this->secondaryCnaes->isNotEmpty())

                <div class="ec-cnae-block">

                    <div class="ec-cnae-block-label">
                        CNAEs secundários
                    </div>

                    <div class="ec-cnae-list">

                        @foreach ($this->secondaryCnaes as $cnae)

                            <div
                                wire:key="cnae-secondary-{{ $cnae->id }}"
                                class="ec-cnae-row"
                            >

                                <div>

                                    <div class="ec-cnae-row-code">
                                        {{ $cnae->code }}
                                    </div>

                                    <div class="ec-cnae-row-description">
                                        {{
                                            $cnae->description
                                            ?: 'Sem descrição'
                                        }}
                                    </div>

                                </div>


                                <div class="ec-cnae-actions">

                                    <button
                                        type="button"
                                        wire:click="makeCnaePrimary({{ $cnae->id }})"
                                        class="ec-link-action"
                                    >
                                        Tornar principal
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="removeCnae({{ $cnae->id }})"
                                        wire:confirm="Deseja remover este CNAE da matriz?"
                                        class="ec-danger-action"
                                    >
                                        Remover
                                    </button>

                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            @endif


            @if (
                ! $this->primaryCnae
                && $this->secondaryCnaes->isEmpty()
                && ! $showCnaeForm
            )

                <div class="mt-4 text-center">

                    <button
                        type="button"
                        wire:click="toggleCnaeForm"
                        class="ec-link-action"
                    >
                        + Cadastrar primeiro CNAE
                    </button>

                </div>

            @endif

        </div>

    </section>

</div>
