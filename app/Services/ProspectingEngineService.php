<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;

final class ProspectingEngineService
{
    public function __construct(
        private readonly ProspectingDiscoveryService $discovery,
        private readonly CnpjImportService $import,
        private readonly ImportQueueService $queue,
    ) {}

    /**
     * @param  list<string>  $states
     * @param  list<string>  $cnaes
     * @param  list<string>|null  $sizeCodes
     * @return array{
     *     items: list<array<string, mixed>>,
     *     discovered_count: int,
     *     known_count: int,
     *     new_count: int,
     *     filters: array<string, mixed>
     * }
     */
    public function preview(
        int $limit,
        array $states,
        array $cnaes,
        ?float $minCapital = null,
        ?array $sizeCodes = null,
        int $offset = 0,
    ): array {
        $limit =
            max(
                1,
                min(
                    200,
                    $limit
                )
            );

        $offset =
            max(
                0,
                $offset
            );

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

        $lastFilters = [];

        /*
         * Memória do próprio motor:
         *
         * mesmo que uma empresa ainda esteja
         * em processamento, não oferecemos
         * novamente em outra execução.
         */
        for (
            $page = 0;
            $page < $maxPages;
            $page++
        ) {
            $pageResult =
                $this->discovery->discover(
                    limit: $pageSize,
                    offset: $offset
                        + (
                            $page
                            * $pageSize
                        ),
                    states: $states,
                    cnaes: $cnaes,
                    minCapital: $minCapital,
                    sizeCodes: $sizeCodes,
                );

            $lastFilters =
                $pageResult[
                    'filters'
                ];

            if (
                $pageResult[
                    'items'
                ] === []
            ) {
                break;
            }

            foreach (
                $pageResult[
                    'items'
                ] as $item
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
             * Buscamos mais candidatos do que
             * o solicitado porque empresas já
             * conhecidas serão removidas depois.
             */
            if (
                count($items)
                >= $limit * 4
            ) {
                $rootsSoFar =
                    array_keys(
                        $seenRoots
                    );

                $knownRootsSoFar =
                    $this->knownRoots(
                        $rootsSoFar
                    );

                $knownLookupSoFar =
                    array_fill_keys(
                        $knownRootsSoFar,
                        true
                    );

                $newCountSoFar =
                    count(
                        array_filter(
                            $items,
                            static fn (
                                array $item
                            ): bool => ! isset(
                                $knownLookupSoFar[
                                    $item[
                                        'cnpj_root'
                                    ]
                                ]
                            )
                        )
                    );

                if (
                    $newCountSoFar
                    >= $limit
                ) {
                    break;
                }
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

        if ($items === []) {
            return [
                'items' => [],
                'discovered_count' => 0,
                'known_count' => 0,
                'new_count' => 0,
                'filters' => $lastFilters,
            ];
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

        $knownRoots =
            $this->knownRoots(
                $roots
            );

        $knownLookup =
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
                        $knownLookup[
                            $item[
                                'cnpj_root'
                            ]
                        ]
                    )
                )
            );

        $newItems =
            array_slice(
                $newItems,
                0,
                $limit
            );

        $knownInDiscovery =
            count(
                array_filter(
                    $roots,
                    static fn (
                        string $root
                    ): bool => isset(
                        $knownLookup[
                            $root
                        ]
                    )
                )
            );

        return [
            'items' => $newItems,

            'discovered_count' => count(
                $items
            ),

            'known_count' => $knownInDiscovery,

            'new_count' => count(
                $newItems
            ),

            'filters' => $lastFilters,
        ];
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function knownRoots(
        array $roots
    ): array {
        if ($roots === []) {
            return [];
        }

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

        $prospectedRoots =
            ImportItem::query()
                ->whereIn(
                    'cnpj_root',
                    $roots
                )
                ->whereHas(
                    'batch',
                    fn ($query) => $query->where(
                        'source_type',
                        'prospecting'
                    )
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
                ->distinct()
                ->pluck(
                    'cnpj_root'
                )
                ->map(
                    static fn (
                        mixed $value
                    ): string => (string) $value
                )
                ->filter(
                    static fn (
                        string $value
                    ): bool => $value !== ''
                )
                ->values()
                ->all();

        return array_values(
            array_unique(
                array_merge(
                    $companyRoots,
                    $prospectedRoots,
                )
            )
        );
    }

    /**
     * @param  list<string>  $states
     * @param  list<string>  $cnaes
     * @param  list<string>|null  $sizeCodes
     * @return array{
     *     batch: ImportBatch|null,
     *     dispatched: int,
     *     preview: array{
     *         items: list<array<string, mixed>>,
     *         discovered_count: int,
     *         known_count: int,
     *         new_count: int,
     *         filters: array<string, mixed>
     *     }
     * }
     */
    public function execute(
        int $limit,
        array $states,
        array $cnaes,
        ?int $userId = null,
        ?float $minCapital = null,
        ?array $sizeCodes = null,
        int $offset = 0,
    ): array {
        /*
         * Refazemos o preview no momento da
         * execução.
         *
         * Assim uma empresa que entrou em
         * outro lote segundos antes não será
         * duplicada.
         */
        $preview =
            $this->preview(
                limit: $limit,
                states: $states,
                cnaes: $cnaes,
                minCapital: $minCapital,
                sizeCodes: $sizeCodes,
                offset: $offset,
            );

        if (
            $preview[
                'items'
            ] === []
        ) {
            return [
                'batch' => null,
                'dispatched' => 0,
                'preview' => $preview,
            ];
        }

        $cnpjs =
            array_map(
                static fn (
                    array $item
                ): string => (string) $item[
                        'cnpj'
                    ],
                $preview[
                    'items'
                ]
            );

        $batch =
            $this->import->import(
                values: $cnpjs,
                userId: $userId,
                sourceType: 'prospecting',
                filename: null,
            );

        $batch->update([
            'metadata' => [
                'prospecting' => [
                    'discovered_count' => $preview[
                            'discovered_count'
                        ],

                    'known_roots_count' => $preview[
                            'known_count'
                        ],

                    'new_candidates_count' => $preview[
                            'new_count'
                        ],

                    'filters' => $preview[
                            'filters'
                        ],

                    'limit' => $limit,

                    'offset' => $offset,

                    'generated_at' => now()
                        ->toIso8601String(),
                ],
            ],
        ]);

        $dispatched =
            $this->queue->dispatchReady(
                $batch
            );

        $this->queue->refreshBatch(
            $batch->id
        );

        return [
            'batch' => $batch->refresh(),

            'dispatched' => $dispatched,

            'preview' => $preview,
        ];
    }
}
