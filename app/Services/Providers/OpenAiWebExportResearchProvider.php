<?php

namespace App\Services\Providers;

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiWebExportResearchProvider implements ExportResearchProvider
{
    public function name(): string
    {
        return 'openai-web-search';
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
                'services.openai.api_key'
            );

        if (
            ! is_string($apiKey)
            || trim($apiKey) === ''
        ) {
            throw new RuntimeException(
                'OPENAI_API_KEY não configurada.'
            );
        }

        $baseUrl =
            (string) config(
                'services.openai.base_url',
                'https://api.openai.com/v1'
            );

        $model =
            (string) config(
                'services.openai.export_research_model',
                'gpt-5.6-terra'
            );

        $response =
            $this->client(
                $apiKey
            )->post(
                rtrim(
                    $baseUrl,
                    '/'
                ).'/responses',
                [
                    'model' => $model,

                    'tools' => [
                        [
                            'type' => 'web_search',
                        ],
                    ],

                    'instructions' => $this->instructions(),

                    'input' => $this->prompt(
                        $company,
                        $queries
                    ),

                    'text' => [
                    'format' => [
                        'type' => 'json_schema',

                        'name' => 'export_research',

                        'strict' => true,

                        'schema' => $this->schema(),
                    ],
                    ],
                ]
            );

        $response->throw();

        $body =
            $response->json();

        if (! is_array($body)) {
            throw new RuntimeException(
                'Resposta inválida da OpenAI.'
            );
        }

        $outputText =
            $this->outputText(
                $body
            );

        $decoded =
            json_decode(
                $outputText,
                true
            );

        if (
            ! is_array($decoded)
            || ! isset(
                $decoded['findings']
            )
            || ! is_array(
                $decoded['findings']
            )
        ) {
            throw new RuntimeException(
                'Findings estruturados '
                .'não foram retornados.'
            );
        }

        /*
         * URLs efetivamente citadas pelo
         * web_search.
         *
         * Uma URL escrita somente no JSON
         * do modelo NÃO é aceita como prova.
         */
        $citedUrls =
            $this->citedUrls(
                $body
            );

        $results = [];

        foreach (
            $decoded['findings'] as $finding
        ) {
            if (! is_array($finding)) {
                continue;
            }

            $sourceUrl =
                $finding[
                    'source_url'
                ] ?? null;

            if (! is_string($sourceUrl)) {
                continue;
            }

            $normalizedUrl =
                $this->normalizeUrl(
                    $sourceUrl
                );

            if (
                ! isset(
                    $citedUrls[
                        $normalizedUrl
                    ]
                )
            ) {
                /*
                 * O modelo mencionou uma URL
                 * que não aparece nas fontes
                 * reais da pesquisa.
                 */
                continue;
            }

            $dimension =
                $finding[
                    'dimension'
                ] ?? null;

            $signal =
                $finding[
                    'signal'
                ] ?? null;

            $confidence =
                $finding[
                    'confidence'
                ] ?? null;

            if (
                ! is_string($dimension)
                || ! in_array(
                    $dimension,
                    [
                        'direct',
                        'indirect',
                        'trading',
                    ],
                    true
                )
            ) {
                continue;
            }

            if (
                ! is_string($signal)
                || ! in_array(
                    $signal,
                    [
                        'positive',
                        'negative',
                        'neutral',
                    ],
                    true
                )
            ) {
                continue;
            }

            if (! is_int($confidence)) {
                continue;
            }

            $evidenceText =
                $finding[
                    'evidence_text'
                ] ?? null;

            if (
                ! is_string($evidenceText)
                || trim($evidenceText) === ''
            ) {
                continue;
            }

            $results[] = [
                'dimension' => $dimension,

                'signal' => $signal,

                'confidence' => max(
                    0,
                    min(
                        100,
                        $confidence
                    )
                ),

                'source_type' => $this->sourceType(
                    $finding[
                        'source_type'
                    ] ?? null
                ),

                'source_name' => $this->nullableString(
                    $finding[
                        'source_name'
                    ] ?? null
                ),

                /*
                 * Salvamos a URL encontrada
                 * entre as citações reais.
                 */
                'source_url' => $citedUrls[
                        $normalizedUrl
                    ],

                'title' => $this->nullableString(
                    $finding[
                        'title'
                    ] ?? null
                ),

                'evidence_text' => trim(
                    $evidenceText
                ),

                'metadata' => [
                'provider' => $this->name(),

                'model' => $model,

                'openai_response_id' => $body['id']
                    ?? null,

                'source_validated' => true,

                'matched_query' => $finding[
                        'matched_query'
                    ] ?? null,
                ],
            ];
        }

        return $results;
    }

    private function client(
        string $apiKey
    ): PendingRequest {
        return Http::acceptJson()
            ->asJson()
            ->withToken(
                $apiKey
            )
            ->timeout(
                180
            );
    }

    private function instructions(): string
    {
        return <<<'TEXT'
Você é um pesquisador B2B especializado em exportações brasileiras.

Pesquise evidências públicas verificáveis sobre a empresa.

Dimensões:

direct:
exportação realizada pela própria empresa.

indirect:
venda com fim específico de exportação ou operação indireta em que trading/comercial exportadora realiza a exportação.

trading:
relação comercial concreta com trading ou comercial exportadora.

Regras:

- Não conclua exportação apenas por CNAE.
- Não conclua exportação apenas porque a empresa atua no agronegócio.
- Não conclua exportação apenas porque menciona mercado internacional.
- Não invente fontes.
- Ausência de evidência não é evidência negativa.
- Use negative apenas quando uma fonte afirmar algo contrário de forma explícita.
- Se houver apenas indício, use neutral.
- Cada finding deve possuir uma fonte efetivamente pesquisada.
- source_url deve corresponder à página pesquisada.
- evidence_text deve ser uma paráfrase factual curta.
- Priorize fontes governamentais, site oficial da empresa e veículos confiáveis.
TEXT;
    }

    /**
     * @param  list<string>  $queries
     */
    private function prompt(
        Company $company,
        array $queries,
    ): string {
        $lines = [
            'Empresa:',
            $company->corporate_name,

            '',
            'Raiz do CNPJ:',
            $company->cnpj_root,

            '',
            'Consultas sugeridas:',
        ];

        foreach (
            $queries as $query
        ) {
            $lines[] =
                '- '.$query;
        }

        $lines[] = '';
        $lines[] =
            'Pesquise fontes públicas e '
            .'retorne somente evidências '
            .'relevantes.';

        return implode(
            PHP_EOL,
            $lines
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',

            'additionalProperties' => false,

            'properties' => [
                'findings' => [
                    'type' => 'array',

                    'items' => [
                        'type' => 'object',

                        'additionalProperties' => false,

                        'properties' => [
                            'dimension' => [
                                'type' => 'string',

                                'enum' => [
                                    'direct',
                                    'indirect',
                                    'trading',
                                ],
                            ],

                            'signal' => [
                                'type' => 'string',

                                'enum' => [
                                    'positive',
                                    'negative',
                                    'neutral',
                                ],
                            ],

                            'confidence' => [
                                'type' => 'integer',

                                'minimum' => 0,

                                'maximum' => 100,
                            ],

                            'source_type' => [
                                'type' => 'string',

                                'enum' => [
                                    'government',
                                    'company_site',
                                    'news',
                                    'industry',
                                    'other',
                                ],
                            ],

                            'source_name' => [
                                'type' => 'string',
                            ],

                            'source_url' => [
                                'type' => 'string',
                            ],

                            'title' => [
                                'type' => 'string',
                            ],

                            'evidence_text' => [
                                'type' => 'string',
                            ],

                            'matched_query' => [
                                'type' => 'string',
                            ],
                        ],

                        'required' => [
                            'dimension',
                            'signal',
                            'confidence',
                            'source_type',
                            'source_name',
                            'source_url',
                            'title',
                            'evidence_text',
                            'matched_query',
                        ],
                    ],
                ],
            ],

            'required' => [
                'findings',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function outputText(
        array $body
    ): string {
        $output =
            $body['output']
            ?? null;

        if (! is_array($output)) {
            throw new RuntimeException(
                'Output ausente.'
            );
        }

        foreach (
            $output as $item
        ) {
            if (
                ! is_array($item)
                || (
                    $item['type']
                    ?? null
                ) !== 'message'
            ) {
                continue;
            }

            $content =
                $item['content']
                ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach (
                $content as $part
            ) {
                if (
                    is_array($part)
                    && (
                        $part['type']
                        ?? null
                    ) === 'output_text'
                    && is_string(
                        $part['text']
                        ?? null
                    )
                ) {
                    return $part['text'];
                }
            }
        }

        throw new RuntimeException(
            'output_text não encontrado.'
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    private function citedUrls(
        array $body
    ): array {
        $urls = [];

        $output =
            $body['output']
            ?? null;

        if (! is_array($output)) {
            return [];
        }

        foreach (
            $output as $item
        ) {
            if (! is_array($item)) {
                continue;
            }

            $content =
                $item['content']
                ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach (
                $content as $part
            ) {
                if (! is_array($part)) {
                    continue;
                }

                $annotations =
                    $part[
                        'annotations'
                    ] ?? null;

                if (! is_array($annotations)) {
                    continue;
                }

                foreach (
                    $annotations as $annotation
                ) {
                    if (
                        ! is_array(
                            $annotation
                        )
                    ) {
                        continue;
                    }

                    if (
                        (
                            $annotation[
                                'type'
                            ] ?? null
                        ) !== 'url_citation'
                    ) {
                        continue;
                    }

                    $url =
                        $annotation[
                            'url'
                        ] ?? null;

                    if (! is_string($url)) {
                        continue;
                    }

                    $normalized =
                        $this->normalizeUrl(
                            $url
                        );

                    $urls[$normalized] =
                        $url;
                }
            }
        }

        return $urls;
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

        if ($withoutFragment !== null) {
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
        mixed $value
    ): string {
        if (
            is_string($value)
            && in_array(
                $value,
                [
                    'government',
                    'company_site',
                    'news',
                    'industry',
                    'other',
                ],
                true
            )
        ) {
            return $value;
        }

        return 'other';
    }

    private function nullableString(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value =
            trim(
                $value
            );

        return $value === ''
            ? null
            : $value;
    }
}
