<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ImportItem;
use App\Services\CnpjImportService;
use App\Services\ImportQueueService;
use App\Services\ProspectingDiscoveryService;
use Illuminate\Console\Command;

class RunProspecting extends Command
{
    protected $signature =
        'prospecting:run
        {--limit=100 : Quantidade de candidatos}
        {--offset=0 : Posição inicial na descoberta}
        {--states= : UFs separadas por vírgula}
        {--cnaes= : CNAEs separados por vírgula}
        {--min-capital= : Capital social mínimo}
        {--size-codes= : Portes separados por vírgula}
        {--execute : Cria o lote e envia para processamento}';

    protected $description =
        'Descobre empresas na Receita e alimenta o pipeline de prospecção.';

    public function handle(
        ProspectingDiscoveryService $discovery,
        CnpjImportService $import,
        ImportQueueService $queue,
    ): int {
        $limit =
            max(
                1,
                min(
                    1000,
                    (int) $this->option(
                        'limit'
                    )
                )
            );

        $offset =
            max(
                0,
                (int) $this->option(
                    'offset'
                )
            );

        $minCapitalOption =
            trim(
                (string) $this->option(
                    'min-capital'
                )
            );

        $minCapital =
            $minCapitalOption !== ''
                ? max(
                    0,
                    (float) $minCapitalOption
                )
                : null;

        /*
         * O motor não pode ficar preso aos
         * primeiros resultados da Receita.
         *
         * Vasculhamos páginas sucessivas até
         * reunir a quantidade solicitada de
         * candidatos inéditos ou atingir o
         * limite seguro de varredura.
         */
        $pageSize =
            max(
                50,
                min(
                    250,
                    $limit * 2
                )
            );

        $maxPages = 20;

        $items = [];

        $seenRoots = [];

        $lastResult = [
            'items' => [],
            'count' => 0,
            'limit' => $pageSize,
            'offset' => $offset,
            'filters' => [],
        ];

        for (
            $page = 0;
            $page < $maxPages;
            $page++
        ) {
            $pageOffset =
                $offset
                + (
                    $page
                    * $pageSize
                );

            $pageResult =
                $discovery->discover(
                    limit: $pageSize,
                    offset: $pageOffset,
                    states: $this->csvOption(
                        'states'
                    ),
                    cnaes: $this->csvOption(
                        'cnaes'
                    ),
                    minCapital: $minCapital,
                    sizeCodes: $this->csvOption(
                        'size-codes'
                    ),
                );

            $lastResult =
                $pageResult;

            if (
                $pageResult['items']
                === []
            ) {
                break;
            }

            foreach (
                $pageResult['items'] as $item
            ) {
                $root =
                    $item[
                        'cnpj_root'
                    ];

                if (
                    isset(
                        $seenRoots[
                            $root
                        ]
                    )
                ) {
                    continue;
                }

                $seenRoots[
                    $root
                ] = true;

                $items[] =
                    $item;
            }

            /*
             * Buscamos mais que o limite
             * solicitado porque ainda vamos
             * remover empresas já conhecidas.
             */
            if (
                count($items)
                >= $limit * 4
            ) {
                break;
            }

            if (
                count(
                    $pageResult[
                        'items'
                    ]
                )
                < $pageSize
            ) {
                break;
            }
        }

        $result = [
            'items' => $items,

            'count' => count($items),

            'limit' => $limit,

            'offset' => $offset,

            'filters' => $lastResult[
                    'filters'
                ],
        ];

        if ($items === []) {
            $this->components->info(
                'Nenhum candidato encontrado.'
            );

            return self::SUCCESS;
        }

        $roots =
            array_values(
                array_unique(
                    array_map(
                        static fn (
                            array $item
                        ): string => $item[
                                'cnpj_root'
                            ],
                        $items
                    )
                )
            );

        /*
         * Memória 1:
         * empresas que já chegaram ao cadastro
         * principal do Prospector.
         */
        $companyRoots =
            Company::query()
                ->whereIn(
                    'cnpj_root',
                    $roots
                )
                ->pluck(
                    'cnpj_root'
                )
                ->map(
                    static fn (
                        mixed $value
                    ): string => (string) $value
                )
                ->all();

        /*
         * Memória 2:
         * empresas já enviadas anteriormente
         * pelo próprio motor, mesmo que o job
         * ainda esteja processando.
         */
        $prospectedRoots =
            ImportItem::query()
                ->whereHas(
                    'batch',
                    fn ($query) => $query->where(
                        'source_type',
                        'prospecting'
                    )
                )
                ->whereNotNull(
                    'normalized_cnpj'
                )
                ->whereIn(
                    'status',
                    [
                        'ready',
                        'queued',
                        'processing',
                        'completed',
                        'existing',
                    ]
                )
                ->get([
                    'normalized_cnpj',
                ])
                ->map(
                    static function (
                        ImportItem $item
                    ): string {
                        return mb_substr(
                            (string)
                                $item
                                    ->normalized_cnpj,
                            0,
                            8
                        );
                    }
                )
                ->filter()
                ->unique()
                ->values()
                ->all();

        $knownRoots =
            array_values(
                array_unique(
                    array_merge(
                        $companyRoots,
                        $prospectedRoots,
                    )
                )
            );

        $existingLookup =
            array_fill_keys(
                $knownRoots,
                true
            );

        $newItems =
            array_values(
                array_filter(
                    $items,
                    static fn (
                        array $item
                    ): bool => ! isset(
                        $existingLookup[
                            $item[
                                'cnpj_root'
                            ]
                        ]
                    )
                )
            );

        /*
         * A varredura pode trazer muito mais
         * candidatos do que o solicitado.
         *
         * Depois de remover tudo que já é
         * conhecido, ficamos somente com a
         * quantidade pedida pelo operador.
         */
        $newItems =
            array_slice(
                $newItems,
                0,
                $limit
            );

        $rows = [];

        foreach (
            array_slice(
                $items,
                0,
                20
            ) as $item
        ) {
            $capital =
                $item[
                    'share_capital'
                ];

            $rows[] = [
                $item['cnpj'],

                mb_substr(
                    $item[
                        'corporate_name'
                    ],
                    0,
                    45
                ),

                $item['state']
                    ?? '—',

                $item['matched_cnae']
                    ?? '—',

                (
                    $item[
                        'cnae_match_type'
                    ]
                    ?? null
                ) === 'primary'
                    ? 'Principal'
                    : 'Secundário',

                $item[
                    'discovery_score'
                ]
                    ?? '—',

                $item[
                    'active_establishments'
                ],

                $capital !== null
                    ? 'R$ '
                        .number_format(
                            $capital,
                            0,
                            ',',
                            '.'
                        )
                    : '—',

                isset(
                    $existingLookup[
                        $item[
                            'cnpj_root'
                        ]
                    ]
                )
                    ? 'Já local'
                    : 'Novo',
            ];
        }

        $this->table(
            [
                'CNPJ',
                'Empresa',
                'UF',
                'CNAE',
                'Tipo',
                'Pré-ICP',
                'Unid.',
                'Capital',
                'Situação',
            ],
            $rows
        );

        $this->newLine();

        $this->line(
            'Descobertos: '
            .count($items)
        );

        $this->line(
            'Já existentes no Prospector: '
            .count($knownRoots)
        );

        $this->line(
            'Novos candidatos: '
            .count($newItems)
        );

        if (
            ! (bool) $this->option(
                'execute'
            )
        ) {
            $this->newLine();

            $this->components->warn(
                'Pré-visualização apenas. '
                .'Use --execute para criar '
                .'o lote de prospecção.'
            );

            return self::SUCCESS;
        }

        if ($newItems === []) {
            $this->components->info(
                'Nenhuma empresa nova para processar.'
            );

            return self::SUCCESS;
        }

        $cnpjs =
            array_map(
                static fn (
                    array $item
                ): string => $item['cnpj'],
                $newItems
            );

        $batch =
            $import->import(
                values: $cnpjs,
                userId: null,
                sourceType: 'prospecting',
                filename: null,
            );

        $batch->update([
            'metadata' => [
                'prospecting' => [
                    'discovered_count' => count($items),

                    'existing_roots_count' => count(
                        $knownRoots
                    ),

                    'new_candidates_count' => count(
                        $newItems
                    ),

                    'filters' => $result[
                            'filters'
                        ],

                    'limit' => $result[
                            'limit'
                        ],

                    'offset' => $result[
                            'offset'
                        ],

                    'generated_at' => now()
                        ->toIso8601String(),
                ],
            ],
        ]);

        $dispatched =
            $queue->dispatchReady(
                $batch
            );

        /*
         * Garante estado correto inclusive
         * quando nenhum item terminou como
         * "ready".
         */
        $queue->refreshBatch(
            $batch->id
        );

        $batch->refresh();

        $this->newLine();

        $this->components->info(
            'Lote de prospecção criado.'
        );

        $this->line(
            'Lote: '
            .$batch->uuid
        );

        $this->line(
            'Candidatos enviados à fila: '
            .$dispatched
        );

        $this->line(
            'Status do lote: '
            .$batch->status
        );

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function csvOption(
        string $name
    ): ?array {
        $rawValue =
            $this->option(
                $name
            );

        $value =
            is_scalar(
                $rawValue
            )
                ? trim(
                    (string) $rawValue
                )
                : '';

        if ($value === '') {
            return null;
        }

        $parts =
            array_values(
                array_filter(
                    array_map(
                        static fn (
                            string $item
                        ): string => trim(
                            $item
                        ),
                        explode(
                            ',',
                            $value
                        )
                    ),
                    static fn (
                        string $item
                    ): bool => $item !== ''
                )
            );

        return $parts !== []
            ? $parts
            : null;
    }
}
