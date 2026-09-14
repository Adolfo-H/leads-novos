<?php

use App\Services\ProspectingEngineService;
use App\Services\ProspectingRunSummaryService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $limit = 20;

    /**
     * @var list<string>
     */
    public array $selectedStates = [
        'MA',
        'SP',
        'PA',
        'RO',
        'MT',
        'MS',
        'TO',
        'GO',
        'MG',
    ];

    /**
     * @var list<string>
     */
    public array $selectedCnaes = [
        '4622200',
        '4632001',
        '0115600',
        '0111302',
        '1071600',
    ];

    /**
     * @var array<string, string>
     */
    public array $stateOptions = [
        'MA' => 'Maranhão',
        'SP' => 'São Paulo',
        'PA' => 'Pará',
        'RO' => 'Rondônia',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'TO' => 'Tocantins',
        'GO' => 'Goiás',
        'MG' => 'Minas Gerais',
    ];

    /**
     * @var array<string, string>
     */
    public array $cnaeOptions = [
        '4622200' => 'Comércio atacadista de soja',
        '4632001' => 'Comércio atacadista de cereais',
        '0115600' => 'Cultivo de soja',
        '0111302' => 'Cultivo de milho',
        '1071600' => 'Fabricação de açúcar em bruto',
    ];

    /**
     * @var list<array<string, mixed>>
     */
    public array $prospects = [];

    public ?string $error = null;

    public bool $searched = false;

    public int $discoveredCount = 0;

    public int $knownCount = 0;

    public int $newCount = 0;

    public bool $confirmingExecution = false;

    public ?string $executionMessage = null;

    public ?string $executedBatchUuid = null;

    public int $dispatchedCount = 0;

    public function selectAllStates(): void
    {
        $this->selectedStates =
            array_keys(
                $this->stateOptions
            );
    }

    public function clearStates(): void
    {
        $this->selectedStates = [];
    }

    public function selectAllCnaes(): void
    {
        $this->selectedCnaes =
            array_keys(
                $this->cnaeOptions
            );
    }

    public function clearCnaes(): void
    {
        $this->selectedCnaes = [];
    }

    public function search(
        ProspectingEngineService $engine
    ): void {
        $this->validateFilters();

        $this->error = null;

        $this->executionMessage = null;

        $this->executedBatchUuid = null;

        $this->dispatchedCount = 0;

        $this->confirmingExecution = false;

        try {
            $this->loadPreview(
                $engine
            );
        } catch (\Throwable $exception) {
            $this->prospects = [];

            $this->searched = true;

            $this->error =
                $exception->getMessage();
        }
    }

    public function confirmExecution(): void
    {
        if ($this->prospects === []) {
            return;
        }

        $this->confirmingExecution = true;
    }

    public function cancelExecution(): void
    {
        $this->confirmingExecution = false;
    }

    public function executeProspecting(
        ProspectingEngineService $engine
    ): void {
        $this->validateFilters();

        if (! $this->confirmingExecution) {
            return;
        }

        $this->error = null;

        try {
            $authId =
                auth()->id();

            $result =
                $engine->execute(
                    limit: $this->limit,
                    states: array_values(
                        $this->selectedStates
                    ),
                    cnaes: array_values(
                        $this->selectedCnaes
                    ),
                    userId: is_numeric(
                        $authId
                    )
                        ? (int) $authId
                        : null,
                );

            $batch =
                $result[
                    'batch'
                ];

            $this->confirmingExecution =
                false;

            if ($batch === null) {
                $this->executionMessage =
                    'Nenhuma empresa nova estava disponível para processamento.';

                $this->executedBatchUuid =
                    null;

                $this->dispatchedCount =
                    0;
            } else {
                $this->executedBatchUuid =
                    (string) $batch->uuid;

                $this->dispatchedCount =
                    $result[
                        'dispatched'
                    ];

                $this->executionMessage =
                    $this->dispatchedCount === 1
                        ? '1 empresa enviada para qualificação.'
                        : $this->dispatchedCount
                            .' empresas enviadas para qualificação.';
            }

            /*
             * Depois da execução, atualizamos
             * a tela para mostrar o próximo
             * conjunto de empresas inéditas.
             */
            $this->loadPreview(
                $engine
            );

        } catch (\Throwable $exception) {
            $this->confirmingExecution =
                false;

            $this->error =
                $exception->getMessage();
        }
    }

    private function loadPreview(
        ProspectingEngineService $engine
    ): void {
        $result =
            $engine->preview(
                limit: $this->limit,
                states: array_values(
                    $this->selectedStates
                ),
                cnaes: array_values(
                    $this->selectedCnaes
                ),
            );

        $this->prospects =
            $result[
                'items'
            ];

        $this->discoveredCount =
            $result[
                'discovered_count'
            ];

        $this->knownCount =
            $result[
                'known_count'
            ];

        $this->newCount =
            $result[
                'new_count'
            ];

        $this->searched = true;
    }

    private function validateFilters(): void
    {
        $this->validate([
            'limit' => [
                'required',
                'integer',
                'min:1',
                'max:200',
            ],

            'selectedStates' => [
                'required',
                'array',
                'min:1',
            ],

            'selectedCnaes' => [
                'required',
                'array',
                'min:1',
            ],
        ], [
            'selectedStates.min' =>
                'Selecione pelo menos um estado.',

            'selectedCnaes.min' =>
                'Selecione pelo menos um CNAE.',
        ]);
    }

    #[Computed]
    public function recentRuns(): array
    {
        return app(
            ProspectingRunSummaryService::class
        )->recent(
            10
        );
    }

    public function runStatusLabel(
        string $status
    ): string {
        return match ($status) {
            'completed' =>
                'Concluído',

            'processing' =>
                'Processando',

            'failed' =>
                'Falhou',

            'ready' =>
                'Pronto',

            default =>
                ucfirst($status),
        };
    }

    public function capital(
        mixed $value
    ): string {
        if (! is_numeric($value)) {
            return '—';
        }

        return 'R$ '
            .number_format(
                (float) $value,
                0,
                ',',
                '.'
            );
    }
};
?>

