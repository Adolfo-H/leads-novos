<?php

namespace App\Livewire;

use App\Models\Cnae;
use App\Models\User;
use App\Services\CommercialManagementMetricsService;
use App\Services\DashboardStrategicMetricsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use stdClass;

/**
 * Dashboard v3: consultas locais, sem chamadas a provedores externos.
 *
 * @property-read LengthAwarePaginator<int, stdClass> $records
 * @property-read bool $canExplore
 * @property-read string $explorerTitle
 */
class DashboardOverview extends Component
{
    use WithoutUrlPagination, WithPagination;

    #[Locked]
    public string $dataset = '';

    #[Locked]
    public string $filterValue = '';

    public string $search = '';

    private const TITLES = [
        'companies' => 'Empresas cadastradas',
        'establishments' => 'Todos os estabelecimentos',
        'active' => 'Estabelecimentos ativos',
        'inactive' => 'Estabelecimentos sem situação ATIVA',
        'matrix' => 'Matrizes',
        'branch' => 'Filiais',
        'unknown_type' => 'Tipo de estabelecimento não informado',
        'email' => 'Estabelecimentos com e-mail',
        'missing_email' => 'Estabelecimentos sem e-mail',
        'phone' => 'Estabelecimentos com telefone',
        'missing_phone' => 'Estabelecimentos sem telefone',
        'cnaes' => 'CNAEs vinculados à base',
        'state' => 'Estabelecimentos por UF',
        'cnae' => 'Estabelecimentos por CNAE',
    ];

    // Os mesmos critérios são usados na contagem e na lista de registros.
    private const EMAIL_SQL = "COALESCE(TRIM(e.email), '') <> ''";

    private const PHONE_SQL = "(COALESCE(TRIM(e.phone_1), '') <> '' OR COALESCE(TRIM(e.phone_2), '') <> '')";

