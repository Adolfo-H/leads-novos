<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyIcpScore;
use App\Models\Establishment;
use App\Services\IcpScoringService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class AuditIcpScoreVersions extends Command
{
    protected $signature =
        'icp:audit-scores
        {--limit=0 : Limita o diagnóstico a uma amostra de empresas}
        {--format=table : Formato do relatório (table ou json)}';

    protected $description =
        'Audita versões e integridade dos scores ICP sem exibir dados identificáveis.';

    public function handle(): int
    {
        $limit = $this->option('limit');
        $format = $this->option('format');

        if (
            ! is_string($limit)
            || ! ctype_digit($limit)
            || ! is_string($format)
            || ! in_array($format, ['table', 'json'], true)
        ) {
            $this->components->error(
                'Use --limit com um inteiro não negativo e --format=table ou --format=json.'
            );

            return self::FAILURE;
        }

        $limitValue = (int) $limit;
        $companyIds = fn () => Company::query()
            ->select('companies.id')
            ->when(
                $limitValue > 0,
                fn (Builder $query) => $query
                    ->orderBy('companies.id')
                    ->limit($limitValue)
            );

        $summary = [
            'scope' => $limitValue > 0
                ? 'sample'
                : 'all',
            'companies_in_scope' => Company::query()
                ->whereIn('id', $companyIds())
                ->count(),
            'icp_missing' => Company::query()
                ->whereIn('id', $companyIds())
                ->whereDoesntHave('icpScore')
                ->count(),
            'icp_version_v1' => $this->scoreCount(
                $companyIds,
                fn (Builder $query) => $query->where('version', 'v1')
            ),
            'icp_version_v2' => $this->scoreCount(
                $companyIds,
                fn (Builder $query) => $query->where('version', 'v2')
            ),
            'icp_version_v3' => $this->scoreCount(
                $companyIds,
                fn (Builder $query) => $query->where(
                    'version',
                    IcpScoringService::VERSION
                )
            ),
            'icp_version_empty_or_unknown' => $this->scoreCount(
                $companyIds,
                fn (Builder $query) => $query
                    ->where(function (Builder $versions): void {
                        $versions
                            ->whereNull('version')
                            ->orWhere('version', '')
                            ->orWhereNotIn(
                                'version',
                                ['v1', 'v2', IcpScoringService::VERSION]
                            );
                    })
            ),
            'establishment_code_2' => $this->establishmentCount(
                $companyIds,
                fn (Builder $query) => $query->where(
                    'registration_status_code',
                    '2'
                )
            ),
            'establishment_code_02' => $this->establishmentCount(
                $companyIds,
                fn (Builder $query) => $query->where(
                    'registration_status_code',
                    '02'
                )
            ),
            'establishment_code_other' => $this->establishmentCount(
                $companyIds,
                fn (Builder $query) => $query
                    ->whereNotNull('registration_status_code')
                    ->whereNotIn(
                        'registration_status_code',
                        ['', '2', '02']
                    )
            ),
            'v3_potentially_stale_after_registration_update' => CompanyIcpScore::query()
                ->whereIn('company_id', $companyIds())
                ->where('version', IcpScoringService::VERSION)
                ->whereHas(
                    'company.establishments',
                    fn (Builder $query) => $query
                        ->whereIn(
                            'registration_status_code',
                            ['2', '02']
                        )
                        ->whereColumn(
                            'establishments.updated_at',
                            '>',
                            'company_icp_scores.calculated_at'
                        )
                )
                ->count(),
        ];

        if ($format === 'json') {
            $this->line(json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['Indicador', 'Total'],
            collect($summary)
                ->map(
                    fn (int|string $value, string $key): array => [
                        $key,
                        $value,
                    ]
                )
                ->values()
                ->all()
        );

        return self::SUCCESS;
    }

    /**
     * @param  \Closure(): Builder<Company>  $companyIds
     * @param  \Closure(Builder<CompanyIcpScore>): Builder<CompanyIcpScore>  $condition
     */
    private function scoreCount(
        \Closure $companyIds,
        \Closure $condition,
    ): int {
        $query = CompanyIcpScore::query()
            ->whereIn('company_id', $companyIds());

        $condition($query);

        return $query->count();
    }

    /**
     * @param  \Closure(): Builder<Company>  $companyIds
     * @param  \Closure(Builder<Establishment>): Builder<Establishment>  $condition
     */
    private function establishmentCount(
        \Closure $companyIds,
        \Closure $condition,
    ): int {
        $query = Establishment::query()
            ->whereIn('company_id', $companyIds());

        $condition($query);

        return $query->count();
    }
}
