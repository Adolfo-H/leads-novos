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
        } catch (Throwable $exception) {
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

        } catch (Throwable $exception) {
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
            'limit.required' => 'Selecione a quantidade de candidatos.',

            'limit.integer' => 'A quantidade de candidatos deve ser um número inteiro.',

            'limit.min' => 'A quantidade mínima é de 1 candidato.',

            'limit.max' => 'A quantidade máxima é de 200 candidatos.',

            'selectedStates.required' => 'Selecione pelo menos um estado.',

            'selectedStates.array' => 'A seleção de estados é inválida.',

            'selectedStates.min' => 'Selecione pelo menos um estado.',

            'selectedCnaes.required' => 'Selecione pelo menos um CNAE.',

            'selectedCnaes.array' => 'A seleção de CNAEs é inválida.',

            'selectedCnaes.min' => 'Selecione pelo menos um CNAE.',
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
            'completed' => 'Concluído',

            'processing' => 'Processando',

            'failed' => 'Falhou',

            'ready' => 'Pronto',

            default => ucfirst($status),
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

{{-- Layout visual Figma figma-index-v63; backend Volt preservado. --}}
@include('pages.prospecting.partials.figma-index-v63')