    private const STATE_SQL = "CASE WHEN LENGTH(TRIM(e.state)) = 2 THEN UPPER(TRIM(e.state)) ELSE '__unknown__' END";

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->hasVerifiedEmail(), 403);
    }

    #[Computed]
    public function canExplore(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isCommercialManager();
    }

    /**
     * Cache por instância: não compartilha resultados entre usuários ou bancos.
     *
     * @return array{companies: int, establishments: int, active: int, matrix: int,
     *     branch: int, unknown_type: int, email: int, phone: int, cnaes: int,
     *     states: list<array{code: string, total: int}>, consulted_at: string}
     */
    #[Computed(persist: true, seconds: 90)]
    public function summary(): array
    {
        $stats = DB::table('establishments as e')->selectRaw(
            'COUNT(*) AS total, '
            ."SUM(CASE WHEN e.registration_status = 'ATIVA' THEN 1 ELSE 0 END) AS active, "
            ."SUM(CASE WHEN e.type = 'matrix' THEN 1 ELSE 0 END) AS matrices, "
            ."SUM(CASE WHEN e.type = 'branch' THEN 1 ELSE 0 END) AS branches, "
            .'SUM(CASE WHEN '.self::EMAIL_SQL.' THEN 1 ELSE 0 END) AS email, '
            .'SUM(CASE WHEN '.self::PHONE_SQL.' THEN 1 ELSE 0 END) AS phone'
        )->first();

        $total = (int) ($stats->total ?? 0);
        $matrices = (int) ($stats->matrices ?? 0);
        $branches = (int) ($stats->branches ?? 0);

        $states = DB::table('establishments as e')
            ->selectRaw(self::STATE_SQL.' AS code, COUNT(*) AS total')
            ->groupByRaw(self::STATE_SQL)
            ->orderByDesc('total')
            ->orderBy('code')
            ->limit(5)
            ->get()
            ->map(static fn (stdClass $row): array => [
                'code' => (string) $row->code,
                'total' => (int) $row->total,
            ])->values()->all();

        /*
         * Garante explicitamente uma lista sequencial
         * para o contrato de retorno do summary().
         *
         * @var list<array{code: string, total: int}> $states
         */
        $states = array_values(
            $states
        );

        return [
            'companies' => DB::table('companies')->count(),
            'establishments' => $total,
            'active' => (int) ($stats->active ?? 0),
            'matrix' => $matrices,
            'branch' => $branches,
            'unknown_type' => max(0, $total - $matrices - $branches),
            'email' => (int) ($stats->email ?? 0),
            'phone' => (int) ($stats->phone ?? 0),
            'cnaes' => Cnae::query()->whereHas('establishments')->count(),
            'states' => $states,
            'consulted_at' => now()->format('d/m/Y H:i:s'),
        ];
    }

    /**
     * Indicadores comerciais exclusivos da gestão.
     *
     * @return array<string, int>|null
     */
    #[Computed]
    public function commercialSummary(): ?array
    {
        if (! $this->canExplore) {
            return null;
        }

        return app(
            CommercialManagementMetricsService::class
        )->summary();
    }

    /**
     * Dados estratégicos restritos à gestão comercial.
     *
     * @return array{
     *   export: array{confirmed_companies: int, direct: int, indirect: int, trading: int, researched: int},
     *   cnaes: list<array{code: string, description: string, companies: int}>
     * }|null
     */
    #[Computed]
    public function strategicMetrics(): ?array
    {
        if (! $this->canExplore) {
            return null;
        }

        return app(DashboardStrategicMetricsService::class)->snapshot();
    }

    /**
     * Pesquisa global local: respeita a autorização do explorador empresarial.
     */
    #[On('dashboard-global-search')]
    public function dashboardGlobalSearch(string $term): void
    {
        $this->assertManager();
        $term = trim(mb_substr($term, 0, 120));

        if ($term === '') {
            return;
        }

        $this->openExplorer('companies');
        $this->search = $term;
        unset($this->records);
    }

    public function openExplorer(string $dataset, string $value = ''): void
    {
        $this->assertManager();
        abort_unless(array_key_exists($dataset, self::TITLES), 422);

        if ($dataset === 'state') {
            abort_unless($value === '__unknown__' || preg_match('/^[A-Z]{2}$/D', $value) === 1, 422);
        } elseif ($dataset === 'cnae') {
            abort_unless(preg_match('/^[0-9]{7}$/D', $value) === 1, 422);
        } else {
            $value = '';
        }

        $this->dataset = $dataset;
        $this->filterValue = $value;
        $this->search = '';
        $this->resetPage('overviewPage');
        unset($this->records);
        $this->dispatch('overview-explorer-opened');
    }

    public function closeExplorer(): void
    {
        $previous = $this->dataset;
        $this->dataset = '';
        $this->filterValue = '';
        $this->search = '';
        $this->resetPage('overviewPage');
        unset($this->records);
        $this->dispatch('overview-explorer-closed', dataset: $previous);
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 120);
        $this->resetPage('overviewPage');
        unset($this->records);
    }

    public function refreshSummary(): void
    {
        unset($this->summary, $this->records, $this->commercialSummary, $this->strategicMetrics);
    }

    #[Computed]
    public function explorerTitle(): string
    {
        $title = self::TITLES[$this->dataset] ?? 'Registros';

        if ($this->dataset === 'state') {
            $title .= ' · '.($this->filterValue === '__unknown__' ? 'Sem UF' : $this->filterValue);
        } elseif ($this->dataset === 'cnae') {
            $title .= ' · '.$this->filterValue;
        }

        return $title;
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    #[Computed]
    public function records(): LengthAwarePaginator
    {
        // Não basta esconder os botões: o acesso é verificado em cada consulta.
        $this->assertManager();
        abort_unless(array_key_exists($this->dataset, self::TITLES), 422);

        $term = mb_strtolower(trim(mb_substr($this->search, 0, 120)));
        $document = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($term)) ?? '';
        $like = '%'.$term.'%';

        if ($this->dataset === 'companies') {
            $query = DB::table('companies as c')
                ->select('c.id', 'c.uuid as company_uuid', 'c.corporate_name', 'c.cnpj_root')
                ->selectSub(
                    DB::table('establishments as e')->selectRaw('COUNT(*)')->whereColumn('e.company_id', 'c.id'),
                    'establishments_count'
                );

            if ($term !== '') {
                $query->where(function (Builder $q) use ($like, $document): void {
                    $q->whereRaw('LOWER(c.corporate_name) LIKE ?', [$like]);
                    if ($document !== '') {
                        $q->orWhere('c.cnpj_root', 'like', '%'.$document.'%')
                            ->orWhereExists(function (Builder $sub) use ($document): void {
                                $sub->selectRaw('1')->from('establishments as e')
                                    ->whereColumn('e.company_id', 'c.id')
                                    ->where('e.cnpj', 'like', '%'.$document.'%');
                            });
                    }
                });
            }

            return $query->orderBy('c.corporate_name')->orderBy('c.id')
                ->paginate(15, ['*'], 'overviewPage');
        }

        if ($this->dataset === 'cnaes') {
            $query = Cnae::query()->whereHas('establishments')->withCount('establishments');
            if ($term !== '') {
                $query->where(function ($q) use ($like): void {
                    $q->where('code', 'like', $like)->orWhereRaw('LOWER(description) LIKE ?', [$like]);
                });
            }

            return $query->orderByDesc('establishments_count')->orderBy('code')
                ->toBase()->paginate(15, ['*'], 'overviewPage');
        }

        $query = DB::table('establishments as e')
            ->join('companies as c', 'c.id', '=', 'e.company_id')
            ->select('e.id', 'e.cnpj', 'e.type', 'e.registration_status', 'e.municipality_name',
                'e.state', 'e.email', 'e.phone_1', 'e.phone_2', 'c.uuid as company_uuid', 'c.corporate_name');

        switch ($this->dataset) {
            case 'active':
                $query->where('e.registration_status', 'ATIVA');
                break;
            case 'inactive':
                $query->where(function (Builder $q): void {
                    $q->whereNull('e.registration_status')->orWhere('e.registration_status', '<>', 'ATIVA');
                });
                break;
            case 'matrix':
            case 'branch':
                $query->where('e.type', $this->dataset);
                break;
            case 'unknown_type':
                $query->where(function (Builder $q): void {
                    $q->whereNull('e.type')->orWhereNotIn('e.type', ['matrix', 'branch']);
                });
                break;
            case 'email':
            case 'missing_email':
                $query->whereRaw(($this->dataset === 'missing_email' ? 'NOT (' : '(').self::EMAIL_SQL.')');
                break;
            case 'phone':
            case 'missing_phone':
                $query->whereRaw(($this->dataset === 'missing_phone' ? 'NOT ' : '').self::PHONE_SQL);
                break;
            case 'state':
                $query->whereRaw(self::STATE_SQL.' = ?', [$this->filterValue]);
                break;
            case 'cnae':
                $query->whereExists(function (Builder $q): void {
                    $q->selectRaw('1')->from('cnae_establishment as ce')
                        ->join('cnaes as activity', 'activity.id', '=', 'ce.cnae_id')
                        ->whereColumn('ce.establishment_id', 'e.id')->where('activity.code', $this->filterValue);
                });
                break;
        }

        if ($term !== '') {
            $query->where(function (Builder $q) use ($like, $document): void {
                $q->whereRaw('LOWER(c.corporate_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(e.municipality_name) LIKE ?', [$like]);
                if ($document !== '') {
                    $q->orWhere('e.cnpj', 'like', '%'.$document.'%');
                }
            });
        }

        return $query->orderBy('c.corporate_name')->orderBy('e.cnpj')->orderBy('e.id')
            ->paginate(15, ['*'], 'overviewPage');
    }

    private function assertManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->hasVerifiedEmail() && $user->isCommercialManager(), 403);
    }

    public function render(): View
    {
        return view('livewire.dashboard-overview');
    }
}
