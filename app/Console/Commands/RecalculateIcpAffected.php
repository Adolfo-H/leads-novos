<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyIcpScore;
use App\Services\IcpScoringService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RecalculateIcpAffected extends Command
{
    protected $signature =
        'icp:recalculate-affected
        {--dry-run : Gera apenas a prévia (comportamento padrão)}
        {--apply : Grava os scores da amostra selecionada}
        {--limit=25 : Número máximo de empresas processadas}
        {--chunk=100 : Tamanho dos lotes de leitura}
        {--reason= : Motivo obrigatório ao aplicar}
        {--company-id= : Restringe a uma empresa pelo ID interno}
        {--include-unknown : Inclui versões desconhecidas; exige confirmação ao aplicar}';

    protected $description =
        'Pré-visualiza ou recalcula scores ICP comprovadamente candidatos, sem chamadas externas.';

    public function handle(IcpScoringService $scoring): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->positiveIntegerOption('limit');
        $chunk = $this->positiveIntegerOption('chunk');
        $companyId = $this->option('company-id');
        $reason = $this->option('reason');
        $includeUnknown = (bool) $this->option('include-unknown');

        if (
            $limit === null
            || $chunk === null
            || ($apply && $dryRun)
        ) {
            $this->components->error(
                'Informe --limit e --chunk positivos; --dry-run e --apply não podem ser usados juntos.'
            );

            return self::FAILURE;
        }

        if (
            $companyId !== null
            && (
                ! ctype_digit($companyId)
                || (int) $companyId < 1
            )
        ) {
            $this->components->error(
                'O --company-id deve ser um ID numérico válido.'
            );

            return self::FAILURE;
        }

        if (
            $apply
            && (! is_string($reason) || trim($reason) === '')
        ) {
            $this->components->error(
                'Informe --reason ao usar --apply para manter a trilha de auditoria.'
            );

            return self::FAILURE;
        }

        if ($apply && $includeUnknown && ! $this->confirm(
            'Confirmar a inclusão de versões ICP desconhecidas na amostra?'
        )) {
            $this->components->warn('Aplicação cancelada.');

            return self::SUCCESS;
        }

        if ($apply && app()->environment('production') && ! $this->confirm(
            'ATENÇÃO: aplicar recálculo de scores no ambiente de produção?'
        )) {
            $this->components->warn('Aplicação cancelada.');

            return self::SUCCESS;
        }

        $query = $this->candidateQuery($includeUnknown);

        if ($companyId !== null) {
            $query->where('company_id', (int) $companyId);
        }

        $candidateCount = (clone $query)->count();

        if ($candidateCount === 0) {
            $this->components->info(
                'Nenhum score ICP elegível para a operação.'
            );

            return self::SUCCESS;
        }

        $selectedCount = min($candidateCount, $limit);
        $query
            ->with('company.establishments.cnaes')
            ->orderBy('company_icp_scores.id')
            ->limit($limit);

        $this->components->info(
            ($apply ? 'Aplicação explícita' : 'Prévia somente leitura')
            .": {$selectedCount} de {$candidateCount} candidatos; limite {$limit}."
        );

        if (! $apply) {
            $rows = [];

            /** @param Collection<int, CompanyIcpScore> $scores */
            $previewCandidates = function (Collection $scores) use (
                $scoring,
                &$rows,
            ): void {
                foreach ($scores as $score) {
                    $company = $score->company;

                    if (! $company) {
                        continue;
                    }

                    $preview = $scoring->evaluate($company);
                    $rows[] = [
                        $company->id,
                        $score->version ?: '(vazia)',
                        $score->score,
                        $preview['score'],
                        $preview['score'] - $score->score,
                    ];
                }
            };

            $query->chunkById(
                $chunk,
                $previewCandidates,
                'company_icp_scores.id',
                'id'
            );

            $this->table(
                ['Company ID', 'Versão', 'Score atual', 'Prévia v3', 'Delta'],
                $rows
            );
            $this->line('Nenhum score ICP ou SDR foi gravado.');

            return self::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;

        /** @param Collection<int, CompanyIcpScore> $scores */
        $applyCandidates = function (Collection $scores) use (
            $scoring,
            $includeUnknown,
            $reason,
            &$processed,
            &$skipped,
        ): void {
            foreach ($scores as $candidate) {
                $result = DB::transaction(function () use (
                    $candidate,
                    $scoring,
                    $includeUnknown,
                    $reason,
                ): bool {
                    $company = Company::query()
                        ->whereKey($candidate->company_id)
                        ->lockForUpdate()
                        ->with('establishments.cnaes')
                        ->firstOrFail();
                    $score = CompanyIcpScore::query()
                        ->whereKey($candidate->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (! $this->isCandidate(
                        $score,
                        $company,
                        $includeUnknown
                    )) {
                        return false;
                    }

                    $previousVersion = $score->version;
                    $previousScore = $score->score;
                    $updated = $scoring->calculate($company);

                    Log::info('icp.score.recalculated', [
                        'company_id' => $company->id,
                        'previous_version' => $previousVersion,
                        'previous_score' => $previousScore,
                        'new_score' => $updated->score,
                        'applied_at' => now()->toISOString(),
                        'reason' => trim((string) $reason),
                    ]);

                    return true;
                });

                if ($result) {
                    $processed++;
                } else {
                    $skipped++;
                }
            }
        };

        $query->chunkById(
            $chunk,
            $applyCandidates,
            'company_icp_scores.id',
            'id'
        );

        $this->components->info(
            "Empresas recalculadas: {$processed}; ignoradas após revalidação: {$skipped}."
        );

        return self::SUCCESS;
    }

    /**
     * @return Builder<CompanyIcpScore>
     */
    private function candidateQuery(bool $includeUnknown): Builder
    {
        return CompanyIcpScore::query()
            ->where(function (Builder $query) use ($includeUnknown): void {
                $query
                    ->whereIn('version', ['v1', 'v2'])
                    ->orWhere(function (Builder $statusCandidates): void {
                        $statusCandidates
                            ->where(
                                'version',
                                IcpScoringService::VERSION
                            )
                            ->whereHas(
                                'company.establishments',
                                fn (Builder $establishments) => $establishments
                                    ->whereIn(
                                        'registration_status_code',
                                        ['2', '02']
                                    )
                                    ->whereColumn(
                                        'establishments.updated_at',
                                        '>',
                                        'company_icp_scores.calculated_at'
                                    )
                            );
                    });

                if ($includeUnknown) {
                    $query->orWhere(function (Builder $unknown): void {
                        $unknown
                            ->where(function (Builder $versions): void {
                                $versions
                                    ->whereNull('version')
                                    ->orWhere('version', '')
                                    ->orWhereNotIn(
                                        'version',
                                        ['v1', 'v2', IcpScoringService::VERSION]
                                    );
                            });
                    });
                }
            });
    }

    private function isCandidate(
        CompanyIcpScore $score,
        Company $company,
        bool $includeUnknown,
    ): bool {
        if (in_array($score->version, ['v1', 'v2'], true)) {
            return true;
        }

        if (
            $includeUnknown
            && $score->version !== IcpScoringService::VERSION
        ) {
            return true;
        }

        if ($score->version !== IcpScoringService::VERSION) {
            return false;
        }

        return $company->establishments->contains(
            fn ($establishment): bool => in_array(
                $establishment->registration_status_code,
                ['2', '02'],
                true
            )
            && $establishment->updated_at?->gt($score->calculated_at)
        );
    }

    private function positiveIntegerOption(string $option): ?int
    {
        $value = $this->option($option);

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