<div class="ec-page-shell">

    {{-- CABEÇALHO --}}
    <div class="ec-page-header">

        <div>

            <div class="ec-page-kicker">
                Descoberta Comercial
            </div>

            <h1 class="ec-page-title mt-1">
                Motor de Prospecção
            </h1>

            <p class="ec-page-description">
                Descubra empresas diretamente na base nacional
                da Receita antes de enviá-las para qualificação.
            </p>

        </div>

        <div
            class="
                hidden rounded-xl
                border border-cyan-300/15
                bg-cyan-300/[0.04]
                px-4 py-2
                text-xs text-cyan-300
                md:block
            "
        >
            Pré-visualização segura
        </div>

    </div>


    {{-- CONFIGURAÇÃO --}}
    <div
        class="
            mt-5 rounded-2xl
            border border-white/[0.06]
            bg-white/[0.025]
            p-5
        "
    >

        <div
            class="
                flex flex-wrap items-start
                justify-between gap-3
            "
        >

            <div>

                <h2
                    class="
                        text-sm font-semibold
                        text-[#eef1ff]
                    "
                >
                    Configurar descoberta
                </h2>

                <p
                    class="
                        mt-1 text-xs
                        text-[#7781a2]
                    "
                >
                    Escolha o volume e os segmentos
                    que o motor deve pesquisar.
                </p>

            </div>

            <div
                class="
                    rounded-lg
                    bg-white/[0.03]
                    px-3 py-2
                    text-[11px]
                    text-[#7781a2]
                "
            >
                Nenhuma pesquisa externa é executada nesta etapa.
            </div>

        </div>


        <form
            wire:submit="search"
            class="mt-6 space-y-6"
        >

            {{-- QUANTIDADE --}}
            <div>

                <div
                    class="
                        mb-3 text-xs
                        font-semibold
                        uppercase
                        tracking-wide
                        text-[#697394]
                    "
                >
                    Quantidade de candidatos
                </div>

                <div
                    class="
                        grid max-w-xl
                        grid-cols-4 gap-2
                    "
                >

                    @foreach ([10, 20, 50, 100] as $quantity)

                        <label
                            class="
                                cursor-pointer
                                rounded-xl border
                                px-4 py-3
                                text-center text-sm
                                font-semibold
                                transition
                                {{
                                    $limit === $quantity
                                        ? 'border-cyan-300/40 bg-cyan-300/10 text-cyan-300'
                                        : 'border-white/[0.07] bg-white/[0.025] text-[#aab2cf] hover:bg-white/[0.045]'
                                }}
                            "
                        >

                            <input
                                type="radio"
                                wire:model.live="limit"
                                value="{{ $quantity }}"
                                class="sr-only"
                            >

                            {{ $quantity }}

                        </label>

                    @endforeach

                </div>

            </div>


            {{-- ESTADOS --}}
            <div>

                <div
                    class="
                        mb-3 flex flex-wrap
                        items-center justify-between
                        gap-3
                    "
                >

                    <div>

                        <div
                            class="
                                text-xs font-semibold
                                uppercase tracking-wide
                                text-[#697394]
                            "
                        >
                            Estados
                        </div>

                        <div
                            class="
                                mt-1 text-xs
                                text-[#7781a2]
                            "
                        >
                            {{
                                count(
                                    $selectedStates
                                )
                            }}
                            selecionados
                        </div>

                    </div>

                    <div class="flex gap-3">

                        <button
                            type="button"
                            wire:click="selectAllStates"
                            class="
                                text-xs font-semibold
                                text-cyan-300
                                hover:text-cyan-200
                            "
                        >
                            Selecionar todos
                        </button>

                        <button
                            type="button"
                            wire:click="clearStates"
                            class="
                                text-xs font-semibold
                                text-[#8992af]
                                hover:text-white
                            "
                        >
                            Limpar
                        </button>

                    </div>

                </div>


                <div
                    class="
                        grid gap-2
                        sm:grid-cols-2
                        md:grid-cols-3
                        xl:grid-cols-5
                    "
                >

                    @foreach (
                        $stateOptions
                        as $uf => $stateName
                    )

                        <label
                            class="
                                flex cursor-pointer
                                items-center gap-3
                                rounded-xl border
                                px-3 py-3
                                transition
                                {{
                                    in_array(
                                        $uf,
                                        $selectedStates,
                                        true
                                    )
                                        ? 'border-cyan-300/30 bg-cyan-300/[0.07]'
                                        : 'border-white/[0.06] bg-white/[0.02] hover:bg-white/[0.04]'
                                }}
                            "
                        >

                            <input
                                type="checkbox"
                                wire:model.live="selectedStates"
                                value="{{ $uf }}"
                                class="
                                    rounded border-white/20
                                    bg-transparent
                                    text-cyan-400
                                    focus:ring-cyan-400
                                "
                            >

                            <div>

                                <div
                                    class="
                                        text-sm font-bold
                                        {{
                                            in_array(
                                                $uf,
                                                $selectedStates,
                                                true
                                            )
                                                ? 'text-cyan-300'
                                                : 'text-[#d9ddef]'
                                        }}
                                    "
                                >
                                    {{ $uf }}
                                </div>

                                <div
                                    class="
                                        mt-0.5 truncate
                                        text-[10px]
                                        text-[#707998]
                                    "
                                >
                                    {{ $stateName }}
                                </div>

                            </div>

                        </label>

                    @endforeach

                </div>

                @error('selectedStates')
                    <div
                        class="
                            mt-2 text-xs
                            text-red-300
                        "
                    >
                        {{ $message }}
                    </div>
                @enderror

            </div>


            {{-- CNAES --}}
            <div>

                <div
                    class="
                        mb-3 flex flex-wrap
                        items-center justify-between
                        gap-3
                    "
                >

                    <div>

                        <div
                            class="
                                text-xs font-semibold
                                uppercase tracking-wide
                                text-[#697394]
                            "
                        >
                            Atividades prioritárias
                        </div>

                        <div
                            class="
                                mt-1 text-xs
                                text-[#7781a2]
                            "
                        >
                            {{
                                count(
                                    $selectedCnaes
                                )
                            }}
                            CNAEs selecionados
                        </div>

                    </div>


                    <div class="flex gap-3">

                        <button
                            type="button"
                            wire:click="selectAllCnaes"
                            class="
                                text-xs font-semibold
                                text-cyan-300
                                hover:text-cyan-200
                            "
                        >
                            Selecionar todos
                        </button>

                        <button
                            type="button"
                            wire:click="clearCnaes"
                            class="
                                text-xs font-semibold
                                text-[#8992af]
                                hover:text-white
                            "
                        >
                            Limpar
                        </button>

                    </div>

                </div>


                <div
                    class="
                        grid gap-2
                        md:grid-cols-2
                        xl:grid-cols-3
                    "
                >

                    @foreach (
                        $cnaeOptions
                        as $code => $description
                    )

                        <label
                            class="
                                flex cursor-pointer
                                items-start gap-3
                                rounded-xl border
                                px-4 py-3
                                transition
                                {{
                                    in_array(
                                        $code,
                                        $selectedCnaes,
                                        true
                                    )
                                        ? 'border-cyan-300/30 bg-cyan-300/[0.07]'
                                        : 'border-white/[0.06] bg-white/[0.02] hover:bg-white/[0.04]'
                                }}
                            "
                        >

                            <input
                                type="checkbox"
                                wire:model.live="selectedCnaes"
                                value="{{ $code }}"
                                class="
                                    mt-0.5 rounded
                                    border-white/20
                                    bg-transparent
                                    text-cyan-400
                                    focus:ring-cyan-400
                                "
                            >

                            <div>

                                <div
                                    class="
                                        text-xs font-bold
                                        text-cyan-300
                                    "
                                >
                                    {{ $code }}
                                </div>

                                <div
                                    class="
                                        mt-1 text-xs
                                        leading-5
                                        text-[#9ba4c2]
                                    "
                                >
                                    {{ $description }}
                                </div>

                            </div>

                        </label>

                    @endforeach

                </div>

                @error('selectedCnaes')
                    <div
                        class="
                            mt-2 text-xs
                            text-red-300
                        "
                    >
                        {{ $message }}
                    </div>
                @enderror

            </div>


            {{-- AÇÃO --}}
            <div
                class="
                    flex items-center
                    justify-between gap-4
                    border-t
                    border-white/[0.06]
                    pt-5
                "
            >

                <div
                    class="
                        text-xs
                        text-[#697394]
                    "
                >
                    Busca local na base Receita 2026-08.
                </div>

                <flux:button
                    type="submit"
                    variant="primary"
                    icon="magnifying-glass"
                    wire:loading.attr="disabled"
                    wire:target="search"
                >
                    <span wire:loading.remove wire:target="search">
                        Buscar prospects
                    </span>

                    <span wire:loading wire:target="search">
                        Buscando...
                    </span>
                </flux:button>

            </div>

        </form>

    </div>


    {{-- ERRO --}}
    @if ($error)

        <div
            class="
                mt-5 rounded-2xl
                border border-red-400/20
                bg-red-400/5
                px-5 py-4
                text-sm text-red-200
            "
        >
            {{ $error }}
        </div>

    @endif


    @if ($executionMessage)

        <div
            class="
                mt-5 flex flex-wrap
                items-center justify-between
                gap-4 rounded-2xl
                border border-emerald-400/20
                bg-emerald-400/[0.05]
                px-5 py-4
            "
        >

            <div>

                <div
                    class="
                        text-sm font-semibold
                        text-emerald-300
                    "
                >
                    Prospecção iniciada
                </div>

                <div
                    class="
                        mt-1 text-xs
                        text-[#9ba4c2]
                    "
                >
                    {{ $executionMessage }}

                    @if ($executedBatchUuid)
                        Lote:
                        {{ $executedBatchUuid }}
                    @endif
                </div>

            </div>

            <a
                href="{{ route('imports.index') }}"
                wire:navigate
                class="
                    text-xs font-semibold
                    text-emerald-300
                    hover:text-emerald-200
                "
            >
                Acompanhar processamento →
            </a>

        </div>

    @endif


    {{-- RESULTADOS --}}
    @if ($searched)

        <section
            class="
                mt-5 overflow-hidden
                rounded-2xl
                border border-white/[0.06]
                bg-white/[0.02]
            "
        >

            <div
                class="
                    flex flex-wrap
                    items-center justify-between
                    gap-3
                    border-b
                    border-white/[0.06]
                    px-5 py-4
                "
            >

                <div>

                    <div
                        class="
                            text-sm font-semibold
                            text-[#eef1ff]
                        "
                    >
                        Prospects encontrados
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            text-[#7781a2]
                        "
                    >
                        {{ count($prospects) }}
                        candidatos nesta pré-visualização
                    </div>

                </div>


                @if ($prospects !== [])

                    <flux:button
                        type="button"
                        variant="primary"
                        wire:click="confirmExecution"
                    >
                        Executar prospecção
                    </flux:button>

                @endif

            </div>


            <div
                class="
                    grid gap-3
                    border-b border-white/[0.06]
                    bg-white/[0.015]
                    px-5 py-4
                    sm:grid-cols-3
                "
            >

                <div>

                    <div
                        class="
                            text-[10px] font-semibold
                            uppercase tracking-wide
                            text-[#697394]
                        "
                    >
                        Descobertos
                    </div>

                    <div
                        class="
                            mt-1 text-lg font-bold
                            text-[#eef1ff]
                        "
                    >
                        {{ $discoveredCount }}
                    </div>

                </div>

                <div>

                    <div
                        class="
                            text-[10px] font-semibold
                            uppercase tracking-wide
                            text-[#697394]
                        "
                    >
                        Já trabalhados
                    </div>

                    <div
                        class="
                            mt-1 text-lg font-bold
                            text-[#aab2cf]
                        "
                    >
                        {{ $knownCount }}
                    </div>

                </div>

                <div>

                    <div
                        class="
                            text-[10px] font-semibold
                            uppercase tracking-wide
                            text-[#697394]
                        "
                    >
                        Novos disponíveis
                    </div>

                    <div
                        class="
                            mt-1 text-lg font-bold
                            text-cyan-300
                        "
                    >
                        {{ $newCount }}
                    </div>

                </div>

            </div>


            @if (
                $confirmingExecution
                && $prospects !== []
            )

                <div
                    class="
                        border-b border-amber-300/15
                        bg-amber-300/[0.04]
                        px-5 py-5
                    "
                >

                    <div
                        class="
                            flex flex-col gap-4
                            lg:flex-row
                            lg:items-center
                            lg:justify-between
                        "
                    >

                        <div>

                            <div
                                class="
                                    text-sm font-semibold
                                    text-amber-200
                                "
                            >
                                Confirmar execução
                            </div>

                            <p
                                class="
                                    mt-1 max-w-3xl
                                    text-xs leading-5
                                    text-[#aab2cf]
                                "
                            >
                                Até
                                {{ count($prospects) }}
                                empresas serão enviadas para o pipeline.
                                O sistema consultará Receita, calculará
                                ICP, verificará o HubSpot e, quando a
                                empresa for elegível, poderá executar
                                pesquisa pública de exportação.
                            </p>

                        </div>


                        <div
                            class="
                                flex shrink-0
                                items-center gap-2
                            "
                        >

                            <flux:button
                                type="button"
                                variant="ghost"
                                wire:click="cancelExecution"
                                wire:loading.attr="disabled"
                                wire:target="executeProspecting"
                            >
                                Cancelar
                            </flux:button>

                            <flux:button
                                type="button"
                                variant="primary"
                                wire:click="executeProspecting"
                                wire:loading.attr="disabled"
                                wire:target="executeProspecting"
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="executeProspecting"
                                >
                                    Confirmar e executar
                                </span>

                                <span
                                    wire:loading
                                    wire:target="executeProspecting"
                                >
                                    Enviando...
                                </span>
                            </flux:button>

                        </div>

                    </div>

                </div>

            @endif


            @forelse ($prospects as $prospect)

                <div
                    class="
                        grid gap-4
                        border-b
                        border-white/[0.05]
                        px-5 py-4
                        last:border-b-0
                        hover:bg-white/[0.02]
                        xl:grid-cols-[minmax(0,2fr)_70px_110px_110px_90px_150px]
                        xl:items-center
                    "
                >

                    {{-- EMPRESA --}}
                    <div class="min-w-0">

                        <div
                            class="
                                truncate
                                text-sm font-semibold
                                text-[#eef1ff]
                            "
                        >
                            {{
                                $prospect[
                                    'corporate_name'
                                ]
                            }}
                        </div>

                        <div
                            class="
                                mt-1 text-xs
                                text-[#7781a2]
                            "
                        >
                            {{
                                $prospect[
                                    'cnpj'
                                ]
                            }}
                        </div>

                    </div>


                    {{-- UF --}}
                    <div>

                        <div
                            class="
                                text-[10px]
                                font-semibold
                                uppercase
                                tracking-wide
                                text-[#697394]
                            "
                        >
                            UF
                        </div>

                        <div
                            class="
                                mt-1 text-sm
                                font-bold
                                text-[#e5e9fa]
                            "
                        >
                            {{
                                $prospect[
                                    'state'
                                ]
                                ?? '—'
                            }}
                        </div>

                    </div>


                    {{-- CNAE --}}
                    <div>

                        <div
                            class="
                                text-[10px]
                                font-semibold
                                uppercase
                                tracking-wide
                                text-[#697394]
                            "
                        >
                            CNAE
                        </div>

                        <div
                            class="
                                mt-1 text-xs
                                font-semibold
                                text-[#cbd1e7]
                            "
                        >
                            {{
                                $prospect[
                                    'matched_cnae'
                                ]
                                ?? '—'
                            }}
                        </div>

                        <div
                            class="
                                mt-0.5 text-[10px]
                                text-[#697394]
                            "
                        >
                            {{
                                (
                                    $prospect[
                                        'cnae_match_type'
                                    ]
                                    ?? null
                                ) === 'primary'
                                    ? 'Principal'
                                    : 'Secundário'
                            }}
                        </div>

                    </div>


                    {{-- SCORE --}}
                    <div>

                        <div
                            class="
                                text-[10px]
                                font-semibold
                                uppercase
                                tracking-wide
                                text-[#697394]
                            "
                        >
                            Pré-ICP
                        </div>

                        <div
                            class="
                                mt-1 text-lg
                                font-bold
                                text-cyan-300
                            "
                        >
                            {{
                                $prospect[
                                    'discovery_score'
                                ]
                                ?? '—'
                            }}
                        </div>

                    </div>


                    {{-- UNIDADES --}}
                    <div>

                        <div
                            class="
                                text-[10px]
                                font-semibold
                                uppercase
                                tracking-wide
                                text-[#697394]
                            "
                        >
                            Unidades
                        </div>

                        <div
                            class="
                                mt-1 text-sm
                                font-semibold
                                text-[#d9ddef]
                            "
                        >
                            {{
                                $prospect[
                                    'active_establishments'
                                ]
                            }}
                        </div>

                    </div>


                    {{-- CAPITAL --}}
                    <div>

                        <div
                            class="
                                text-[10px]
                                font-semibold
                                uppercase
                                tracking-wide
                                text-[#697394]
                            "
                        >
                            Capital social
                        </div>

                        <div
                            class="
                                mt-1 truncate
                                text-xs
                                text-[#cbd1e7]
                            "
                        >
                            {{
                                $this->capital(
                                    $prospect[
                                        'share_capital'
                                    ]
                                    ?? null
                                )
                            }}
                        </div>

                    </div>

                </div>

            @empty

                <div
                    class="
                        px-5 py-12
                        text-center
                    "
                >

                    <div
                        class="
                            text-sm font-semibold
                            text-[#b7bfd9]
                        "
                    >
                        Nenhum prospect encontrado
                    </div>

                    <div
                        class="
                            mt-2 text-xs
                            text-[#697394]
                        "
                    >
                        Tente ampliar os estados ou CNAEs selecionados.
                    </div>

                </div>

            @endforelse

        </section>

    @endif


    {{-- HISTÓRICO --}}
    <section
        class="
            mt-5 overflow-hidden
            rounded-2xl
            border border-white/[0.06]
            bg-white/[0.02]
        "
    >

        <div
            class="
                flex flex-wrap
                items-center justify-between
                gap-3 border-b
                border-white/[0.06]
                px-5 py-4
            "
        >

            <div>

                <div
                    class="
                        text-sm font-semibold
                        text-[#eef1ff]
                    "
                >
                    Histórico de prospecção
                </div>

                <div
                    class="
                        mt-1 text-xs
                        text-[#7781a2]
                    "
                >
                    Últimas execuções e resultados comerciais.
                </div>

            </div>

            <a
                href="{{ route('imports.index') }}"
                wire:navigate
                class="
                    text-xs font-semibold
                    text-cyan-300
                    hover:text-cyan-200
                "
            >
                Ver todas as importações →
            </a>

        </div>


        @forelse ($this->recentRuns as $run)

            <div
                class="
                    border-b
                    border-white/[0.05]
                    px-5 py-5
                    last:border-b-0
                "
            >

                <div
                    class="
                        flex flex-col gap-4
                        xl:flex-row
                        xl:items-center
                        xl:justify-between
                    "
                >

                    <div class="min-w-0">

                        <div
                            class="
                                flex flex-wrap
                                items-center gap-2
                            "
                        >

                            <span
                                class="
                                    text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{
                                    $run['created_at']
                                        ? \Illuminate\Support\Carbon::parse(
                                            $run['created_at']
                                        )->format(
                                            'd/m/Y H:i'
                                        )
                                        : '—'
                                }}
                            </span>

                            <span
                                class="
                                    rounded-md
                                    border border-white/[0.07]
                                    bg-white/[0.03]
                                    px-2 py-1
                                    text-[10px]
                                    font-semibold
                                    text-[#aab2cf]
                                "
                            >
                                {{
                                    $this->runStatusLabel(
                                        $run['status']
                                    )
                                }}
                            </span>

                        </div>

                        <div
                            class="
                                mt-1 truncate
                                text-[10px]
                                text-[#697394]
                            "
                        >
                            Lote {{ $run['uuid'] }}
                        </div>

                        <a
                            href="{{
                                route(
                                    'prospecting.show',
                                    $run['uuid']
                                )
                            }}"
                            wire:navigate
                            class="
                                mt-2 inline-block
                                text-xs font-semibold
                                text-cyan-300
                                hover:text-cyan-200
                            "
                        >
                            Abrir rodada →
                        </a>

                    </div>


                    <div
                        class="
                            grid flex-1 gap-3
                            sm:grid-cols-4
                            xl:max-w-4xl
                            xl:grid-cols-8
                        "
                    >

                        @php
                            $metrics = [
                                [
                                    'label' => 'Descobertos',
                                    'value' => $run[
                                        'discovered_count'
                                    ],
                                ],
                                [
                                    'label' => 'Enviados',
                                    'value' => $run[
                                        'total_rows'
                                    ],
                                ],
                                [
                                    'label' => 'Processados',
                                    'value' => $run[
                                        'processed_rows'
                                    ],
                                ],
                                [
                                    'label' => 'Leads',
                                    'value' => $run[
                                        'lead_count'
                                    ],
                                ],
                                [
                                    'label' => 'CRM bloqueou',
                                    'value' => $run[
                                        'crm_blocked_count'
                                    ],
                                ],
                                [
                                    'label' => 'Pesquisados',
                                    'value' => $run[
                                        'researched_count'
                                    ],
                                ],
                                [
                                    'label' => 'Exportadores',
                                    'value' => $run[
                                        'export_identified_count'
                                    ],
                                ],
                                [
                                    'label' => 'Falhas',
                                    'value' => $run[
                                        'failed_count'
                                    ],
                                ],
                            ];
                        @endphp

                        @foreach ($metrics as $metric)

                            <div
                                class="
                                    rounded-xl
                                    border border-white/[0.05]
                                    bg-white/[0.02]
                                    px-3 py-2.5
                                "
                            >

                                <div
                                    class="
                                        text-[9px]
                                        font-semibold
                                        uppercase
                                        tracking-wide
                                        text-[#697394]
                                    "
                                >
                                    {{ $metric['label'] }}
                                </div>

                                <div
                                    class="
                                        mt-1 text-sm
                                        font-bold
                                        text-[#e5e9fa]
                                    "
                                >
                                    {{ $metric['value'] }}
                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            </div>

        @empty

            <div
                class="
                    px-5 py-10
                    text-center
                    text-sm text-[#7781a2]
                "
            >
                Nenhuma rodada de prospecção registrada ainda.
            </div>

        @endforelse

    </section>

</div>
