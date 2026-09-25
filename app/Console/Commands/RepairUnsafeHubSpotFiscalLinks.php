<?php

namespace App\Console\Commands;

use App\Models\HubSpotCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RepairUnsafeHubSpotFiscalLinks extends Command
{
    protected $signature =
        'hubspot:repair-unsafe-fiscal-links
        {--apply : Remove efetivamente os vínculos fiscais inseguros}';

    protected $description =
        'Coloca em quarentena vínculos fiscais HubSpot derivados de empresas relacionadas';

    public function handle(): int
    {
        $records =
            HubSpotCompany::query()
                ->whereIn(
                    'match_source',
                    HubSpotCompany::UNSAFE_FISCAL_MATCH_SOURCES
                )
                ->orderBy(
                    'id'
                )
                ->get();

        $this->newLine();

        $this->info(
            'Vínculos fiscais inseguros encontrados: '
            .$records->count()
        );

        if ($records->isEmpty()) {
            return self::SUCCESS;
        }

        $this->table(
            [
                'HubSpot ID',
                'HubSpot',
                'Company ID',
                'Root',
                'Fonte',
            ],
            $records
                ->take(60)
                ->map(
                    static fn (
                        HubSpotCompany $record
                    ): array => [
                        $record->hubspot_id,
                        $record->name,
                        $record->company_id,
                        $record->matched_cnpj_root,
                        $record->match_source,
                    ]
                )
                ->all()
        );

        /*
         * Sem --apply:
         *
         * somente mostra o que seria alterado.
         */
        if (
            ! (bool) $this->option(
                'apply'
            )
        ) {
            $this->warn(
                'PREVIEW: nenhum vínculo foi alterado.'
            );

            return self::SUCCESS;
        }

        /*
         * Backup fora do banco.
         */
        $directory =
            storage_path(
                'app/hubspot-import/reports'
            );

        File::ensureDirectoryExists(
            $directory
        );

        $backupPath =
            $directory
            .DIRECTORY_SEPARATOR
            .'unsafe_fiscal_links_'
            .now()->format(
                'Ymd_His'
            )
            .'.json';

        $backup =
            $records
                ->map(
                    static fn (
                        HubSpotCompany $record
                    ): array => [
                        'id' => $record->id,

                        'hubspot_id' => $record->hubspot_id,

                        'hubspot_name' => $record->name,

                        'company_id' => $record->company_id,

                        'matched_cnpj_root' => $record->matched_cnpj_root,

                        'matched_company_name' => $record->matched_company_name,

                        'match_source' => $record->match_source,
                    ]
                )
                ->all();

        File::put(
            $backupPath,
            json_encode(
                $backup,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );

        /*
         * Remove somente a identidade fiscal
         * não comprovada.
         *
         * Company HubSpot, Deals, Contacts,
         * Tasks e Activities permanecem no mirror.
         */
        DB::transaction(
            function () use (
                $records
            ): void {
                foreach (
                    $records as $record
                ) {
                    /*
                     * attributesToArray aplica os
                     * casts definidos no Model.
                     *
                     * raw_properties portanto chega
                     * aqui já convertido para array
                     * quando possuir conteúdo.
                     */
                    $attributes =
                        $record
                            ->attributesToArray();

                    /**
                     * @var array<string, mixed> $raw
                     */
                    $raw =
                        (array) (
                            $attributes[
                                'raw_properties'
                            ]
                            ?? []
                        );

                    /*
                     * Mantemos no próprio mirror
                     * o snapshot do vínculo removido.
                     */
                    $raw[
                        '_prospector_fiscal_quarantine'
                    ] = [
                        'company_id' => $record->company_id,

                        'matched_cnpj_root' => $record->matched_cnpj_root,

                        'matched_company_name' => $record->matched_company_name,

                        'match_source' => $record->match_source,

                        'quarantined_at' => now()
                            ->toIso8601String(),
                    ];

                    $record->forceFill([
                        'company_id' => null,

                        'matched_cnpj_root' => null,

                        'matched_company_name' => null,

                        'match_source' => null,

                        'raw_properties' => $raw,
                    ])->save();
                }
            }
        );

        $this->newLine();

        $this->info(
            'Quarentena concluída.'
        );

        $this->line(
            'Backup: '
            .$backupPath
        );

        return self::SUCCESS;
    }
}
