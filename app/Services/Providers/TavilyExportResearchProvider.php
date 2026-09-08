<?php

namespace App\Services\Providers;

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class TavilyExportResearchProvider implements ExportResearchProvider
{
    public function name(): string
    {
        return 'tavily';
    }

    /**
     * @param  list<string>  $queries
     * @return list<array{
     *     dimension: string,
     *     signal: string,
     *     confidence: int,
     *     source_type: string,
     *     source_name: string|null,
     *     source_url: string|null,
     *     title: string|null,
     *     evidence_text: string,
     *     metadata: array<string, mixed>
     * }>
     */
    public function research(
        Company $company,
        array $queries,
    ): array {
        $apiKey =
            config(
                'services.tavily.api_key'
            );

        if (
            ! is_string($apiKey)
            || trim($apiKey) === ''
        ) {
            throw new RuntimeException(
                'TAVILY_API_KEY não configurada.'
            );
        }

        $baseUrl =
            rtrim(
                (string) config(
                    'services.tavily.base_url',
                    'https://api.tavily.com'
                ),
                '/'
            );

        $maxResults =
            max(
                1,
                min(
                    10,
                    (int) config(
                        'services.tavily.max_results',
                        5
                    )
                )
            );

        /*
         * O planner atual organiza:
         *
         * 0..2 = direta
         * 3..5 = indireta
         * 6..7 = trading
         *
         * Para economizar usamos apenas
         * uma busca por dimensão.
         */
        $plannedQueries =
            $this->selectQueries(
                $queries
            );

        $findings = [];

        /** @var array<string, true> $seen */
        $seen = [];

        foreach (
            $plannedQueries as $planned
        ) {
            $dimension =
                $planned[
                    'dimension'
                ];

            $query =
                $planned[
                    'query'
                ];

            $response =
                Http::acceptJson()
                    ->asJson()
                    ->withToken(
                        $apiKey
                    )
                    ->timeout(30)
                    ->retry(
                        2,
                        500,
                        throw: false
                    )
                    ->post(
                        $baseUrl
                        .'/search',
                        [
                            'query' => $query,

                            'search_depth' => 'basic',

                            'max_results' => $maxResults,

                            'include_answer' => false,

                            'include_raw_content' => false,
                        ]
                    );

            if ($response->failed()) {
                throw new RuntimeException(
                    'Erro Tavily: HTTP '
                    .$response->status()
                );
            }

            $data =
                $response->json();

            if (! is_array($data)) {
                throw new RuntimeException(
                    'Resposta inválida do Tavily.'
                );
            }

            $results =
                $data[
                    'results'
                ]
                ?? [];

            if (! is_array($results)) {
                continue;
            }

            foreach (
                $results as $result
            ) {
                if (! is_array($result)) {
                    continue;
                }

                $url =
                    $result[
                        'url'
                    ]
                    ?? null;

                $content =
                    $result[
                        'content'
                    ]
                    ?? null;

                if (
                    ! is_string($url)
                    || trim($url) === ''
                    || ! is_string($content)
                    || trim($content) === ''
                ) {
                    continue;
                }

                $normalizedUrl =
                    $this->normalizeUrl(
                        $url
                    );

                $fingerprint =
                    $dimension
                    .'|'
                    .$normalizedUrl;

                if (
                    isset(
                        $seen[
                            $fingerprint
                        ]
                    )
                ) {
                    continue;
                }

                $seen[
                    $fingerprint
                ] = true;

                $rawScore =
                    $result[
                        'score'
                    ]
                    ?? 0;

                $score =
                    is_numeric(
                        $rawScore
                    )
                        ? (float) $rawScore
                        : 0.0;

                [
                    $signal,
                    $confidence,
                    $matchedRule,
                ] =
                    $this->classify(
                        dimension: $dimension,

                        content: $content,

                        score: $score,
                    );

                $host =
                    parse_url(
                        $url,
                        PHP_URL_HOST
                    );

                $findings[] = [
                    'dimension' => $dimension,

                    'signal' => $signal,

                    'confidence' => $confidence,

                    'source_type' => $this->sourceType(
                        is_string($host)
                            ? $host
                            : null
                    ),

                    'source_name' => is_string($host)
                            ? $host
                            : null,

                    'source_url' => $url,

                    'title' => isset(
                        $result[
                            'title'
                        ]
                    )
                        && is_string(
                            $result[
                                'title'
                            ]
                        )
                            ? trim(
                                $result[
                                    'title'
                                ]
                            )
                            : null,

                    'evidence_text' => Str::limit(
                        trim(
                            $content
                        ),
                        800,
                        ''
                    ),

                    'metadata' => [
                        'provider' => $this->name(),

                        'matched_query' => $query,

                        'tavily_score' => $score,

                        'search_depth' => 'basic',

                        'matched_rule' => $matchedRule,
                    ],
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $queries
     * @return list<array{
     *     dimension: string,
     *     query: string
     * }>
     */
    private function selectQueries(
        array $queries
    ): array {
        $mapping = [
            'direct' => 0,

            'indirect' => 3,

            'trading' => 6,
        ];

        $selected = [];

        foreach (
            $mapping as $dimension => $index
        ) {
            if (
                ! isset(
                    $queries[
                        $index
                    ]
                )
            ) {
                continue;
            }

            $selected[] = [
                'dimension' => $dimension,

                'query' => $queries[
                        $index
                    ],
            ];
        }

        return $selected;
    }

    /**
     * @return array{
     *     0: string,
     *     1: int,
     *     2: string|null
     * }
     */
    private function classify(
        string $dimension,
        string $content,
        float $score,
    ): array {
        $normalized =
            mb_strtolower(
                Str::ascii(
                    $content
                )
            );

        $strongRules =
            match ($dimension) {
                'direct' => [
                    'exporta para',
                    'exportou para',
                    'exportacoes para',
                    'vendas ao exterior',
                    'embarques para o exterior',
                    'exportacao direta',
                ],

                'indirect' => [
                    'fim especifico de exportacao',
                    'venda com fim especifico',
                    'remessa com fim especifico',
                    'exportacao indireta',
                ],

                'trading' => [
                    'trading company',
                    'venda para trading',
                    'operacao com trading',
                    'comercial exportadora',
                ],

                default => [],
            };

        foreach (
            $strongRules as $rule
        ) {
            if (
                str_contains(
                    $normalized,
                    $rule
                )
            ) {
                $normalizedScore =
                    max(
                        0.0,
                        min(
                            1.0,
                            $score
                        )
                    );

                return [
                    'positive',

                    min(
                        90,
                        max(
                            65,
                            (int) round(
                                70
                                + (
                                    $normalizedScore
                                    * 20
                                )
                            )
                        )
                    ),

                    $rule,
                ];
            }
        }

        /*
         * Encontramos uma página relacionada,
         * mas não existe sinal forte suficiente.
         *
         * Ausência de evidência NÃO vira
         * evidência negativa.
         */
        $normalizedScore =
            max(
                0.0,
                min(
                    1.0,
                    $score
                )
            );

        return [
            'neutral',

            min(
                55,
                max(
                    30,
                    (int) round(
                        35
                        + (
                            $normalizedScore
                            * 20
                        )
                    )
                )
            ),

            null,
        ];
    }

    private function normalizeUrl(
        string $url
    ): string {
        $url =
            trim(
                $url
            );

        $withoutFragment =
            preg_replace(
                '/#.*$/',
                '',
                $url
            );

        if (
            $withoutFragment
            !== null
        ) {
            $url =
                $withoutFragment;
        }

        return mb_strtolower(
            rtrim(
                $url,
                '/'
            )
        );
    }

    private function sourceType(
        ?string $host
    ): string {
        if (! $host) {
            return 'other';
        }

        $host =
            mb_strtolower(
                $host
            );

        if (
            $host === 'gov.br'
            || str_ends_with(
                $host,
                '.gov.br'
            )
        ) {
            return 'government';
        }

        return 'other';
    }
}
