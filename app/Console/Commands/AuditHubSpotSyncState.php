<?php

namespace App\Console\Commands;

use App\Models\CompanyHubSpotLead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AuditHubSpotSyncState extends Command
{
    protected $signature =
        'hubspot:sync-audit
        {--format=table : Formato do relatório (table ou json)}';

    protected $description =
        'Audita estados de sincronização HubSpot sem iniciar sincronizações ou exibir dados pessoais.';

    public function handle(): int
    {
        $format = $this->option('format');

        if (
            ! is_string($format)
            || ! in_array($format, ['table', 'json'], true)
        ) {
            $this->components->error(
                'Use --format=table ou --format=json.'
            );

            return self::FAILURE;
        }

        $summary = [
            'leads_without_remote_company_id' => CompanyHubSpotLead::query()
                ->whereNull('hubspot_company_id')
                ->count(),
            'leads_without_remote_deal_id' => CompanyHubSpotLead::query()
                ->whereNull('hubspot_deal_id')
                ->count(),
            'deals_without_company_association_candidate' => CompanyHubSpotLead::query()
                ->whereNotNull('hubspot_deal_id')
                ->whereNull('hubspot_company_id')
                ->count(),
            'deal_sync_states' => CompanyHubSpotLead::query()
                ->select('hubspot_deal_sync_status')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy('hubspot_deal_sync_status')
                ->orderBy('hubspot_deal_sync_status')
                ->pluck('aggregate', 'hubspot_deal_sync_status')
                ->map(fn (int|string $count): int => (int) $count)
                ->all(),
            'remote_company_id_duplicate_groups' => $this->duplicateGroups(
                'hubspot_company_id'
            ),
            'remote_deal_id_duplicate_groups' => $this->duplicateGroups(
                'hubspot_deal_id'
            ),
            'failed_jobs_by_queue_and_exception' => $this->failedJobCategories(),
        ];

        if ($format === 'json') {
            $this->line(json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['Indicador', 'Total agregado'],
            collect($summary)
                ->map(
                    fn (mixed $value, string $key): array => [
                        $key,
                        is_array($value)
                            ? json_encode(
                                $value,
                                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                            )
                            : $value,
                    ]
                )
                ->values()
                ->all()
        );

        return self::SUCCESS;
    }

    private function duplicateGroups(string $column): int
    {
        $duplicateGroups = CompanyHubSpotLead::query()
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1');

        return DB::query()
            ->fromSub($duplicateGroups->toBase(), 'duplicate_groups')
            ->count();
    }

    /**
     * @return array{available: bool, categories: array<string, int>}
     */
    private function failedJobCategories(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'available' => false,
                'categories' => [],
            ];
        }

        $categories = [];

        DB::table('failed_jobs')
            ->select([
                'id',
                'queue',
                'exception',
            ])
            ->orderBy('id')
            ->chunk(
                200,
                function ($failedJobs) use (&$categories): void {
                    foreach ($failedJobs as $failedJob) {
                        $queue = (string) $failedJob->queue;

                        if (! str_starts_with($queue, 'hubspot')) {
                            continue;
                        }

                        $firstLine = strtok(
                            (string) $failedJob->exception,
                            "\r\n"
                        ) ?: '';

                        preg_match(
                            '/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/',
                            $firstLine,
                            $matches
                        );

                        $exceptionClass = $matches[1] ?? 'unknown';
                        $category = $queue.'|'.$exceptionClass;
                        $categories[$category] =
                            ($categories[$category] ?? 0) + 1;
                    }
                }
            );

        ksort($categories);

        return [
            'available' => true,
            'categories' => $categories,
        ];
    }
}
