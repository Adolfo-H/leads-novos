<?php

namespace App\Support\Access;

use App\Models\AccountAccessRequest;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dados exclusivamente de apresentação para o painel administrativo de acesso.
 * Não altera o fluxo de aprovação, convite, autenticação ou permissões.
 */
final class AccessRequestPageData
{
    private const STATUS_GROUPS = [
        'pending' => ['pending', 'requested', 'awaiting_review', 'pendente', 'solicitado', 'waiting'],
        'approved' => ['approved', 'invited', 'invitation_sent', 'aprovado', 'aprovada'],
        'rejected' => ['rejected', 'denied', 'refused', 'recusado', 'recusada'],
        'activated' => ['activated', 'active', 'accepted', 'completed', 'ativado', 'ativada'],
    ];

    /** @var array<string, string> */
    private const ACTION_ROUTES = [
        'approve' => 'access.requests.approve',
        'reject' => 'access.requests.reject',
        'resend-invitation' => 'access.requests.resend',
        'resend-notification' => 'access.requests.resend-notification',
        'revoke' => 'access.requests.revoke',
    ];

    /**
     * @return array{items: LengthAwarePaginator<int, AccountAccessRequest>, counts: array<string, int>, filter: string, search: string, sort: string}
     */
    public static function load(): array
    {
        // O controle de acesso permanece no controller e no middleware originais.
        // Esta classe nunca é exposta como rota, endpoint público ou ação de escrita.
        $model = new AccountAccessRequest;
        $table = $model->getTable();
        $columns = Schema::getColumnListing($table);
        $columnSet = array_fill_keys($columns, true);
        $statusColumn = isset($columnSet['status']) ? 'status' : null;
        $activatedColumn = self::activatedColumn($columnSet);

        $filter = (string) request()->query('status', 'all');
        if (! in_array($filter, ['all', 'pending', 'approved', 'rejected', 'activated'], true)) {
            $filter = 'all';
        }

        $searchInput = request()->query('q', '');
        $search = trim(is_string($searchInput) ? Str::limit($searchInput, 120, '') : '');
        $sort = request()->query('sort') === 'oldest' ? 'oldest' : 'newest';

        $counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'activated' => 0];

        if ($statusColumn !== null) {
            $groups = AccountAccessRequest::query()
                ->select('status')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('status')
                ->get();

            foreach ($groups as $group) {
                $total = (int) $group->getAttribute('total');
                // Inclui também estados legados/desconhecidos no total geral.
                $counts['all'] += $total;
                $bucket = self::bucketFromRaw($group->getRawOriginal('status'));
                if (isset($counts[$bucket])) {
                    $counts[$bucket] += $total;
                }
            }

            if ($activatedColumn !== null) {
                $turnedActive = AccountAccessRequest::query()
                    ->whereIn('status', self::STATUS_GROUPS['approved'])
                    ->whereNotNull($activatedColumn)
                    ->count();

                $counts['approved'] = max(0, $counts['approved'] - $turnedActive);
                $counts['activated'] += $turnedActive;
            }
        } else {
            // Compatibilidade defensiva caso alguma instalação antiga não tenha status.
            $counts['all'] = AccountAccessRequest::query()->count();
        }

        $query = AccountAccessRequest::query();

        if ($statusColumn !== null && $filter !== 'all') {
            if ($filter === 'activated' && $activatedColumn !== null) {
                $query->where(function (Builder $q) use ($activatedColumn): void {
                    $q->whereIn('status', self::STATUS_GROUPS['activated'])
                        ->orWhere(function (Builder $nested) use ($activatedColumn): void {
                            $nested->whereIn('status', self::STATUS_GROUPS['approved'])
                                ->whereNotNull($activatedColumn);
                        });
                });
            } elseif ($filter === 'approved' && $activatedColumn !== null) {
                $query->whereIn('status', self::STATUS_GROUPS['approved'])
                    ->whereNull($activatedColumn);
            } else {
                $query->whereIn('status', self::STATUS_GROUPS[$filter]);
            }
        }

