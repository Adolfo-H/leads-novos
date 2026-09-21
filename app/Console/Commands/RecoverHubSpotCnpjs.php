<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class RecoverHubSpotCnpjs extends Command
{
    protected $signature =
        'hubspot:recover-cnpjs
        {--apply : Salva automaticamente somente matches seguros}';

    protected $description =
        'Recupera CNPJs explícitos existentes nos dados exportados do HubSpot';

    public function handle(): int
    {
        $apply =
            (bool) $this->option(
                'apply'
            );

        $directory =
            app()->environment('testing')
                ? storage_path(
                    'framework/testing/hubspot-cnpj-recovery'
                )
                : storage_path(
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
            .'hubspot_cnpj_safe_'
            .$stamp
            .'.csv';

        $reviewPath =
            $directory
            .DIRECTORY_SEPARATOR
            .'hubspot_cnpj_review_'
            .$stamp
            .'.csv';

        $unmatchedActivePath =
            $directory
            .DIRECTORY_SEPARATOR
            .'hubspot_unmatched_active_'
            .$stamp
            .'.csv';

        $safe =
            fopen(
                $safePath,
                'wb'
            );

        $review =
            fopen(
                $reviewPath,
                'wb'
            );

        $unmatchedActive =
            fopen(
                $unmatchedActivePath,
                'wb'
            );

        if (
            $safe === false
            || $review === false
            || $unmatchedActive === false
        ) {
            throw new RuntimeException(
                'Não foi possível criar os relatórios.'
            );
        }

        $this->csv(
            $safe,
            [
                'hubspot_id',
                'hubspot_name',
                'cnpj_root',
                'cnpjs_encontrados',
                'campos_origem',
                'local_company_id',
                'local_company_name',
                'situacao',
                'deals_total',
                'deals_active',
                'stages',
            ]
        );

        $this->csv(
            $review,
            [
                'hubspot_id',
                'hubspot_name',
                'cnpj_atual',
                'roots_encontrados',
                'cnpjs_encontrados',
                'campos_origem',
                'motivo',
                'deals_total',
                'deals_active',
                'stages',
            ]
        );

        $this->csv(
            $unmatchedActive,
            [
                'hubspot_id',
                'hubspot_name',
                'domain',
                'deals_total',
                'deals_active',
                'stages',
            ]
        );

        $stats = [
            'total' => 0,

            'already_matched' => 0,

            'safe_found' => 0,

            'applied' => 0,

            'matched_local_company' => 0,

            'multiple_roots' => 0,

            'conflicts' => 0,

            'without_explicit_cnpj' => 0,

            'unmatched_active' => 0,
        ];

        HubSpotCompany::query()
            ->with([
                'deals:id,hubspot_id,stage_label,is_closed',
            ])
            ->orderBy(
                'id'
            )
            ->chunkById(
                200,
                function (
                    $companies
                ) use (
                    &$stats,
                    $apply,
                    $safe,
                    $review,
                    $unmatchedActive,
                ): void {
                    foreach (
                        $companies as $hubSpotCompany
                    ) {
                        $stats['total']++;

                        $rawProperties =
                            $hubSpotCompany
                                ->getAttribute(
                                    'raw_properties'
                                );

                        $properties =
                            is_array(
                                $rawProperties
                            )
                                ? $rawProperties
                                : [];

                        $candidate =
                            $this->extractCandidate(
                                $properties
                            );

                        $deals =
                            $hubSpotCompany
                                ->deals;

                        $dealCount =
                            $deals->count();

                        $activeDeals =
                            $deals
                                ->filter(
                                    static fn (
                                        HubSpotDeal $deal
                                    ): bool => ! $deal->is_closed
                                )
                                ->count();

                        $stages =
                            $deals
                                ->pluck(
                                    'stage_label'
                                )
                                ->filter()
                                ->unique()
                                ->values()
                                ->implode(
                                    ' | '
                                );

                        $currentRoot =
                            trim(
                                (string)
                                $hubSpotCompany
                                    ->matched_cnpj_root
                            );

                        /*
                         * Nenhum CNPJ explícito
                         * e validado.
                         */
                        if (
                            $candidate[
                                'roots'
                            ] === []
                        ) {
                            $stats[
                                'without_explicit_cnpj'
                            ]++;

                            if (
                                $currentRoot === ''
                                && $activeDeals > 0
                            ) {
                                $stats[
                                    'unmatched_active'
                                ]++;

                                $this->csv(
                                    $unmatchedActive,
                                    [
                                        $hubSpotCompany
                                            ->hubspot_id,

                                        $hubSpotCompany
                                            ->name,

                                        $hubSpotCompany
                                            ->domain,

                                        $dealCount,

                                        $activeDeals,

                                        $stages,
                                    ]
                                );
                            }

                            continue;
                        }

                        /*
                         * Mais de uma raiz:
                         * não decide automaticamente.
                         */
                        if (
                            count(
                                $candidate[
                                    'roots'
                                ]
                            ) > 1
                        ) {
                            $stats[
                                'multiple_roots'
                            ]++;

                            $this->csv(
                                $review,
                                [
                                    $hubSpotCompany
                                        ->hubspot_id,

                                    $hubSpotCompany
                                        ->name,

                                    $currentRoot,

                                    implode(
                                        ' | ',
                                        $candidate[
                                            'roots'
                                        ]
                                    ),

                                    implode(
                                        ' | ',
                                        $candidate[
                                            'cnpjs'
                                        ]
                                    ),

                                    implode(
                                        ' | ',
                                        $candidate[
                                            'fields'
                                        ]
                                    ),

                                    'multiple_roots',

                                    $dealCount,

                                    $activeDeals,

                                    $stages,
                                ]
                            );

                            continue;
                        }

                        $root =
                            $candidate[
                                'roots'
                            ][0];

                        /*
                         * Já tínhamos um match
                         * anterior.
                         */
                        if ($currentRoot !== '') {
                            if (
                                $currentRoot
                                === $root
                            ) {
                                $stats[
                                    'already_matched'
                                ]++;

                                continue;
                            }

                            /*
                             * Nunca sobrescrevemos
                             * um CNPJ anterior se
                             * os dados discordarem.
                             */
                            $stats[
                                'conflicts'
                            ]++;

                            $this->csv(
                                $review,
                                [
                                    $hubSpotCompany
                                        ->hubspot_id,

                                    $hubSpotCompany
                                        ->name,

                                    $currentRoot,

                                    $root,

                                    implode(
                                        ' | ',
                                        $candidate[
                                            'cnpjs'
                                        ]
                                    ),

                                    implode(
                                        ' | ',
                                        $candidate[
                                            'fields'
                                        ]
                                    ),

                                    'conflict_with_existing_match',

                                    $dealCount,

                                    $activeDeals,

                                    $stages,
                                ]
                            );

                            continue;
                        }

                        $stats[
                            'safe_found'
                        ]++;

                        $localCompany =
                            Company::query()
                                ->where(
                                    'cnpj_root',
                                    $root
                                )
                                ->first([
                                    'id',
                                    'corporate_name',
                                ]);

                        if (
                            $localCompany !== null
                        ) {
                            $stats[
                                'matched_local_company'
                            ]++;
                        }

                        if ($apply) {
                            $hubSpotCompany
                                ->forceFill([
                                    /*
                                     * company_id é útil
                                     * enquanto a base
                                     * antiga existir.
                                     *
                                     * Ao apagarmos
                                     * companies depois,
                                     * a FK nullOnDelete
                                     * transforma isso em
                                     * NULL automaticamente.
                                     */
                                    'company_id' => $localCompany
                                        ?->id,

                                    /*
                                     * Este é o dado que
                                     * realmente precisamos
                                     * preservar após a
                                     * limpeza.
                                     */
                                    'matched_cnpj_root' => $root,

                                    'matched_company_name' => $localCompany->corporate_name ?? $hubSpotCompany
                                        ->name,

                                    'match_source' => 'hubspot_export_explicit_cnpj',
                                ])
                                ->save();

                            $stats[
                                'applied'
                            ]++;
                        }

                        $this->csv(
                            $safe,
                            [
                                $hubSpotCompany
                                    ->hubspot_id,

                                $hubSpotCompany
                                    ->name,

                                $root,

                                implode(
                                    ' | ',
                                    $candidate[
                                        'cnpjs'
                                    ]
                                ),

                                implode(
                                    ' | ',
                                    $candidate[
                                        'fields'
                                    ]
                                ),

                                $localCompany
                                    ?->id,

                                $localCompany
                                    ?->corporate_name,

                                $localCompany !== null
                                    ? 'found_in_local_database'
                                    : 'root_preserved_for_future_enrichment',

                                $dealCount,

                                $activeDeals,

                                $stages,
                            ]
                        );
                    }
                }
            );

        fclose(
            $safe
        );

        fclose(
            $review
        );

        fclose(
            $unmatchedActive
        );

        $this->newLine();

        $this->table(
            [
                'Indicador',
                'Quantidade',
            ],
            [
                [
                    'Empresas HubSpot',
                    $stats['total'],
                ],
                [
                    'Match anterior confirmado',
                    $stats['already_matched'],
                ],
                [
                    'Novos CNPJs seguros encontrados',
                    $stats['safe_found'],
                ],
                [
                    'Aplicados',
                    $stats['applied'],
                ],
                [
                    'Também existem na base local',
                    $stats['matched_local_company'],
                ],
                [
                    'Múltiplas raízes - revisão',
                    $stats['multiple_roots'],
                ],
                [
                    'Conflito com match anterior',
                    $stats['conflicts'],
                ],
                [
                    'Sem CNPJ explícito',
                    $stats['without_explicit_cnpj'],
                ],
                [
                    'Sem CNPJ + negócio ativo',
                    $stats['unmatched_active'],
                ],
            ]
        );

        $this->newLine();

        $this->line(
            'Matches seguros: '
            .$safePath
        );

        $this->line(
            'Revisão manual: '
            .$reviewPath
        );

        $this->line(
            'Sem CNPJ + negócio ativo: '
            .$unmatchedActivePath
        );

        $this->newLine();

        if ($apply) {
            $this->info(
                'Matches seguros foram gravados.'
            );
        } else {
            $this->warn(
                'Modo preview: nenhum match foi alterado.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Consideramos apenas campos de texto onde
     * historicamente o CNPJ foi registrado
     * manualmente pela equipe comercial.
     *
     * Também exigimos que o campo mencione
     * explicitamente "CNPJ".
     *
     * @param  array<string, mixed>  $properties
     * @return array{
     *     cnpjs: list<string>,
     *     roots: list<string>,
     *     fields: list<string>
     * }
     */
    private function extractCandidate(
        array $properties
    ): array {
        $allowedFields = [
            'Associated Note',
            'Descrição',
        ];

        $cnpjs = [];
        $fields = [];

        foreach (
            $allowedFields as $field
        ) {
            $raw =
                $properties[
                    $field
                ]
                ?? null;

            if (! is_string($raw)) {
                continue;
            }

            $text =
                trim(
                    $raw
                );

            if ($text === '') {
                continue;
            }

            $searchable =
                Str::lower(
                    Str::ascii(
                        $text
                    )
                );

            /*
             * Evita interpretar um número
             * aleatório como CNPJ.
             */
            if (
                ! str_contains(
                    $searchable,
                    'cnpj'
                )
            ) {
                continue;
            }

            preg_match_all(
                '/(?<!\d)(?:\d{2}[\.\s-]?\d{3}[\.\s-]?\d{3}[\/\s-]?\d{4}[-\s]?\d{2}|\d{14})(?!\d)/u',
                $text,
                $matches
            );

            $fieldHasCnpj =
                false;

            foreach (
                $matches[0] as $match
            ) {

                $digits =
                    preg_replace(
                        '/\D+/',
                        '',
                        $match
                    )
                    ?? '';

                if (
                    ! $this->validCnpj(
                        $digits
                    )
                ) {
                    continue;
                }

                $cnpjs[] =
                    $digits;

                $fieldHasCnpj =
                    true;
            }

            if ($fieldHasCnpj) {
                $fields[] =
                    $field;
            }
        }

        $cnpjs =
            array_values(
                array_unique(
                    $cnpjs
                )
            );

        $roots = [];

        foreach ($cnpjs as $cnpj) {
            $roots[] =
                substr(
                    $cnpj,
                    0,
                    8
                );
        }

        return [
            'cnpjs' => $cnpjs,

            'roots' => array_values(
                array_unique(
                    $roots
                )
            ),

            'fields' => array_values(
                array_unique(
                    $fields
                )
            ),
        ];
    }

    private function validCnpj(
        string $cnpj
    ): bool {
        if (
            strlen(
                $cnpj
            ) !== 14
            || preg_match(
                '/^(\d)\1{13}$/',
                $cnpj
            ) === 1
        ) {
            return false;
        }

        $numbers =
            array_map(
                'intval',
                str_split(
                    $cnpj
                )
            );

        $firstDigit =
            $this->cnpjDigit(
                array_slice(
                    $numbers,
                    0,
                    12
                ),
                [
                    5, 4, 3, 2,
                    9, 8, 7, 6,
                    5, 4, 3, 2,
                ]
            );

        if (
            $numbers[12]
            !== $firstDigit
        ) {
            return false;
        }

        $secondDigit =
            $this->cnpjDigit(
                array_slice(
                    $numbers,
                    0,
                    13
                ),
                [
                    6, 5, 4, 3, 2,
                    9, 8, 7, 6,
                    5, 4, 3, 2,
                ]
            );

        return $numbers[13]
            === $secondDigit;
    }

    /**
     * @param  list<int>  $numbers
     * @param  list<int>  $weights
     */
    private function cnpjDigit(
        array $numbers,
        array $weights,
    ): int {
        $sum = 0;

        foreach (
            $numbers as $index => $number
        ) {
            $sum +=
                $number
                * $weights[
                    $index
                ];
        }

        $remainder =
            $sum % 11;

        return $remainder < 2
            ? 0
            : 11 - $remainder;
    }

    /**
     * @param  resource  $handle
     * @param  list<mixed>  $fields
     */
    private function csv(
        $handle,
        array $fields,
    ): void {
        fputcsv(
            $handle,
            $fields,
            ',',
            '"',
            ''
        );
    }
}
