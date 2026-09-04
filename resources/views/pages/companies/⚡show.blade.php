<?php

use App\Models\Company;
use App\Services\EstablishmentService;
use App\Services\ExportResearchEligibilityService;
use App\Services\ExportResearchQueueService;
use App\Services\SdrScoringService;
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
            'exportIntelligence',
            'exportEvidence',
            'sdrScore',
        ]);

        app(
            SdrScoringService::class
        )->recalculate(
            $this->company
        );

        $this->company->load(
            'sdrScore'
        );
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
            'exportIntelligence',
            'exportEvidence',
            'sdrScore',
        ]);

    unset(
        $this->matrix,
        $this->primaryCnae,
        $this->secondaryCnaes,
    );
}

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     message: string
     * }
     */
    #[Computed]
    public function exportResearchEligibility(): array
    {
        return app(
            ExportResearchEligibilityService::class
        )->evaluate(
            $this->company
        );
    }

    public function exportResearchConfigured(): bool
    {
        if (
            ! (bool) config(
                'prospector.export_research.enabled',
                false
            )
        ) {
            return false;
        }

        /*
         * O provider atual é OpenAI.
         *
         * Quando adicionarmos outros providers,
         * essa verificação poderá ir para uma
         * abstração própria.
         */
        $apiKey =
            config(
                'services.openai.api_key'
            );

        return is_string($apiKey)
            && trim($apiKey) !== '';
    }

    public function exportResearchRunning(): bool
    {
        $status =
            $this->company
                ->exportIntelligence
                ?->research_status;

        return in_array(
            $status,
            [
                'queued',
                'processing',
            ],
            true
        );
    }

    public function researchExports(
        ExportResearchQueueService $queue
    ): void {
        if (
            ! $this->exportResearchConfigured()
        ) {
            $this->addError(
                'exportResearch',
                'A pesquisa externa está '
                .'desativada ou sem provider '
                .'configurado.'
            );

            return;
        }

        $eligibility =
            app(
                ExportResearchEligibilityService::class
            )->evaluate(
                $this->company
            );

        if (
            ! $eligibility[
                'eligible'
            ]
        ) {
            $this->addError(
                'exportResearch',
                $eligibility[
                    'message'
                ]
            );

            return;
        }

        $result =
            $queue->dispatch(
                $this->company
            );

        $this->reloadCompany();

        unset(
            $this->exportResearchEligibility
        );

        if (
            $result->research_status
            === 'queued'
        ) {
            session()->flash(
                'success',
                'Pesquisa de exportação '
                .'enviada para processamento.'
            );
        }
    }

    public function refreshExportResearch(): void
    {
        $this->reloadCompany();

        unset(
            $this->exportResearchEligibility
        );
    }

    public function exportStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'yes' =>
                'Sim',

            'no' =>
                'Não',

            'uncertain' =>
                'Incerto',

            default =>
                'Não pesquisada',
        };
    }

    public function exportStatusClasses(
        ?string $status
    ): string {
        return match ($status) {
            'yes' =>
                'bg-emerald-500/15 '
                .'text-emerald-300',

            'no' =>
                'bg-rose-500/15 '
                .'text-rose-300',

            'uncertain' =>
                'bg-amber-500/15 '
                .'text-amber-300',

            default =>
                'bg-white/5 '
                .'text-[#7f87a7]',
        };
    }

    public function exportDimensionLabel(
        string $dimension
    ): string {
        return match ($dimension) {
            'direct' =>
                'Exportação direta',

            'indirect' =>
                'Exportação indireta',

            'trading' =>
                'Trading',

            default =>
                ucfirst($dimension),
        };
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

    @php
        /*
         * Inteligência de exportação da empresa.
         *
         * Definida no início da view para ficar
         * disponível em todos os cards, painel
         * de pesquisa e bloco de evidências.
         */
        $export =
            $company->exportIntelligence;
    @endphp

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


        @php
            $researchStatus =
                $export?->research_status
                ?? 'idle';

            $researchRunning =
                in_array(
                    $researchStatus,
                    [
                        'queued',
                        'processing',
                    ],
                    true
                );

            $researchConfigured =
                $this
                    ->exportResearchConfigured();

            $researchEligibility =
                $this
                    ->exportResearchEligibility;

            $evidenceCount =
                $company
                    ->exportEvidence
                    ->count();
        @endphp


        {{-- PESQUISA DE EXPORTAÇÃO --}}
        <div
            @if ($researchRunning)
                wire:poll.2s="refreshExportResearch"
            @endif
            class="
                mb-5 overflow-hidden
                rounded-2xl
                border border-white/[0.07]
                bg-white/[0.025]
            "
        >

            <div
                class="
                    flex flex-col gap-4
                    px-5 py-4
                    lg:flex-row
                    lg:items-center
                    lg:justify-between
                "
            >

                <div
                    class="
                        flex min-w-0
                        items-center gap-4
                    "
                >

                    <div
                        class="
                            flex size-11
                            shrink-0
                            items-center
                            justify-center
                            rounded-xl
                            border
                            border-cyan-300/10
                            bg-cyan-300/[0.06]
                            text-cyan-300
                        "
                    >

                        @if ($researchRunning)

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                class="
                                    size-5
                                    animate-spin
                                "
                            >
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="9"
                                    stroke="currentColor"
                                    stroke-opacity=".20"
                                    stroke-width="3"
                                />

                                <path
                                    d="
                                        M21 12
                                        a9 9 0 0 0-9-9
                                    "
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                />
                            </svg>

                        @elseif (
                            $researchStatus
                            === 'completed'
                        )

                            <svg
                                xmlns="
                                    http://www.w3.org/2000/svg
                                "
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                class="
                                    size-5
                                    text-emerald-300
                                "
                            >
                                <path
                                    d="
                                        m5 12
                                        4 4
                                        L19 6
                                    "
                                />
                            </svg>

                        @else

                            <svg
                                xmlns="
                                    http://www.w3.org/2000/svg
                                "
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                class="size-5"
                            >
                                <circle
                                    cx="11"
                                    cy="11"
                                    r="7"
                                />

                                <path
                                    d="m20 20-4-4"
                                />
                            </svg>

                        @endif

                    </div>


                    <div class="min-w-0">

                        <div
                            class="
                                text-[11px]
                                font-semibold
                                uppercase
                                tracking-[0.16em]
                                text-[#737e9f]
                            "
                        >
                            Pesquisa de exportação
                        </div>


                        @if ($researchRunning)

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Pesquisando fontes públicas...
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                O processamento está sendo
                                executado em segundo plano.
                            </div>

                        @elseif (
                            $researchStatus
                            === 'completed'
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-emerald-300
                                "
                            >
                                Pesquisa concluída
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                {{ $evidenceCount }}
                                evidência(s) pública(s)
                                registrada(s).
                            </div>

                        @elseif (
                            $researchStatus
                            === 'failed'
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-rose-300
                                "
                            >
                                Falha na pesquisa
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                {{
                                    $export
                                        ?->research_error
                                    ?: 'Não foi possível concluir.'
                                }}
                            </div>

                        @elseif (! $researchConfigured)

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Pesquisa pública desativada
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                Nenhuma API externa será
                                utilizada até você ativar
                                um provider.
                            </div>

                        @elseif (
                            ! $researchEligibility[
                                'eligible'
                            ]
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Pesquisa automática bloqueada
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                {{
                                    $researchEligibility[
                                        'message'
                                    ]
                                }}
                            </div>

                        @else

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Empresa elegível para pesquisa
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                O Prospector pesquisará
                                exportação direta, indireta
                                e relação com tradings.
                            </div>

                        @endif

                    </div>

                </div>


                @if (
                    $researchConfigured
                    && $researchEligibility[
                        'eligible'
                    ]
                    && ! $researchRunning
                    && $researchStatus
                        !== 'completed'
                )

                    <button
                        type="button"
                        wire:click="researchExports"
                        wire:loading.attr="disabled"
                        wire:target="researchExports"
                        class="ec-button-primary"
                    >

                        <span
                            wire:loading.remove
                            wire:target="researchExports"
                        >
                            Pesquisar exportações
                        </span>

                        <span
                            wire:loading
                            wire:target="researchExports"
                            class="
                                inline-flex
                                items-center gap-2
                            "
                        >
                            <span
                                class="
                                    size-3.5
                                    animate-spin
                                    rounded-full
                                    border-2
                                    border-current/20
                                    border-t-current
                                "
                            ></span>

                            Enviando...
                        </span>

                    </button>

                @elseif (! $researchConfigured)

                    <span
                        class="
                            rounded-full
                            border border-white/[0.06]
                            bg-white/[0.03]
                            px-3 py-1.5
                            text-xs
                            font-medium
                            text-[#7f87a7]
                        "
                    >
                        Provider desativado
                    </span>

                @endif

            </div>

        </div>


        @error('exportResearch')

            <div
                class="
                    mb-5 rounded-xl
                    border border-amber-400/15
                    bg-amber-400/[0.06]
                    px-4 py-3
                    text-sm
                    text-amber-200
                "
            >
                {{ $message }}
            </div>

        @enderror


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

                    <span
                        class="
                            rounded-full
                            px-2 py-1
                            text-[10px]
                            font-bold
                            uppercase
                            {{
                                $this
                                    ->exportStatusClasses(
                                        $export
                                            ?->direct_status
                                    )
                            }}
                        "
                    >
                        {{
                            $this
                                ->exportStatusLabel(
                                    $export
                                        ?->direct_status
                                )
                        }}
                    </span>

                </div>

                <div class="ec-intelligence-value">
                    {{
                        $this
                            ->exportStatusLabel(
                                $export
                                    ?->direct_status
                            )
                    }}
                </div>

                <div class="ec-intelligence-caption">

                    @if (
                        $export
                        && $export->direct_status
                            !== 'not_researched'
                    )

                        {{
                            $export
                                ->direct_confidence
                        }}% de confiança

                        @if (
                            $export
                                ->direct_confirmed
                        )
                            · Confirmado
                        @endif

                    @else
                        Exportação própria
                    @endif

                </div>

            </div>


            {{-- INDIRETA --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Exp. indireta
                    </span>

                    <span
                        class="
                            rounded-full
                            px-2 py-1
                            text-[10px]
                            font-bold
                            uppercase
                            {{
                                $this
                                    ->exportStatusClasses(
                                        $export
                                            ?->indirect_status
                                    )
                            }}
                        "
                    >
                        {{
                            $this
                                ->exportStatusLabel(
                                    $export
                                        ?->indirect_status
                                )
                        }}
                    </span>

                </div>

                <div class="ec-intelligence-value">
                    {{
                        $this
                            ->exportStatusLabel(
                                $export
                                    ?->indirect_status
                            )
                    }}
                </div>

                <div class="ec-intelligence-caption">

                    @if (
                        $export
                        && $export->indirect_status
                            !== 'not_researched'
                    )

                        {{
                            $export
                                ->indirect_confidence
                        }}% de confiança

                        @if (
                            $export
                                ->indirect_confirmed
                        )
                            · Confirmado
                        @endif

                    @else
                        Fim específico
                    @endif

                </div>

            </div>


            {{-- TRADING --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Trading
                    </span>

                    <span
                        class="
                            rounded-full
                            px-2 py-1
                            text-[10px]
                            font-bold
                            uppercase
                            {{
                                $this
                                    ->exportStatusClasses(
                                        $export
                                            ?->trading_status
                                    )
                            }}
                        "
                    >
                        {{
                            $this
                                ->exportStatusLabel(
                                    $export
                                        ?->trading_status
                                )
                        }}
                    </span>

                </div>

                <div class="ec-intelligence-value">
                    {{
                        $this
                            ->exportStatusLabel(
                                $export
                                    ?->trading_status
                            )
                    }}
                </div>

                <div class="ec-intelligence-caption">

                    @if (
                        $export
                        && $export->trading_status
                            !== 'not_researched'
                    )

                        {{
                            $export
                                ->trading_confidence
                        }}% de confiança

                        @if (
                            $export
                                ->trading_confirmed
                        )
                            · Confirmado
                        @endif

                    @else
                        Relação comercial
                    @endif

                </div>

            </div>


            {{-- SCORE --}}
            @php
                $sdr =
                    $company->sdrScore;
            @endphp

            <div class="ec-intelligence-card ec-intelligence-score">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Score
                    </span>

                    @if ($sdr)

                        <span
                            class="
                                rounded-full
                                px-2 py-1
                                text-[10px]
                                font-bold
                                uppercase
                                {{
                                    match ($sdr->priority) {
                                        'very_high' =>
                                            'bg-emerald-500/15 text-emerald-300',

                                        'high' =>
                                            'bg-cyan-500/15 text-cyan-300',

                                        'medium' =>
                                            'bg-amber-500/15 text-amber-300',

                                        'blocked' =>
                                            'bg-rose-500/15 text-rose-300',

                                        default =>
                                            'bg-white/5 text-[#8e97b8]',
                                    }
                                }}
                            "
                        >
                            @if (! $sdr->is_eligible)
                                Bloqueado
                            @elseif ($sdr->is_provisional)
                                Provisório
                            @else
                                SDR
                            @endif
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                @if (
                    $sdr
                    && ! $sdr->is_eligible
                )

                    <div
                        class="
                            mt-3
                            text-base
                            font-bold
                            text-rose-300
                        "
                    >
                        Não priorizar
                    </div>

                    <div class="ec-intelligence-caption">
                        {{
                            $sdr->blocked_reason
                            ?: 'Bloqueio comercial'
                        }}
                    </div>

                @elseif ($sdr)

                    <div class="ec-score-value">
                        {{ $sdr->score }}/100
                    </div>

                    <div class="ec-intelligence-caption">
                        {{ $sdr->label }}

                        @if ($sdr->is_provisional)
                            · Provisório
                        @endif
                    </div>

                @else

                    <div class="ec-score-value">
                        —
                    </div>

                    <div class="ec-intelligence-caption">
                        Prioridade SDR
                    </div>

                @endif

            </div>

        </div>

        {{-- EVIDÊNCIAS DE EXPORTAÇÃO --}}
        @if (
            $company
                ->exportEvidence
                ->isNotEmpty()
        )

            <details
                class="
                    mt-4 overflow-hidden
                    rounded-xl
                    border border-white/5
                    bg-white/[0.025]
                "
            >

                <summary
                    class="
                        flex cursor-pointer
                        list-none
                        items-center
                        justify-between
                        px-5 py-4
                        text-sm
                        font-semibold
                        text-[#d9ddef]
                        transition
                        hover:bg-white/[0.025]
                    "
                >

                    <span>
                        Evidências de exportação
                    </span>

                    <span
                        class="
                            text-xs
                            font-medium
                            text-[#7f87a7]
                        "
                    >
                        {{
                            $company
                                ->exportEvidence
                                ->count()
                        }}
                        fonte(s)
                    </span>

                </summary>


                <div
                    class="
                        border-t border-white/5
                        p-5
                    "
                >

                    <div class="space-y-3">

                        @foreach (
                            $company
                                ->exportEvidence
                                ->sortByDesc(
                                    'created_at'
                                )
                            as $evidence
                        )

                            <div
                                class="
                                    rounded-xl
                                    border
                                    border-white/[0.06]
                                    bg-[#171d3c]/45
                                    p-4
                                "
                            >

                                <div
                                    class="
                                        flex flex-col
                                        gap-3
                                        sm:flex-row
                                        sm:items-start
                                        sm:justify-between
                                    "
                                >

                                    <div class="min-w-0">

                                        <div
                                            class="
                                                flex flex-wrap
                                                items-center
                                                gap-2
                                            "
                                        >

                                            <span
                                                class="
                                                    rounded-full
                                                    bg-cyan-300/10
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-bold
                                                    uppercase
                                                    text-cyan-300
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->exportDimensionLabel(
                                                            $evidence
                                                                ->dimension
                                                        )
                                                }}
                                            </span>

                                            <span
                                                class="
                                                    text-xs
                                                    font-semibold
                                                    text-[#aeb6d1]
                                                "
                                            >
                                                {{
                                                    strtoupper(
                                                        $evidence
                                                            ->signal
                                                    )
                                                }}

                                                ·

                                                {{
                                                    $evidence
                                                        ->confidence
                                                }}%
                                            </span>

                                        </div>


                                        <div
                                            class="
                                                mt-2
                                                text-sm
                                                font-semibold
                                                text-[#eef1ff]
                                            "
                                        >
                                            {{
                                                $evidence
                                                    ->title
                                                ?: (
                                                    $evidence
                                                        ->source_name
                                                    ?: 'Evidência pública'
                                                )
                                            }}
                                        </div>


                                        <div
                                            class="
                                                mt-1
                                                text-xs
                                                leading-5
                                                text-[#929bbb]
                                            "
                                        >
                                            {{
                                                $evidence
                                                    ->evidence_text
                                            }}
                                        </div>


                                        <div
                                            class="
                                                mt-2
                                                text-[11px]
                                                text-[#697598]
                                            "
                                        >
                                            Fonte:

                                            {{
                                                $evidence
                                                    ->source_name
                                                ?: $evidence
                                                    ->source_type
                                            }}
                                        </div>

                                    </div>


                                    @if (
                                        $evidence
                                            ->source_url
                                    )

                                        <a
                                            href="{{
                                                $evidence
                                                    ->source_url
                                            }}"
                                            target="_blank"
                                            rel="
                                                noopener
                                                noreferrer
                                            "
                                            class="
                                                inline-flex
                                                shrink-0
                                                items-center
                                                gap-1
                                                text-xs
                                                font-semibold
                                                text-cyan-300
                                                hover:text-cyan-200
                                            "
                                        >
                                            Abrir fonte ↗
                                        </a>

                                    @endif

                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            </details>

        @endif


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
                                Lifecycle HubSpot (informativo)
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

                    {{-- NEGÓCIOS HUBSPOT --}}
                    @php
                        $crmDeals =
                            data_get(
                                $crm->metadata,
                                'deals',
                                []
                            );

                        $dealSummary =
                            data_get(
                                $crm->metadata,
                                'deal_summary',
                                []
                            );

                        $crmDeals =
                            is_array($crmDeals)
                                ? $crmDeals
                                : [];
                    @endphp

                    @if ($crmDeals !== [])

                        <div
                            class="
                                mt-5 border-t
                                border-white/5
                                pt-5
                            "
                        >

                            <div
                                class="
                                    flex flex-col gap-2
                                    sm:flex-row
                                    sm:items-center
                                    sm:justify-between
                                "
                            >

                                <div>

                                    <div
                                        class="
                                            text-sm
                                            font-semibold
                                            text-[#eef1ff]
                                        "
                                    >
                                        Negócios HubSpot
                                    </div>

                                    <div
                                        class="
                                            mt-0.5 text-xs
                                            text-[#7f87a7]
                                        "
                                    >
                                        Negócios associados
                                        usados para classificar
                                        o status comercial.
                                    </div>

                                </div>

                                <div
                                    class="
                                        text-xs
                                        font-medium
                                        text-[#8f99bb]
                                    "
                                >
                                    {{
                                        data_get(
                                            $dealSummary,
                                            'active',
                                            0
                                        )
                                    }}
                                    ativo(s)

                                    ·

                                    {{
                                        data_get(
                                            $dealSummary,
                                            'won',
                                            0
                                        )
                                    }}
                                    ganho(s)

                                    ·

                                    {{
                                        data_get(
                                            $dealSummary,
                                            'closed_lost',
                                            0
                                        )
                                    }}
                                    encerrado(s)
                                </div>

                            </div>


                            <div
                                class="
                                    mt-4 grid gap-3
                                    lg:grid-cols-2
                                "
                            >

                                @foreach (
                                    $crmDeals
                                    as $deal
                                )

                                    @php
                                        $dealWon =
                                            (bool) (
                                                $deal[
                                                    'is_closed_won'
                                                ]
                                                ?? false
                                            );

                                        $dealClosed =
                                            (bool) (
                                                $deal[
                                                    'is_closed'
                                                ]
                                                ?? false
                                            );

                                        $dealState =
                                            $dealWon
                                                ? 'Ganho'
                                                : (
                                                    $dealClosed
                                                        ? 'Encerrado'
                                                        : 'Ativo'
                                                );

                                        $dealStateClasses =
                                            $dealWon
                                                ? 'bg-emerald-500/15 text-emerald-300'
                                                : (
                                                    $dealClosed
                                                        ? 'bg-rose-500/15 text-rose-300'
                                                        : 'bg-amber-500/15 text-amber-300'
                                                );
                                    @endphp

                                    <div
                                        class="
                                            rounded-xl
                                            border
                                            border-white/[0.06]
                                            bg-white/[0.025]
                                            p-4
                                        "
                                    >

                                        <div
                                            class="
                                                flex items-start
                                                justify-between
                                                gap-3
                                            "
                                        >

                                            <div
                                                class="
                                                    min-w-0
                                                "
                                            >

                                                <div
                                                    class="
                                                        truncate
                                                        text-sm
                                                        font-semibold
                                                        text-[#eef1ff]
                                                    "
                                                    title="{{
                                                        $deal[
                                                            'name'
                                                        ]
                                                        ?? 'Negócio sem nome'
                                                    }}"
                                                >
                                                    {{
                                                        $deal[
                                                            'name'
                                                        ]
                                                        ?? 'Negócio sem nome'
                                                    }}
                                                </div>

                                                <div
                                                    class="
                                                        mt-1
                                                        text-xs
                                                        text-[#8f99bb]
                                                    "
                                                >
                                                    Etapa:

                                                    <span
                                                        class="
                                                            text-[#c8cee4]
                                                        "
                                                    >
                                                        {{
                                                            $deal[
                                                                'stage_label'
                                                            ]
                                                            ?? $deal[
                                                                'stage_id'
                                                            ]
                                                            ?? '—'
                                                        }}
                                                    </span>
                                                </div>

                                            </div>


                                            <span
                                                class="
                                                    shrink-0
                                                    rounded-full
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-bold
                                                    uppercase
                                                    {{
                                                        $dealStateClasses
                                                    }}
                                                "
                                            >
                                                {{
                                                    $dealState
                                                }}
                                            </span>

                                        </div>

                                    </div>

                                @endforeach

                            </div>

                        </div>

                    @endif


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