        if ($search !== '') {
            $allowedSearchColumns = array_values(array_intersect(
                ['name', 'email', 'company', 'company_name', 'phone', 'position', 'job_title'],
                $columns,
            ));
            if ($allowedSearchColumns !== []) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

                $query->where(function (Builder $q) use ($allowedSearchColumns, $operator, $escaped): void {
                    foreach ($allowedSearchColumns as $column) {
                        $q->orWhere($column, $operator, '%'.$escaped.'%');
                    }
                });
            }
        }

        $orderBy = isset($columnSet['created_at']) ? 'created_at' : $model->getKeyName();
        $query->orderBy($orderBy, $sort === 'oldest' ? 'asc' : 'desc');
        if ($orderBy !== $model->getKeyName()) {
            $query->orderBy($model->getKeyName(), 'desc');
        }

        return [
            'items' => $query->paginate(10)->withQueryString(),
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
            'sort' => $sort,
        ];
    }

    /** @param array<string, bool> $columnSet */
    private static function activatedColumn(array $columnSet): ?string
    {
        foreach (['activated_at', 'accepted_at', 'invitation_accepted_at', 'registered_at'] as $column) {
            if (isset($columnSet[$column])) {
                return $column;
            }
        }

        return null;
    }

    private static function bucketFromRaw(mixed $raw): string
    {
        $status = strtolower(trim((string) ($raw instanceof BackedEnum ? $raw->value : $raw)));
        foreach (self::STATUS_GROUPS as $group => $values) {
            if (in_array($status, $values, true)) {
                return $group;
            }
        }

        return 'unknown';
    }

    public static function status(object $row): string
    {
        $raw = data_get($row, 'status');
        $bucket = self::bucketFromRaw($raw);

        if ($bucket === 'approved') {
            foreach (['activated_at', 'accepted_at', 'invitation_accepted_at', 'registered_at'] as $column) {
                if (data_get($row, $column) !== null) {
                    return 'activated';
                }
            }
        }

        return $bucket;
    }

    public static function label(object $row): string
    {
        return match (self::status($row)) {
            'pending' => 'Pendente',
            'approved' => 'Aprovada',
            'rejected' => 'Recusada',
            'activated' => 'Ativada',
            default => 'Em análise',
        };
    }

    /** @param list<string> $names */
    public static function field(object $row, array $names, string $fallback = '—'): string
    {
        foreach ($names as $name) {
            $value = data_get($row, $name);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return $fallback;
    }

    public static function initials(object $row): string
    {
        $name = self::field($row, ['name', 'full_name'], 'Pessoa');
        $pieces = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr($pieces[0] ?? 'P', 0, 1);
        $last = mb_substr(count($pieces) > 1 ? (string) end($pieces) : ($pieces[0] ?? 'P'), 0, 1);

        return mb_strtoupper($first.$last);
    }

    public static function date(object $row): string
    {
        $raw = data_get($row, 'created_at');
        if ($raw === null || $raw === '') {
            return '—';
        }
        if ($raw instanceof DateTimeInterface) {
            $raw = $raw->format(DateTimeInterface::ATOM);
        } elseif (! is_string($raw) && ! is_int($raw)) {
            return '—';
        }
        try {
            return Carbon::parse((string) $raw)
                ->timezone('America/Sao_Paulo')
                ->format('d/m/Y H:i');
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * Ações exibidas para o estado atual. Isto não substitui a autorização
     * no controller ou as validações de estado feitas pelo backend.
     *
     * @return array<string, array{url: string, method: string}>
     */
    public static function availableActions(object $row): array
    {
        $types = match (self::status($row)) {
            'pending' => ['approve', 'reject', 'resend-notification'],
            'approved' => ['resend-invitation', 'revoke'],
            default => [],
        };

        // O endpoint de revogação aceita exclusivamente status bruto "approved".
        if (data_get($row, 'status') !== 'approved') {
            $types = array_filter($types, static fn (string $type): bool => $type !== 'revoke');
        }

        $actions = [];
        foreach ($types as $type) {
            $action = self::action($row, $type);
            if ($action !== null) {
                $actions[$type] = $action;
            }
        }

        return $actions;
    }

    /**
     * Mapeia cada ação para sua rota nomeada, sem varrer todas as rotas.
     * A rota e o controller continuam responsáveis pela autorização.
     *
     * @return array{url: string, method: string}|null
     */
    public static function action(object $row, string $type): ?array
    {
        $routeName = self::ACTION_ROUTES[$type] ?? null;
        if ($routeName === null) {
            return null;
        }

        $route = Route::getRoutes()->getByName($routeName);
        if ($route === null || count($route->parameterNames()) !== 1) {
            return null;
        }

        $writeMethods = array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));
        if ($writeMethods === []) {
            return null;
        }

        try {
            $paramName = (string) $route->parameterNames()[0];
            $key = method_exists($row, 'getRouteKey') ? $row->getRouteKey() : data_get($row, 'id');
            // Continua a reconhecer parâmetros com vinculação explícita {modelo:uuid}.
            if (preg_match('/\{'.preg_quote($paramName, '/').':([a-zA-Z_][a-zA-Z_0-9]*)\}/', $route->uri(), $binding)) {
                $key = data_get($row, $binding[1]);
            }
            if ($key === null) {
                return null;
            }

            return [
                'url' => route($routeName, [$paramName => $key]),
                'method' => $writeMethods[0],
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
