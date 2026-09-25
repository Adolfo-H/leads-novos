<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RecoverHubSpotLocalMatches extends Command
{
    protected $signature =
        'hubspot:recover-local-matches
        {--apply : Grava somente matches seguros}';

    protected $description =
        'Recupera vínculos HubSpot usando companies já reconstruídas';

    /**
     * @var list<string>
     */
    private const PUBLIC_DOMAINS = [
        'gmail.com',
        'hotmail.com',
        'outlook.com',
        'live.com',
        'yahoo.com',
        'icloud.com',
        'uol.com.br',
        'bol.com.br',
        'terra.com.br',
    ];

    public function handle(): int
    {
        $apply =
            (bool) $this->option(
                'apply'
            );

        /*
         * ========================================
         * INDEX POR NOME NORMALIZADO
         * ========================================
         */
        $companiesByName = [];

        Company::query()
            ->select([
                'id',
                'cnpj_root',
                'corporate_name',
                'normalized_name',
            ])
            ->orderBy('id')
            ->chunk(
                500,
                function ($companies) use (
                    &$companiesByName
                ): void {
                    foreach ($companies as $company) {
                        $key =
                            trim(
                                (string)
                                $company->normalized_name
                            );

                        if ($key === '') {
                            continue;
                        }

                        $companiesByName[
                            $key
                        ] ??= [];

                        $companiesByName[
                            $key
                        ][] = [
                            'id' => (int) $company->id,

                            'cnpj_root' => (string)
                                $company->cnpj_root,

                            'corporate_name' => (string)
                                $company->corporate_name,
                        ];
                    }
                }
            );

        /*
         * ========================================
         * INDEX POR DOMÍNIO DE E-MAIL RECEITA
         * ========================================
         */
        $companiesByDomain = [];

        Company::query()
            ->with([
                'establishments:id,company_id,email',
            ])
            ->orderBy('id')
            ->chunk(
                200,
                function ($companies) use (
                    &$companiesByDomain
                ): void {
                    foreach ($companies as $company) {
                        foreach (
                            $company->establishments as $establishment
                        ) {
                            $email =
                                mb_strtolower(
                                    trim(
                                        (string)
                                        $establishment->email
                                    )
                                );

                            if (
                                $email === ''
                                || ! str_contains(
                                    $email,
                                    '@'
                                )
                            ) {
                                continue;
                            }

                            $domain =
                                trim(
                                    (string)
                                    strrchr(
                                        $email,
                                        '@'
                                    ),
                                    '@ '
                                );

                            if (
                                $domain === ''
                                || in_array(
                                    $domain,
                                    self::PUBLIC_DOMAINS,
                                    true
                                )
                            ) {
                                continue;
                            }

                            $companiesByDomain[
                                $domain
                            ] ??= [];

                            $companiesByDomain[
                                $domain
                            ][
                                (int) $company->id
                            ] = [
                                'id' => (int) $company->id,

                                'cnpj_root' => (string)
                                    $company->cnpj_root,

                                'corporate_name' => (string)
                                    $company->corporate_name,
                            ];
                        }
                    }
                }
            );

        /*
         * ========================================
         * ANALISAR HUBSPOT SEM CNPJ
         * ========================================
         */
        $safe = [];
        $review = [];
        $unmatched = [];

        $stats = [
            'analyzed' => 0,
            'safe_name' => 0,
            'safe_domain' => 0,
            'conflict' => 0,
            'unmatched' => 0,
            'applied' => 0,
        ];

        HubSpotCompany::query()
            ->whereNull(
                'matched_cnpj_root'
            )
            ->withCount([
                'deals',
            ])
            ->with([
                'deals:id,is_closed',
            ])
            ->orderBy('id')
            ->chunkById(
                200,
                function ($rows) use (
                    &$safe,
                    &$review,
                    &$unmatched,
                    &$stats,
                    &$companiesByName,
                    &$companiesByDomain,
                    $apply
                ): void {
                    foreach ($rows as $hubspot) {
                        $stats[
                            'analyzed'
                        ]++;

                        $activeDeals =
                            $hubspot
                                ->deals
                                ->filter(
                                    static fn ($deal): bool => ! (bool)
                                        $deal->is_closed
                                )
                                ->count();

                        $nameMatches = [];

                        $normalizedName =
                            TextNormalizer::companyName(
                                $hubspot->name
                            );

                        if ($normalizedName !== null) {
                            $nameMatches =
                                $companiesByName[
                                    $normalizedName
                                ]
                                ?? [];
                        }

                        /*
                         * Domínio HubSpot.
                         */
                        $domain =
                            mb_strtolower(
                                trim(
                                    (string)
                                    $hubspot->domain
                                )
                            );

                        $domain =
                            preg_replace(
                                '#^https?://#',
                                '',
                                $domain
                            )
                            ?? '';

                        $domain =
                            preg_replace(
                                '#^www\.#',
                                '',
                                $domain
                            )
                            ?? '';

                        $domain =
                            trim(
                                explode(
                                    '/',
                                    $domain
                                )[0]
                            );

                        $domainMatches = [];

                        if (
                            $domain !== ''
                            && ! in_array(
                                $domain,
                                self::PUBLIC_DOMAINS,
                                true
                            )
                        ) {
                            $domainMatches =
                                array_values(
                                    $companiesByDomain[
                                        $domain
                                    ]
                                    ?? []
                                );
                        }

                        /*
                         * =================================================
                         * MATCH SEGURO
                         *
                         * nome exato único
                         * OU
                         * domínio único
                         *
                         * Se ambos existirem, precisam apontar
                         * para a mesma company.
                         * =================================================
                         */
                        $candidate = null;
                        $source = null;

                        if (
                            count($nameMatches) === 1
                            && count($domainMatches) === 0
                        ) {
                            $candidate =
                                $nameMatches[0];

                            $source =
                                'existing_company_exact_name';

                            $stats[
                                'safe_name'
                            ]++;

                        } elseif (
                            count($nameMatches) === 0
                            && count($domainMatches) === 1
                        ) {
                            /*
                             * Domínio sozinho não comprova
                             * identidade fiscal.
                             *
                             * Pode ser grupo econômico,
                             * contador, escritório,
                             * consultoria ou terceiro.
                             */
                            $stats[
                                'conflict'
                            ]++;

                            $review[] = [
                                'hubspot_id' => $hubspot->hubspot_id,

                                'hubspot_name' => $hubspot->name,

                                'domain' => $domain,

                                'deals' => $hubspot->deals_count,

                                'active_deals' => $activeDeals,

                                'name_candidates' => 0,

                                'domain_candidates' => 1,
                            ];

                            continue;

                        } elseif (
                            count($nameMatches) === 1
                            && count($domainMatches) === 1
                            && $nameMatches[0]['id']
                                === $domainMatches[0]['id']
                        ) {
                            $candidate =
                                $nameMatches[0];

                            $source =
                                'existing_company_name_and_domain';

                            $stats[
                                'safe_name'
                            ]++;

                        } elseif (
                            $nameMatches !== []
                            || $domainMatches !== []
                        ) {
                            $stats[
                                'conflict'
                            ]++;

                            $review[] = [
                                'hubspot_id' => $hubspot->hubspot_id,

                                'hubspot_name' => $hubspot->name,

                                'domain' => $domain,

                                'deals' => $hubspot->deals_count,

                                'active_deals' => $activeDeals,

                                'name_candidates' => count(
                                    $nameMatches
                                ),

                                'domain_candidates' => count(
                                    $domainMatches
                                ),
                            ];

                            continue;
                        }

                        if ($candidate === null) {
                            $stats[
                                'unmatched'
                            ]++;

                            $unmatched[] = [
                                'hubspot_id' => $hubspot->hubspot_id,

                                'hubspot_name' => $hubspot->name,

                                'domain' => $domain,

                                'deals' => $hubspot->deals_count,

                                'active_deals' => $activeDeals,
                            ];

                            continue;
                        }

                        $safe[] = [
                            'hubspot_id' => $hubspot->hubspot_id,

                            'hubspot_name' => $hubspot->name,

                            'domain' => $domain,

                            'cnpj_root' => $candidate[
                                    'cnpj_root'
                                ],

                            'company_name' => $candidate[
                                    'corporate_name'
                                ],

                            'source' => $source,

                            'deals' => $hubspot->deals_count,

                            'active_deals' => $activeDeals,
                        ];

                        if ($apply) {
                            $hubspot
                                ->forceFill([
                                    'company_id' => $candidate[
                                            'id'
                                        ],

                                    'matched_cnpj_root' => $candidate[
                                            'cnpj_root'
                                        ],

                                    'matched_company_name' => $candidate[
                                            'corporate_name'
                                        ],

                                    'match_source' => $source,
                                ])
                                ->save();

                            $stats[
                                'applied'
                            ]++;
                        }
                    }
                }
            );

        /*
         * ========================================
         * RELATÓRIOS
         * ========================================
         */
        $directory =
            storage_path(
                'app/hubspot-import/reports'
            );

        File::ensureDirectoryExists(
            $directory
        );

        $stamp =
            now()->format(
                'Ymd_His'
            );

        $safePath =
            $directory
            .DIRECTORY_SEPARATOR
            .'hubspot_local_safe_'
            .$stamp
            .'.csv';

        $reviewPath =
            $directory
            .DIRECTORY_SEPARATOR
            .'hubspot_local_review_'
            .$stamp
            .'.csv';

        $unmatchedPath =
            $directory
            .DIRECTORY_SEPARATOR
            .'hubspot_local_unmatched_'
            .$stamp
            .'.csv';

        $this->writeCsv(
            $safePath,
            $safe
        );

        $this->writeCsv(
            $reviewPath,
            $review
        );

        $this->writeCsv(
            $unmatchedPath,
            $unmatched
        );

        $this->newLine();

        $this->table(
            [
                'Indicador',
                'Quantidade',
            ],
            [
                [
                    'Analisadas',
                    $stats[
                        'analyzed'
                    ],
                ],
                [
                    'Match seguro por nome',
                    $stats[
                        'safe_name'
                    ],
                ],
                [
                    'Match seguro nome + domínio',
                    $stats[
                        'safe_domain'
                    ],
                ],
                [
                    'Conflitos/revisão',
                    $stats[
                        'conflict'
                    ],
                ],
                [
                    'Sem match',
                    $stats[
                        'unmatched'
                    ],
                ],
                [
                    'Aplicados',
                    $stats[
                        'applied'
                    ],
                ],
            ]
        );

        $this->line(
            'Seguros: '
            .$safePath
        );

        $this->line(
            'Revisão: '
            .$reviewPath
        );

        $this->line(
            'Sem match: '
            .$unmatchedPath
        );

        if (! $apply) {
            $this->warn(
                'PREVIEW: nenhum vínculo foi gravado.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(
        string $path,
        array $rows
    ): void {
        $handle =
            fopen(
                $path,
                'wb'
            );

        if ($handle === false) {
            return;
        }

        if ($rows === []) {
            fclose(
                $handle
            );

            return;
        }

        fputcsv(
            $handle,
            array_keys(
                $rows[0]
            ),
            ',',
            '"',
            ''
        );

        foreach ($rows as $row) {
            fputcsv(
                $handle,
                array_values(
                    $row
                ),
                ',',
                '"',
                ''
            );
        }

        fclose(
            $handle
        );
    }
}
