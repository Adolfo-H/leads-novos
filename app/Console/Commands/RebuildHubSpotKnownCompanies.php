<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Services\CompanyGroupEnrichmentService;
use App\Services\Providers\ReceitaLocalCnpjGroupProvider;
use App\Support\Cnpj;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class RebuildHubSpotKnownCompanies extends Command
{
    protected $signature =
        'hubspot:rebuild-known-companies
        {--limit=0 : Quantidade máxima de raízes}
        {--root= : Processa somente uma raiz}
        {--dry-run : Apenas mostra o que seria processado}
        {--refresh : Reprocessa empresa já reconstruída}';

    protected $description =
        'Reconstrói companies a partir dos CNPJs conhecidos no espelho HubSpot';

    public function handle(
        CompanyGroupEnrichmentService $enrichment,
        ReceitaLocalCnpjGroupProvider $provider,
    ): int {
        $roots =
            HubSpotCompany::query()
                ->trustedFiscalLink()
                ->whereNotNull(
                    'matched_cnpj_root'
                )
                ->orderBy(
                    'id'
                )
                ->pluck(
                    'matched_cnpj_root'
                )
                ->map(
                    static fn (
                        mixed $root
                    ): string => mb_strtoupper(
                        trim(
                            (string) $root
                        )
                    )
                )
                ->filter(
                    static fn (
                        string $root
                    ): bool => preg_match(
                        '/^[A-Z0-9]{8}$/',
                        $root
                    ) === 1
                )
                ->unique()
                ->values();

        $specificRoot =
            mb_strtoupper(
                trim(
                    (string) $this->option(
                        'root'
                    )
                )
            );

        if ($specificRoot !== '') {
            if (
                preg_match(
                    '/^[A-Z0-9]{8}$/',
                    $specificRoot
                ) !== 1
            ) {
                $this->error(
                    'Raiz informada é inválida.'
                );

                return self::FAILURE;
            }

            $roots =
                $roots
                    ->filter(
                        static fn (
                            string $root
                        ): bool => $root === $specificRoot
                    )
                    ->values();
        }

        $limit =
            max(
                0,
                (int) $this->option(
                    'limit'
                )
            );

        if ($limit > 0) {
            $roots =
                $roots
                    ->take(
                        $limit
                    )
                    ->values();
        }

        $this->newLine();

        $this->info(
            'Raízes selecionadas: '
            .$roots->count()
        );

        if ($roots->isEmpty()) {
            $this->warn(
                'Nenhuma raiz encontrada.'
            );

            return self::SUCCESS;
        }

        if (
            (bool) $this->option(
                'dry-run'
            )
        ) {
            $this->newLine();

            foreach (
                $roots->take(30) as $root
            ) {
                $hubSpotCompanies =
                    HubSpotCompany::query()
                        ->where(
                            'matched_cnpj_root',
                            $root
                        )
                        ->pluck(
                            'name'
                        )
                        ->filter()
                        ->unique()
                        ->implode(
                            ' | '
                        );

                $this->line(
                    $root
                    .' → '
                    .$hubSpotCompanies
                );
            }

            return self::SUCCESS;
        }

        $stats = [
            'selected' => $roots->count(),

            'processed' => 0,

            'success' => 0,

            'existing' => 0,

            'failed' => 0,

            'establishments' => 0,

            'hubspot_links' => 0,
        ];

        $failures = [];

        foreach (
            $roots as $index => $root
        ) {
            $position =
                $index + 1;

            $this->newLine();

            $this->line(
                '['
                .$position
                .'/'
                .$roots->count()
                .'] '
                .$root
            );

            $stats[
                'processed'
            ]++;

            try {
                $existing =
                    Company::query()
                        ->where(
                            'cnpj_root',
                            $root
                        )
                        ->first();

                if (
                    $existing !== null
                    && ! (bool) $this->option(
                        'refresh'
                    )
                    && $existing
                        ->establishments()
                        ->exists()
                ) {
                    $linked =
                        HubSpotCompany::query()
                            ->trustedFiscalLink()
                            ->where(
                                'matched_cnpj_root',
                                $root
                            )
                            ->update([
                                'company_id' => $existing->id,
                            ]);

                    $stats[
                        'existing'
                    ]++;

                    $stats[
                        'hubspot_links'
                    ] +=
                        $linked;

                    $this->line(
                        '  já reconstruída → '
                        .$existing->corporate_name
                    );

                    continue;
                }

                /*
                 * O serviço existente trabalha
                 * com CNPJ completo.
                 *
                 * Para consulta por GRUPO,
                 * somente a raiz interessa ao
                 * provider.
                 *
                 * Criamos um CNPJ tecnicamente
                 * válido com ordem 0001 para que
                 * o serviço extraia a mesma raiz.
                 */
                $base =
                    $root
                    .'0001';

                $syntheticCnpj =
                    $base
                    .Cnpj::calculateCheckDigits(
                        $base
                    );

                $company =
                    $enrichment->enrich(
                        $syntheticCnpj,
                        $provider,
                    );

                $establishmentCount =
                    $company
                        ->establishments()
                        ->count();

                $linked =
                    HubSpotCompany::query()
                        ->where(
                            'matched_cnpj_root',
                            $root
                        )
                        ->update([
                            'company_id' => $company->id,
                        ]);

                $company->load([
                    'icpScore',
                    'sdrScore',
                ]);

                $stats[
                    'success'
                ]++;

                $stats[
                    'establishments'
                ] +=
                    $establishmentCount;

                $stats[
                    'hubspot_links'
                ] +=
                    $linked;

                $this->info(
                    '  '
                    .$company->corporate_name
                );

                $this->line(
                    '  estabelecimentos: '
                    .$establishmentCount
                );

                $this->line(
                    '  HubSpot vinculados: '
                    .$linked
                );

                $this->line(
                    '  ICP: '
                    .(
                        $company
                            ->icpScore
                            ? (
                                $company
                                    ->icpScore
                                    ->grade
                                .' · '
                                .$company
                                    ->icpScore
                                    ->score
                                .'/100'
                            )
                            : 'não calculado'
                    )
                );

                $this->line(
                    '  SDR: '
                    .(
                        $company
                            ->sdrScore
                            ? (
                                $company
                                    ->sdrScore
                                    ->score
                                .'/100'
                                .' · '
                                .$company
                                    ->sdrScore
                                    ->priority
                            )
                            : 'não calculado'
                    )
                );
            } catch (
                Throwable $exception
            ) {
                $stats[
                    'failed'
                ]++;

                $failures[] = [
                    'root' => $root,

                    'error' => mb_substr(
                        $exception
                            ->getMessage(),
                        0,
                        2000
                    ),
                ];

                $this->error(
                    '  '
                    .$exception
                        ->getMessage()
                );
            }
        }

        $directory =
            storage_path(
                'app/hubspot-import/reports'
            );

        File::ensureDirectoryExists(
            $directory
        );

        $reportPath =
            $directory
            .DIRECTORY_SEPARATOR
            .'hubspot_rebuild_known_'
            .now()->format(
                'Ymd_His'
            )
            .'.json';

        File::put(
            $reportPath,
            json_encode(
                [
                    'generated_at' => now()
                        ->toIso8601String(),

                    'stats' => $stats,

                    'failures' => $failures,
                ],
                JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
        );

        $this->newLine();

        $this->table(
            [
                'Indicador',
                'Quantidade',
            ],
            [
                [
                    'Selecionadas',
                    $stats[
                        'selected'
                    ],
                ],
                [
                    'Processadas',
                    $stats[
                        'processed'
                    ],
                ],
                [
                    'Sucesso Receita',
                    $stats[
                        'success'
                    ],
                ],
                [
                    'Já existentes',
                    $stats[
                        'existing'
                    ],
                ],
                [
                    'Falhas',
                    $stats[
                        'failed'
                    ],
                ],
                [
                    'Estabelecimentos',
                    $stats[
                        'establishments'
                    ],
                ],
                [
                    'Vínculos HubSpot',
                    $stats[
                        'hubspot_links'
                    ],
                ],
            ]
        );

        $this->line(
            'Relatório: '
            .$reportPath
        );

        /*
         * Uma falha isolada não deve destruir
         * a reconstrução inteira.
         *
         * Mas zero sucessos indica provável
         * problema com a fonte Receita.
         */
        if (
            $stats['success'] === 0
            && $stats['existing'] === 0
        ) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
