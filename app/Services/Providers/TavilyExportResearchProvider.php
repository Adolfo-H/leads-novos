<?php

namespace App\Services\Providers;

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use App\Services\ExportResearchEntityMatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class TavilyExportResearchProvider implements ExportResearchProvider
{
    public function __construct(
        private readonly ExportResearchEntityMatcher $entityMatcher,
    ) {}

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
        $company->loadMissing([
            'matrix',
        ]);

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

                $titleRaw =
                    $result[
                        'title'
                    ]
                    ?? null;

                $title =
                    is_string(
                        $titleRaw
                    )
                    && trim(
                        $titleRaw
                    ) !== ''
                        ? trim(
                            $titleRaw
                        )
                        : null;

                /*
                 * Não classificamos uma página
                 * apenas porque contém palavras
                 * como exportação ou trading.
                 *
                 * Primeiro ela precisa estar
                 * relacionada à empresa.
                 */
                if (
                    ! $this->entityMatcher
                        ->matches(
                            company: $company,
                            title: $title,
                            content: $content,
                            url: $url,
                        )
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

                    'title' => $title,

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

        /*
         * Remove acentos e pontuação para que:
         *
         * "não exporta"
         *
         * seja comparável a:
         *
         * "nao exporta"
         */
        $normalized =
            preg_replace(
                '/[^a-z0-9]+/',
                ' ',
                $normalized
            )
            ?? $normalized;

        $normalized =
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    $normalized
                )
                ?? $normalized
            );

        /*
         * Frases que realmente representam
         * evidência positiva forte.
         */
        $strongRules =
            match ($dimension) {
                'direct' => [
                    'exporta para',
                    'exportou para',
                    'exportacoes para',
                    'vendas ao exterior',
                    'embarques para o exterior',
                    'exportacao direta',

                    'produz e exporta',
                    'comercializa e exporta',
                    'produz comercializa e exporta',

                    'we export',
                    'we produce market and export',
                    'export shipments',
                    'is an exporter',
                    'exporter and importer',
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

                    /*
                     * Mantemos os sinais internacionais
                     * já utilizados pelo provider.
                     */
                    'strong trading',
                    'trading capabilities',
                    'commodity trading',
                    'we trade with',
                ],

                default => [],
            };

        /*
         * Negação explícita.
         *
         * Uma fonte dizendo que a empresa NÃO
         * exporta é evidência negativa, e não
         * uma evidência positiva por conter a
         * palavra "exporta".
         */
        $negativeRules =
            match ($dimension) {
                'direct' => [
                    'nao exporta',
                    'nao exportou',
                    'nao realiza exportacao',
                    'nao realiza exportacoes',
                    'nao possui exportacao',
                    'sem exportacao direta',
                    'nao vende ao exterior',
                    'nao vende para o exterior',

                    'does not export',
                    'do not export',
                    'did not export',
                    'not an exporter',
                    'no export activity',
                ],

                'indirect' => [
                    'nao realiza exportacao indireta',
                    'sem exportacao indireta',

                    'nao realiza venda com fim especifico',
                    'nao utiliza venda com fim especifico',

                    'nao realiza remessa com fim especifico',
                    'sem venda com fim especifico',
                ],

                'trading' => [
                    'nao opera com trading',
                    'nao realiza operacao com trading',
                    'nao vende para trading',
                    'nao utiliza trading',
                    'sem operacao com trading',

                    'does not use a trading company',
                    'does not work with trading companies',
                ],

                default => [],
            };

        $matchedPositive =
            null;

        $matchedNegative =
            null;

        /*
         * Primeiro detectamos negativas
         * explícitas.
         */
        foreach (
            $negativeRules as $rule
        ) {
            if (
                str_contains(
                    $normalized,
                    $rule
                )
            ) {
                $matchedNegative =
                    $rule;

                break;
            }
        }

        /*
         * Depois analisamos cada frase positiva.
         *
         * Não basta encontrar:
         *
         * "exporta para"
         *
         * porque ela pode fazer parte de:
         *
         * "não exporta para".
         */
        foreach (
            $strongRules as $rule
        ) {
            [
                $hasPositiveOccurrence,
                $hasNegatedOccurrence,
            ] =
                $this
                    ->rulePolarity(
                        text: $normalized,

                        rule: $rule,
                    );

            if (
                $hasNegatedOccurrence
                && $matchedNegative
                    === null
            ) {
                $matchedNegative =
                    'negated:'
                    .$rule;
            }

            if (
                $hasPositiveOccurrence
                && $matchedPositive
                    === null
            ) {
                $matchedPositive =
                    $rule;
            }
        }

        $normalizedScore =
            max(
                0.0,
                min(
                    1.0,
                    $score
                )
            );

        /*
         * O score do Tavily ajuda a medir
         * relevância da página.
         *
         * Ele não é tratado como probabilidade
         * estatística da conclusão.
         */
        $strongConfidence =
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
            );

        /*
         * Exemplo:
         *
         * "A empresa não exporta atualmente.
         *  No passado exportou para a Europa."
         *
         * Existem sinais opostos.
         *
         * O sistema NÃO escolhe sozinho:
         * fica neutro/incerto.
         */
        if (
            $matchedPositive
                !== null
            && $matchedNegative
                !== null
        ) {
            return [
                'neutral',

                min(
                    55,
                    max(
                        40,
                        $strongConfidence
                        - 25
                    )
                ),

                'conflict:'
                .$matchedNegative
                .'|'
                .$matchedPositive,
            ];
        }

        if (
            $matchedNegative
                !== null
        ) {
            return [
                'negative',

                $strongConfidence,

                $matchedNegative,
            ];
        }

        if (
            $matchedPositive
                !== null
        ) {
            return [
                'positive',

                $strongConfidence,

                $matchedPositive,
            ];
        }

        /*
         * Encontramos uma página relacionada,
         * mas não existe sinal forte.
         *
         * Ausência de evidência NÃO significa
         * evidência negativa.
         */
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

    /**
     * Analisa todas as ocorrências de uma regra.
     *
     * Retorno:
     *
     * [0] existe ocorrência positiva
     * [1] existe ocorrência negada
     *
     * @return array{0: bool, 1: bool}
     */
    private function rulePolarity(
        string $text,
        string $rule,
    ): array {
        $positive =
            false;

        $negative =
            false;

        $offset =
            0;

        while (
            (
                $position =
                    strpos(
                        $text,
                        $rule,
                        $offset
                    )
            )
            !== false
        ) {
            /*
             * Pegamos apenas uma janela local
             * antes da frase.
             *
             * Isso evita que uma negação muito
             * distante contamine outra sentença.
             */
            $before =
                substr(
                    $text,
                    max(
                        0,
                        $position - 80
                    ),
                    min(
                        80,
                        $position
                    )
                );

            $tokens =
                preg_split(
                    '/\s+/',
                    trim(
                        $before
                    )
                )
                ?: [];

            /*
             * Cinco palavras é suficiente para:
             *
             * "não realiza qualquer exportação direta"
             *
             * sem carregar facilmente a negação
             * de outra frase anterior.
             */
            $window =
                implode(
                    ' ',
                    array_slice(
                        $tokens,
                        -5
                    )
                );

            /*
             * Exceção:
             *
             * "não só exporta para..."
             *
             * é uma afirmação positiva.
             */
            $notOnly =
                preg_match(
                    '/(?:^| )(?:nao so|not only)(?: |$)/',
                    $window
                ) === 1;

            $isNegated =
                ! $notOnly
                && preg_match(
                    '/(?:^| )(?:nao|nunca|jamais|sem|not|never|no)(?: |$)/',
                    $window
                ) === 1;

            if ($isNegated) {
                $negative =
                    true;
            } else {
                $positive =
                    true;
            }

            $offset =
                $position
                + max(
                    1,
                    strlen(
                        $rule
                    )
                );
        }

        return [
            $positive,
            $negative,
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
